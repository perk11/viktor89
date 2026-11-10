<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Amp\Future;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Perk11\Viktor89\Util\BackgroundFiber;
use Perk11\Viktor89\Util\FiberYieldingCurlHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FiberYieldingCurlHandler::class)]
#[CoversClass(BackgroundFiber::class)]
class FiberYieldingCurlHandlerTest extends TestCase
{
    private const string ROUTER = <<<'PHP'
        <?php
        usleep((int) ($_GET['ms'] ?? 0) * 1000);
        http_response_code((int) ($_GET['status'] ?? 200));
        echo $_GET['body'] ?? '';
        PHP;

    private static string $dir;
    /** @var resource|null */
    private static $server = null;
    private static string $baseUrl;

    public static function setUpBeforeClass(): void
    {
        self::$dir = sys_get_temp_dir() . '/viktor89-fiber-curl-' . bin2hex(random_bytes(4));
        mkdir(self::$dir);
        file_put_contents(self::$dir . '/router.php', self::ROUTER);
        $port = self::freePort();
        self::$baseUrl = "http://127.0.0.1:$port";
        // The built-in server handles one request at a time unless it has workers.
        self::$server = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:$port", self::$dir . '/router.php'],
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            null,
            ['PHP_CLI_SERVER_WORKERS' => '4'],
        );
        $deadline = microtime(true) + 10;
        while (@fsockopen('127.0.0.1', $port) === false) {
            if (microtime(true) > $deadline) {
                self::fail('PHP built-in server did not start');
            }
            usleep(50000);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$server !== null) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }
        @unlink(self::$dir . '/router.php');
        @rmdir(self::$dir);
    }

    public function testRequestsInBackgroundFibersRunConcurrently(): void
    {
        $client = new Client(['base_uri' => self::$baseUrl, 'handler' => FiberYieldingCurlHandler::createStack()]);

        $startedAt = microtime(true);
        $bodies = Future\await([
            BackgroundFiber::start(static fn () => (string) $client->get('/?ms=500&body=first')->getBody()),
            BackgroundFiber::start(static fn () => (string) $client->post('/?ms=500&body=second', ['json' => ['a' => 1]])->getBody()),
        ]);

        $this->assertSame(['first', 'second'], $bodies);
        $this->assertLessThan(0.9, microtime(true) - $startedAt);
    }

    public function testHttpErrorsAreStillThrown(): void
    {
        $client = new Client(['base_uri' => self::$baseUrl, 'handler' => FiberYieldingCurlHandler::createStack()]);

        $this->expectException(ServerException::class);
        BackgroundFiber::start(static fn () => $client->get('/?status=500'))->await();
    }

    public function testConnectionErrorsAreStillThrown(): void
    {
        $client = new Client(['handler' => FiberYieldingCurlHandler::createStack(), 'connect_timeout' => 2]);

        $this->expectException(ConnectException::class);
        BackgroundFiber::start(static fn () => $client->get('http://127.0.0.1:' . self::freePort() . '/'))->await();
    }

    public function testRequestsOutsideBackgroundFibersUseTheDefaultHandler(): void
    {
        $default = new MockHandler([new Response(200, [], 'main'), new Response(200, [], 'plain fiber'), new Response(200, [], 'streamed')]);
        $client = new Client(['handler' => HandlerStack::create(new FiberYieldingCurlHandler($default))]);

        $this->assertSame('main', (string) $client->get('http://example.invalid/')->getBody());
        $this->assertSame('plain fiber', \Amp\async(static fn () => (string) $client->get('http://example.invalid/')->getBody())->await());
        $this->assertSame('streamed', BackgroundFiber::start(
            static fn () => (string) $client->get('http://example.invalid/', ['stream' => true])->getBody(),
        )->await());
        $this->assertSame(0, $default->count());
    }

    public function testOnlyFibersStartedAsBackgroundFibersAreMarked(): void
    {
        $this->assertFalse(BackgroundFiber::isCurrent());
        $this->assertTrue(BackgroundFiber::start(static fn () => BackgroundFiber::isCurrent())->await());
        $this->assertFalse(\Amp\async(static fn () => BackgroundFiber::isCurrent())->await());

        $nestedFiber = new \Fiber(static fn () => BackgroundFiber::isCurrent());
        $this->assertFalse(BackgroundFiber::start(static function () use ($nestedFiber): bool {
            $nestedFiber->start();

            return $nestedFiber->getReturn();
        })->await());
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);

        return $port;
    }
}
