<?php

namespace Tuf\ComposerIntegration\Tests;

use Composer\IO\NullIO;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Prophecy\PhpUnit\ProphecyTrait;
use Psr\Http\Message\StreamInterface;
use Tuf\ComposerIntegration\Loader;
use Tuf\Exception\RepoFileNotFound;

/**
 * @covers \Tuf\ComposerIntegration\Loader
 */
class LoaderTest extends TestCase
{
    use ProphecyTrait;

    public function testLoader(): void
    {
        $responses = new MockHandler();

        $handlerStack = HandlerStack::create($responses);
        $client = new Client(['handler' => $handlerStack]);

        $loader = new Loader($client, new NullIO());

        $responses->append(new Response());
        $this->assertInstanceOf(StreamInterface::class, $loader->load('root.json', 128));

        // Any ClientException with a 404 error could should be converted
        // into a RepoFileNotFound exception.
        $responses->append(new Response(404));
        try {
            $loader->load('bogus.txt', 10);
            $this->fail('Expected a RepoFileNotFound exception, but none was thrown.');
        } catch (RepoFileNotFound $e) {
            $this->assertSame('bogus.txt not found', $e->getMessage());
        }

        // Any other ClientException should be wrapped in a
        // \RuntimeException.
        $responses->append(new Response(420));
        try {
            $loader->load('wtf.txt', 10);
            $this->fail('Expected a RuntimeException, but none was thrown.');
        } catch (\RuntimeException $e) {
            $this->assertSame(420, $e->getCode());
            $this->assertInstanceOf('Throwable', $e->getPrevious());
        }
    }
}
