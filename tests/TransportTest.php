<?php

declare(strict_types=1);

namespace Typizer\Tests;

use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Psr7\HttpFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Typizer\Client;
use Typizer\Exception\NetworkException;
use Typizer\Exception\NotFoundException;
use Typizer\File;
use Typizer\Http\CurlTransport;
use Typizer\Http\MultipartBody;
use Typizer\Http\Psr18Transport;
use Typizer\Http\Request;
use Typizer\Http\Transport;
use Typizer\Tests\Support\MockTransport;
use Typizer\Tests\Support\Server;

/**
 * Both transports over real HTTP, against `php -S` (tests/Fixtures/router.php): what goes over the
 * wire — method, headers, JSON and multipart bodies, a document bigger than the in-memory part of
 * the temp stream — and how failures come back.
 */
final class TransportTest extends TestCase
{
    private static Server $server;

    public static function setUpBeforeClass(): void
    {
        self::$server = new Server();
    }

    public static function tearDownAfterClass(): void
    {
        self::$server->stop();
    }

    /** @return iterable<string, array{\Closure(): Transport}> */
    public static function transports(): iterable
    {
        yield 'curl' => [static fn (): Transport => new CurlTransport()];
        yield 'psr-18 (guzzle)' => [static function (): Transport {
            $factory = new HttpFactory();

            return new Psr18Transport(new Guzzle(['timeout' => 2]), $factory, $factory);
        }];
        yield 'psr-18 discovered' => [static fn (): Transport => Psr18Transport::discover()];
    }

    /** @return array<string, mixed> */
    private static function echoed(string $body): array
    {
        $echo = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        $echo['body'] = base64_decode($echo['body'], true);

        return $echo;
    }

    /** @param \Closure(): Transport $make */
    #[DataProvider('transports')]
    public function testGetWithHeaders(\Closure $make): void
    {
        $response = $make()->send(new Request('GET', self::$server->url . '/__echo/v1/me?x=1', [
            'Authorization' => 'Bearer tz_live_t',
            'Accept' => 'application/json',
        ], null, 5));

        self::assertSame(200, $response->status);
        self::assertTrue($response->isJson());
        self::assertSame('yes', $response->header('X-Echo'));
        self::assertSame('req_fake', $response->requestId());
        $echo = self::echoed($response->body);
        self::assertSame('GET', $echo['method']);
        self::assertSame('x=1', $echo['query']);
        self::assertSame('Bearer tz_live_t', $echo['headers']['authorization']);
        self::assertSame('', $echo['body']);
    }

    /** @param \Closure(): Transport $make */
    #[DataProvider('transports')]
    public function testJsonBodyAndMethod(\Closure $make): void
    {
        $response = $make()->send(new Request('PATCH', self::$server->url . '/__echo', [
            'Content-Type' => 'application/json',
        ], '{"name":"Счета"}', 5));
        $echo = self::echoed($response->body);
        self::assertSame('PATCH', $echo['method']);
        self::assertSame('{"name":"Счета"}', $echo['body']);
        self::assertSame('application/json', $echo['headers']['content-type']);
        self::assertArrayNotHasKey('expect', $echo['headers']);
    }

    /** @param \Closure(): Transport $make */
    #[DataProvider('transports')]
    public function testMultipartWithABigFileFromDisk(\Closure $make): void
    {
        $path = tempnam(sys_get_temp_dir(), 'big');
        $bytes = random_bytes(3 * 1024 * 1024 + 17); // past the 2 MiB in-memory part of php://temp
        file_put_contents($path, $bytes);
        $body = new MultipartBody([
            ['schema', '{"type":"object"}'],
            ['file', File::fromPath($path, 'Скан счёта.pdf')],
        ]);
        $transport = $make();

        // twice: a retry must resend the same bytes from the rewound stream
        foreach ([1, 2] as $attempt) {
            $response = $transport->send(new Request('POST', self::$server->url . '/__echo', [], $body, 10));
            $echo = self::echoed($response->body);
            self::assertSame('POST', $echo['method'], "attempt {$attempt}");
            self::assertSame((string) $body->size(), $echo['headers']['content-length']);
            $parts = MockTransport::parseMultipart($echo['body'], $echo['headers']['content-type']);
            self::assertSame(['schema', 'file'], array_column($parts, 'name'));
            self::assertSame('{"type":"object"}', $parts[0]['body']);
            self::assertSame('Скан счёта.pdf', $parts[1]['filename']);
            self::assertSame('application/pdf', $parts[1]['type']);
            self::assertSame(sha1($bytes), sha1($parts[1]['body']), "attempt {$attempt}: file bytes intact");
        }
        unlink($path);
    }

    /** @param \Closure(): Transport $make */
    #[DataProvider('transports')]
    public function testErrorStatusesAreResponsesNotExceptions(\Closure $make): void
    {
        $response = $make()->send(new Request('GET', self::$server->url . '/__echo?status=503', [], null, 5));
        self::assertSame(503, $response->status);
        self::assertSame('application/problem+json', $response->contentType());
    }

    /** @param \Closure(): Transport $make */
    #[DataProvider('transports')]
    public function testConnectionRefusedIsANetworkException(\Closure $make): void
    {
        $port = Server::freePort();
        try {
            $make()->send(new Request('GET', "http://127.0.0.1:{$port}/v1/me", ['Authorization' => 'Bearer tz_live_never_shown'], null, 2));
            self::fail('no exception');
        } catch (NetworkException $e) {
            self::assertSame('GET', $e->method);
            self::assertStringContainsString('/v1/me', $e->getMessage());
            self::assertStringNotContainsString('tz_live_never_shown', $e->getMessage());
        }
    }

    public function testCurlTimeout(): void
    {
        try {
            (new CurlTransport())->send(new Request('GET', self::$server->url . '/__sleep', [], null, 0.5));
            self::fail('no exception');
        } catch (NetworkException $e) {
            self::assertTrue($e->isTimeout);
        }
    }

    public function testCurlGoesThroughTheProxy(): void
    {
        // php -S as a plain HTTP proxy: curl sends the absolute URL to it, the echo shows the target host
        $transport = new CurlTransport(proxy: self::$server->url);
        $response = $transport->send(new Request('GET', 'http://api.typizer.invalid/__echo/v1/me', [], null, 5));
        self::assertSame(200, $response->status);
        $echo = self::echoed($response->body);
        self::assertSame('api.typizer.invalid', $echo['headers']['host']);
        self::assertSame('/__echo/v1/me', $echo['path']);
    }

    public function testClientEndToEndOverCurl(): void
    {
        self::$server->clear();
        $client = new Client('tz_live_e2e', self::$server->url, maxRetries: 0);
        $run = $client->extract(['file' => File::fromString('%PDF-1.4', 'a.pdf'), 'catalog' => 'upd@1'], wait: 5);
        self::assertSame('succeeded', $run['status']);
        self::assertSame('req_fake', $client->lastResponse()?->requestId());

        try {
            $client->getTypizer('tz_missing');
            self::fail('no exception');
        } catch (NotFoundException $e) {
            self::assertSame('req_fake', $e->requestId);
        }

        [$extract] = self::$server->requests();
        self::assertSame('/v1/extract', $extract['path']);
        self::assertSame('wait=5', $extract['query']);
        self::assertSame('Bearer tz_live_e2e', $extract['headers']['authorization']);
        self::assertNotEmpty($extract['headers']['idempotency-key']);
        $parts = MockTransport::parseMultipart($extract['body'], $extract['headers']['content-type']);
        self::assertSame(['file', 'catalog'], array_column($parts, 'name'));
    }
}
