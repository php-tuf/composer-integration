<?php

namespace Tuf\ComposerIntegration;

use Composer\Downloader\TransportException;
use Composer\Repository\ComposerRepository;
use Composer\Repository\RepositorySecurityException;
use Composer\Util\Filesystem;
use Composer\Util\Http\Response;
use Composer\Util\HttpDownloader;
use GuzzleHttp\Promise\EachPromise;
use GuzzleHttp\Promise\Is;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\StreamInterface;
use Tuf\Client\DurableStorage\FileStorage;
use Tuf\Client\GuzzleFileFetcher;
use Tuf\Client\ResponseStream;
use Tuf\Client\Updater;
use Tuf\Exception\RepoFileNotFound;

/**
 * Provides a TUF-aware adapter for Composer's HTTP downloader.
 *
 * This class extends \Composer\Util\HttpDownloader in order to satisfy type
 * hints, but decorates an existing instance in order to preserve the state of
 * the HTTP downloader used by Composer's loop.
 */
class HttpDownloaderAdapter extends HttpDownloader
{
    private $decorated;

    private $instances = [];

    public $fetchers = [];

    /**
     * The base path where persistent TUF data should be stored.
     *
     * @var string
     *
     * @see \Tuf\ComposerIntegration\Plugin::getStoragePath()
     */
    private $storagePath;

    /**
     * A queue of promises to settle asynchronously.
     *
     * @var \ArrayIterator
     *
     * @see ::countActiveJobs()
     */
    private $queue;

    /**
     * An aggregated promise to settle the queued promises asynchronously.
     *
     * @var \GuzzleHttp\Promise\EachPromise
     *
     * @see ::countActiveJobs()
     */
    private $aggregator;

    private $activeJobs = 0;

    public function __construct(HttpDownloader $decorated, string $storagePath)
    {
        $this->decorated = $decorated;
        $this->storagePath = $storagePath;
        $this->queue = new \ArrayIterator();
        $this->aggregator = new EachPromise($this->queue, ['concurrency' => 12]);
    }

    public function register(ComposerRepository $repository)
    {
        $repoConfig = $repository->getRepoConfig();
        $url = $repoConfig['url'];

        // @todo: Write a custom implementation of FileStorage that stores repo keys to user's global composer cache?
        // Convert the repo URL into a string that can be used as a
        // directory name.
        $repoPath = implode(DIRECTORY_SEPARATOR, [
          $this->storagePath,
          preg_replace('/[^[:alnum:]\.]/', '-', $url),
        ]);
        // Ensure directory exists.
        $fs = new Filesystem();
        $fs->ensureDirectoryExists($repoPath);

        $rootFile = $repoPath . '/root.json';
        if (!file_exists($rootFile)) {
            $fs->copy(realpath($repoConfig['tuf']['root']), $rootFile);
        }

        // Instantiate TUF library.
        $this->fetchers[$url] = FileFetcher::createFromUri($url);
        $this->instances[$url] = new Updater($this->fetchers[$url], [], new FileStorage($repoPath));
    }

    /**
     * Creates a promise for a request.
     *
     * @param array $request
     *   The request array. Must contain at least a 'url' element with the URL.
     *
     * @return \GuzzleHttp\Promise\PromiseInterface
     *   A promise representing the eventual result of the request.
     */
    private function createPromise(array $request): PromiseInterface
    {
        $request += [
            'copyTo' => false,
        ];
        $request['options'] = array_replace_recursive($this->getOptions(), $request['options']);

        $fetcherOptions = [];
        if ($request['copyTo']) {
            $fetcherOptions[RequestOptions::SINK] = $request['copyTo'];
        }

        // When the promise is fulfilled, convert it to an instance of
        // \Composer\Util\Http\Response that resembles what the regular
        // HttpDownloader would produce.
        $accept = function (ResponseStream $stream) use ($request) {
            $this->markJobDone();

            $response = $stream->getResponse();
            $headers = [];
            foreach ($response->getHeaders() as $name => $values) {
                $headers[] = "$name: " . reset($values);
            }

            $uri = $stream->getMetadata('uri');
            if ($uri && file_exists($uri)) {
                $contents = "$uri~";
            } else {
                $contents = $stream->getContents();
            }
            return new Response($request, $response->getStatusCode(), $headers, $contents);
        };

        // If the promise gets rejected because it's a 404, convert that to a
        // \Composer\Downloader\TransportException like the regular
        // HttpDownloader would produce.
        $reject = function (\Throwable $e) use ($request) {
            $this->markJobDone();

            if ($e instanceof \InvalidArgumentException || $e instanceof RepoFileNotFound) {
                $e = new TransportException($e->getMessage(), $e->getCode(), $e);
                $e->setStatusCode(404);
            }
            throw $e;
        };

        $repository = $request['options']['tuf']['repository'];
        if (isset($request['options']['tuf']['target'])) {
            $target = $request['options']['tuf']['target'];
        } else {
            $target = parse_url($request['url'], PHP_URL_PATH);
            $target = ltrim($target, '/');
        }

        $this->activeJobs++;
        $tuf = $this->instances[$repository];
        return $tuf->download($target, $fetcherOptions)->then($accept, $reject);
    }

    /**
     * {@inheritDoc}
     */
    public function get($url, $options = array())
    {
        if (isset($options['tuf'])) {
            return $this->add($url, $options)->wait();
        } else {
            return $this->decorated->get($url, $options);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function add($url, $options = array())
    {
        if (isset($options['tuf'])) {
            return $this->createQueuedPromise([
              'url' => $url,
              'options' => $options,
            ]);
        } else {
            return $this->decorated->add($url, $options);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function copy($url, $to, $options = array())
    {
        if (isset($options['tuf'])) {
            return $this->addCopy($url, $to, $options)->wait();
        } else {
            return $this->decorated->copy($url, $to, $options);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function addCopy($url, $to, $options = array())
    {
        if (isset($options['tuf'])) {
            return $this->createQueuedPromise([
              'url' => $url,
              'options' => $options,
              'copyTo' => $to,
            ]);
        } else {
            return $this->decorated->addCopy($url, $to, $options);
        }
    }

    private function createQueuedPromise(array $request): PromiseInterface
    {
        $promise = $this->createPromise($request);
        $this->queue->append($promise);
        return $promise;
    }

    /**
     * {@inheritDoc}
     */
    public function getOptions()
    {
        return $this->decorated->getOptions();
    }

    /**
     * {@inheritDoc}
     */
    public function setOptions(array $options)
    {
        return $this->decorated->setOptions($options);
    }

    /**
     * {@inheritDoc}
     */
    public function markJobDone()
    {
        $this->activeJobs--;
        return $this->decorated->markJobDone();
    }

    /**
     * {@inheritDoc}
     */
    public function wait($index = null)
    {
        parent::wait($index);
        return $this->decorated->wait($index);
    }

    /**
     * {@inheritDoc}
     */
    public function enableAsync()
    {
        return $this->decorated->enableAsync();
    }

    /**
     * {@inheritDoc}
     */
    public function countActiveJobs($index = null)
    {
        $this->clearSettledPromises();
        $this->aggregator = new EachPromise($this->queue, ['concurrency' => 12]);
        $this->aggregator->promise()->wait();
        return $this->activeJobs + $this->decorated->countActiveJobs($index);
    }

    private function clearSettledPromises(): void
    {
        foreach ($this->queue as $key => $promise) {
            if (Is::settled($promise)) {
                unset($this->queue[$key]);
            }
        }
    }
}
