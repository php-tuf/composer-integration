<?php

namespace Tuf\ComposerIntegration;

use Composer\Downloader\TransportException;
use Composer\Package\PackageInterface;
use Composer\Repository\ComposerRepository;
use Composer\Repository\RepositorySecurityException;
use Composer\Util\Filesystem;
use Composer\Util\Http\Response;
use Composer\Util\HttpDownloader;
use GuzzleHttp\Promise\EachPromise;
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
 * hints, but decorates an existing instance in order to preserve as much state
 * as possible.
 *
 * By "TUF-aware", I mean this class knows about all instantiated TUF
 * repositories, and knows to delegate certain HTTP requests to TUF, which will
 * transparently do whatever downloading and verification is needed. The
 * expected flow is that a TUF-aware Composer repository will call this class'
 * ::register() method, which will create a TUF repository object corresponding
 * to that Composer repository. Then later on, individual packages can associate
 * a TUF target key with an arbitrary URL by calling ::setPackageUrl().
 */
class HttpDownloaderAdapter extends HttpDownloader
{
    /**
     * The decorated HTTP downloader.
     *
     * @var \Composer\Util\HttpDownloader
     */
    private $decorated;

    /**
     * The instantiated TUF repositories, keyed by URL.
     *
     * @var Updater[]
     */
    private $instances = [];

    /**
     * The instantiated TUF file fetchers, keyed by repository URL.
     *
     * @var \Tuf\Client\RepoFileFetcherInterface[]
     */
    private $fetchers = [];

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
     * @var \GuzzleHttp\Promise\PromiseInterface[]
     *
     * @see ::countActiveJobs()
     */
    private $queue = [];

    /**
     * The number of pending promises.
     *
     * @var int
     */
    private $activeJobs = 0;

    /**
     * HttpDownloaderAdapter constructor.
     *
     * @param \Composer\Util\HttpDownloader $decorated
     *   The decorated HTTP downloader.
     * @param string $storagePath
     *   The path where TUF data should be persisted.
     */
    public function __construct(HttpDownloader $decorated, string $storagePath)
    {
        $this->decorated = $decorated;
        $this->storagePath = $storagePath;
    }

    public function getDecorated(): HttpDownloader
    {
        return $this->decorated;
    }

    public function addRepository(ComposerRepository $repository)
    {
        $config = $repository->getRepoConfig();
        $url = $config['url'];

        // @todo: Write a custom implementation of FileStorage that stores repo keys to user's global composer cache?
        // Use the repository URL to derive a path where we can persist the TUF
        // data.
        $repoPath = implode(DIRECTORY_SEPARATOR, [
          $this->storagePath,
          preg_replace('/[^[:alnum:]\.]/', '-', $url),
        ]);

        $fs = new Filesystem();
        $fs->ensureDirectoryExists($repoPath);

        // We expect the repository to have a root metadata file in a known
        // good state. Copy that file to our persistent storage location if
        // it doesn't already exist.
        $rootFile = $repoPath . '/root.json';
        if (!file_exists($rootFile)) {
            $repoConfig = $repository->getRepoConfig();
            $fs->copy(realpath($repoConfig['tuf']['root']), $rootFile);
        }

        $this->fetchers[$url] = new UrlMapDecorator(GuzzleFileFetcher::createFromUri($url));
        $this->instances[$url] = new Updater($this->fetchers[$url], [], new FileStorage($repoPath));
    }

    /**
     * Associates a TUF-validated package with a specific URL.
     *
     * It is assumed that the package's transport options include an indexed
     * array of information needed by TUF. In order:
     * - The URL of the repository.
     * - The target key, as known to TUF.
     *
     * @param \Composer\Package\PackageInterface $package
     *   The package object.
     * @param string $url
     *   The URL from which the package should be downloaded.
     *
     * @see \Tuf\ComposerIntegration\PackageLoader::loadPackages()
     */
    public function setPackageUrl(PackageInterface $package, string $url): void
    {
        $options = $package->getTransportOptions();
        list ($repository, $target) = $options['tuf'];
        $this->fetchers[$repository][$target] = $url;
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

        list ($repository) = $request['options']['tuf'];
        if (isset($request['options']['tuf'][1])) {
            $target = $request['options']['tuf'][1];
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
            return $this->getDecorated()->get($url, $options);
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
            return $this->getDecorated()->add($url, $options);
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
            return $this->getDecorated()->copy($url, $to, $options);
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
            return $this->getDecorated()->addCopy($url, $to, $options);
        }
    }

    private function createQueuedPromise(array $request): PromiseInterface
    {
        array_push($this->queue, $this->createPromise($request));
        return end($this->queue);
    }

    /**
     * {@inheritDoc}
     */
    public function getOptions()
    {
        return $this->getDecorated()->getOptions();
    }

    /**
     * {@inheritDoc}
     */
    public function setOptions(array $options)
    {
        return $this->getDecorated()->setOptions($options);
    }

    /**
     * {@inheritDoc}
     */
    public function markJobDone()
    {
        $this->activeJobs--;
        return $this->getDecorated()->markJobDone();
    }

    /**
     * {@inheritDoc}
     */
    public function wait($index = null)
    {
        parent::wait($index);
        return $this->getDecorated()->wait($index);
    }

    /**
     * {@inheritDoc}
     */
    public function enableAsync()
    {
        return $this->getDecorated()->enableAsync();
    }

    /**
     * {@inheritDoc}
     */
    public function countActiveJobs($index = null)
    {
        $this->queue = array_filter($this->queue, '\GuzzleHttp\Promise\Is::pending');
        $aggregate = new EachPromise($this->queue, ['concurrency' => 12]);
        $aggregate->promise()->wait();
        return $this->activeJobs + $this->getDecorated()->countActiveJobs($index);
    }
}
