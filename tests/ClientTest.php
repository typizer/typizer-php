<?php

declare(strict_types=1);

namespace Typizer\Tests;

use PHPUnit\Framework\TestCase;
use Typizer\Client;
use Typizer\Exception\ApiException;
use Typizer\Exception\AuthenticationException;
use Typizer\Exception\ConflictException;
use Typizer\Exception\DocumentException;
use Typizer\Exception\InsufficientBalanceException;
use Typizer\Exception\InvalidRequestException;
use Typizer\Exception\NetworkException;
use Typizer\Exception\NotFoundException;
use Typizer\Exception\PermissionDeniedException;
use Typizer\Exception\RateLimitException;
use Typizer\Exception\ServerException;
use Typizer\Exception\TypizerException;
use Typizer\Exception\WaitTimeoutException;
use Typizer\File;
use Typizer\Http\Response;
use Typizer\RequestOptions;
use Typizer\Tests\Support\MockTransport;

final class ClientTest extends TestCase
{
    private const KEY = 'tz_live_SuperSecretKey1234567890';

    private MockTransport $transport;

    /** @var list<float> */
    private array $sleeps = [];

    protected function setUp(): void
    {
        $this->transport = new MockTransport();
        $this->sleeps = [];
    }

    private function client(int $maxRetries = 2, ?string $organization = null): Client
    {
        return new Client(
            self::KEY,
            'https://api.test/',
            maxRetries: $maxRetries,
            organization: $organization,
            transport: $this->transport,
            sleep: function (float $seconds): void {
                $this->sleeps[] = $seconds;
            },
        );
    }

    /** @return array<string, mixed> */
    private static function runFixture(string $status, array $extra = []): array
    {
        return $extra + ['id' => 'run_1', 'object' => 'run', 'status' => $status, 'output' => null];
    }

    public function testSendsAuthAcceptAndUserAgent(): void
    {
        $this->transport->push(MockTransport::json(200, ['object' => 'me']));
        $me = $this->client()->getMe();

        self::assertSame(['object' => 'me'], $me);
        $request = $this->transport->last();
        self::assertSame('GET', $request->method);
        self::assertSame('https://api.test/v1/me', $request->url);
        self::assertSame('Bearer ' . self::KEY, $request->headers['Authorization']);
        self::assertSame('application/json', $request->headers['Accept']);
        self::assertMatchesRegularExpression('#^typizer-php/\d+\.\d+\.\d+ PHP/8\.\d+$#', $request->headers['User-Agent']);
        self::assertArrayNotHasKey('Idempotency-Key', $request->headers);
        self::assertNull($request->body);
    }

    public function testExtractSendsJsonWithWaitAndAnAutomaticIdempotencyKey(): void
    {
        $this->transport->push(MockTransport::json(200, self::runFixture('succeeded')));
        $schema = ['type' => 'object', 'properties' => ['total' => ['type' => 'number']]];
        $this->client()->extract(['url' => 'https://typizer.ru/samples/invoice.pdf', 'schema' => $schema, 'metadata' => ['ид' => 'заказ/1']], wait: 60);

        $request = $this->transport->last();
        self::assertSame('POST', $request->method);
        self::assertSame('https://api.test/v1/extract?wait=60', $request->url);
        self::assertSame('application/json', $request->headers['Content-Type']);
        self::assertSame(
            '{"url":"https://typizer.ru/samples/invoice.pdf","schema":{"type":"object","properties":{"total":{"type":"number"}}},"metadata":{"ид":"заказ/1"}}',
            $request->body,
        );
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $request->headers['Idempotency-Key']);
        self::assertSame(90.0, $request->timeout, 'the default timeout already covers wait + 15');
    }

    public function testWaitExtendsTheTimeout(): void
    {
        $client = new Client(self::KEY, 'https://api.test', timeout: 10, transport: $this->transport);
        $client->extract(['url' => 'https://x.test/a.pdf'], wait: 60);
        self::assertSame(75.0, $this->transport->last()->timeout);
        $client->getMe(['timeout' => 3]);
        self::assertSame(3.0, $this->transport->last()->timeout);
    }

    public function testEmptyBodyIsAJsonObjectAndFloatsKeepTheirFraction(): void
    {
        $this->client()->installCatalogEntry('upd');
        self::assertSame('{}', $this->transport->last()->body);
        $this->client()->updateTypizer('tz_1', ['schema' => ['type' => 'object', 'properties' => ['x' => ['minimum' => 1.0]]]]);
        self::assertStringContainsString('"minimum":1.0', (string) $this->transport->last()->body);
        $this->client()->extract(['url' => 'https://x.test/a.pdf', 'metadata' => [], 'schema' => ['type' => 'object', 'properties' => [], 'required' => []]]);
        self::assertSame(
            '{"url":"https://x.test/a.pdf","metadata":{},"schema":{"type":"object","properties":{},"required":[]}}',
            $this->transport->last()->body,
            'empty dictionaries are objects, empty lists stay lists',
        );
    }

    public function testCallerIdempotencyKeyOrganizationAndHeaders(): void
    {
        $client = $this->client(organization: 'org_default');
        $client->createRun('tz_1', ['url' => 'https://x.test/a.pdf'], options: ['idempotency_key' => 'order-42', 'headers' => ['X-Trace' => 't1']]);
        $request = $this->transport->last();
        self::assertSame('order-42', $request->headers['Idempotency-Key']);
        self::assertSame('org_default', $request->headers['Typizer-Organization']);
        self::assertSame('t1', $request->headers['X-Trace']);

        $client->getMe(new RequestOptions(organization: 'org_other'));
        self::assertSame('org_other', $this->transport->last()->headers['Typizer-Organization']);
    }

    public function testCallerHeadersCannotReplaceAuthorization(): void
    {
        $client = $this->client();
        $client->getMe(['headers' => ['authorization' => 'Bearer other', 'Authorization' => 'Bearer other']]);
        self::assertSame(['Authorization' => 'Bearer ' . self::KEY], array_filter(
            $this->transport->last()->headers,
            static fn (string $name): bool => strtolower($name) === 'authorization',
            ARRAY_FILTER_USE_KEY,
        ));
        self::assertSame(200, $client->lastResponse()?->status);
    }

    public function testInvalidOptionsAreRejected(): void
    {
        try {
            $this->client()->getMe(['idempotencyKey' => 'x']);
            self::fail('unknown option accepted');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('idempotencyKey', $e->getMessage());
        }
        $this->expectException(\InvalidArgumentException::class);
        new RequestOptions(idempotencyKey: "кириллица");
    }

    public function testPathParametersAreEncodedAndQueryIsValidated(): void
    {
        $client = $this->client();
        $client->getCommunityCatalogEntry('ivan petrov', 'счёт', ['version' => '1.2.0']);
        self::assertSame(
            'https://api.test/v1/catalog/ivan%20petrov/' . rawurlencode('счёт') . '?version=1.2.0',
            $this->transport->last()->url,
        );

        $client->listRuns(['limit' => 5, 'status' => 'failed', 'created_after' => new \DateTimeImmutable('2026-11-01T00:00:00+00:00'), 'batch_id' => null]);
        self::assertSame('https://api.test/v1/runs?limit=5&status=failed&created_after=2026-11-01T00%3A00%3A00%2B00%3A00', $this->transport->last()->url);

        $client->listCatalog(['favorites' => true]);
        self::assertStringEndsWith('?favorites=true', $this->transport->last()->url);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('statuss');
        $client->listRuns(['statuss' => 'failed']);
    }

    public function testExtractWithAFileIsMultipart(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'inv');
        file_put_contents($path, "%PDF-1.4\nbytes");
        $this->client()->extract([
            'file' => File::fromPath($path, 'Счёт "№1".pdf'),
            'schema' => ['type' => 'object', 'properties' => []],
            'metadata' => ['order_id' => '12345'],
            'webhook_url' => null,
            'mode' => 'precise',
        ], wait: 30);
        unlink($path);

        $request = $this->transport->last();
        self::assertSame('POST', $request->method);
        self::assertArrayNotHasKey('Content-Type', $request->headers, 'the transport sets the multipart content type');
        $parts = MockTransport::parseMultipart((string) $this->transport->bodies[0], $request->body->contentType());
        self::assertSame(['file', 'schema', 'metadata', 'mode'], array_column($parts, 'name'));
        self::assertSame('Счёт %22№1%22.pdf', $parts[0]['filename']);
        self::assertSame('application/pdf', $parts[0]['type']);
        self::assertSame("%PDF-1.4\nbytes", $parts[0]['body']);
        self::assertSame('{"type":"object","properties":{}}', $parts[1]['body'], 'an empty properties array stays an object');
        self::assertSame('{"order_id":"12345"}', $parts[2]['body']);
        self::assertSame('precise', $parts[3]['body']);
    }

    public function testBatchWithSeveralFilesRepeatsThePart(): void
    {
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, 'PNGDATA');
        rewind($stream);
        $this->client()->createBatch([
            'typizer_id' => 'tz_1',
            'files' => [File::fromString('one', 'a.pdf'), File::fromStream($stream, 'b.png'), File::fromString('zip', 'docs.zip')],
        ]);
        $request = $this->transport->last();
        $parts = MockTransport::parseMultipart((string) $this->transport->bodies[0], $request->body->contentType());
        self::assertSame(['typizer_id', 'files', 'files', 'files'], array_column($parts, 'name'));
        self::assertSame(['a.pdf', 'b.png', 'docs.zip'], array_slice(array_column($parts, 'filename'), 1));
        self::assertSame(['one', 'PNGDATA', 'zip'], array_slice(array_column($parts, 'body'), 1));
        self::assertSame('image/png', $parts[2]['type']);
        self::assertSame('application/zip', $parts[3]['type']);
    }

    public function testFilesAreRefusedWhereTheOperationTakesJsonOnly(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('createFile');
        $this->client()->createTypizer(['name' => 'x', 'file' => File::fromString('x', 'x.pdf')]);
    }

    public function testRateLimitIsRetriedAfterRetryAfterWithTheSameRequest(): void
    {
        $this->transport->push(
            MockTransport::problem(429, 'rate_limited', ['Retry-After' => '3']),
            MockTransport::json(200, self::runFixture('queued')),
        );
        $file = File::fromString('pdf-bytes', 'a.pdf');
        $run = $this->client()->extract(['file' => $file]);

        self::assertSame('queued', $run['status']);
        self::assertSame([3.0], $this->sleeps);
        self::assertCount(2, $this->transport->requests);
        [$first, $second] = $this->transport->requests;
        self::assertSame($first->headers['Idempotency-Key'], $second->headers['Idempotency-Key'], 'a retry reuses the key');
        self::assertSame($this->transport->bodies[0], $this->transport->bodies[1], 'a retry resends the same multipart bytes');
    }

    public function testRetriesStopAfterMaxRetries(): void
    {
        $this->transport->push(
            MockTransport::problem(429, 'rate_limited', ['Retry-After' => '1']),
            MockTransport::problem(429, 'rate_limited', ['Retry-After' => '1']),
            MockTransport::problem(429, 'rate_limited', ['Retry-After' => '2']),
        );
        try {
            $this->client()->getBalance();
            self::fail('no exception');
        } catch (RateLimitException $e) {
            self::assertSame(429, $e->httpStatus);
            self::assertSame('rate_limited', $e->errorCode);
            self::assertSame(2, $e->retryAfter);
            self::assertTrue($e->retryable);
            self::assertSame('req_rate_limited', $e->requestId);
        }
        self::assertCount(3, $this->transport->requests);
        self::assertSame([1.0, 1.0], $this->sleeps);
    }

    public function testALongRetryAfterIsNotWaitedOut(): void
    {
        $this->transport->push(MockTransport::problem(429, 'rate_limited', ['Retry-After' => '3600']));
        $this->expectException(RateLimitException::class);
        try {
            $this->client()->getBalance();
        } finally {
            self::assertSame([], $this->sleeps);
        }
    }

    public function testServerErrorsAreRetriedOnlyWhenSafe(): void
    {
        // POST with an Idempotency-Key: retried with backoff
        $this->transport->push(MockTransport::problem(503, 'upstream_unavailable'), MockTransport::json(201, ['id' => 'tz_1']));
        self::assertSame(['id' => 'tz_1'], $this->client()->createTypizer(['name' => 'x']));
        self::assertCount(1, $this->sleeps);
        self::assertGreaterThan(0, $this->sleeps[0]);

        // POST that ignores Idempotency-Key (generateTypizer): not retried
        $this->transport->push(MockTransport::problem(503, 'upstream_unavailable'));
        try {
            $this->client()->generateTypizer(['description' => 'счёт']);
            self::fail('no exception');
        } catch (ServerException $e) {
            self::assertSame('upstream_unavailable', $e->errorCode);
        }
        self::assertCount(3, $this->transport->requests);

        // PATCH without a key: not retried either
        $this->transport->push(MockTransport::problem(500, 'internal_error'));
        $this->expectException(ServerException::class);
        $this->client()->updateTypizer('tz_1', ['name' => 'y']);
    }

    public function testNetworkErrorsAreRetriedWhenSafe(): void
    {
        $error = new NetworkException('Typizer: сетевая ошибка при GET /v1/runs/run_1: timed out', 'GET', 'https://api.test/v1/runs/run_1', true);
        $this->transport->push($error, MockTransport::json(200, self::runFixture('succeeded')));
        self::assertSame('succeeded', $this->client()->getRun('run_1')['status']);

        $this->transport->push($error, $error, $error);
        try {
            $this->client()->getRun('run_1');
            self::fail('no exception');
        } catch (NetworkException $e) {
            self::assertTrue($e->isTimeout);
            self::assertTrue($e->retryable);
        }

        $this->transport->push($error);
        $this->expectException(NetworkException::class);
        $this->client()->generateTypizer(['description' => 'x']); // no retry: not idempotent
    }

    /** @return iterable<string, array{int, string, class-string}> */
    public static function errors(): iterable
    {
        yield 'invalid_request' => [400, 'invalid_request', InvalidRequestException::class];
        yield 'file_too_large' => [413, 'file_too_large', InvalidRequestException::class];
        yield 'unsupported_file_type' => [415, 'unsupported_file_type', InvalidRequestException::class];
        yield 'unauthorized' => [401, 'unauthorized', AuthenticationException::class];
        yield 'insufficient_balance' => [402, 'insufficient_balance', InsufficientBalanceException::class];
        yield 'insufficient_scope' => [403, 'insufficient_scope', PermissionDeniedException::class];
        yield 'not_found' => [404, 'not_found', NotFoundException::class];
        yield 'idempotency_conflict' => [409, 'idempotency_conflict', ConflictException::class];
        yield 'document_unreadable' => [422, 'document_unreadable', DocumentException::class];
        yield 'internal_error' => [500, 'internal_error', ServerException::class];
    }

    /** @param class-string $class */
    #[\PHPUnit\Framework\Attributes\DataProvider('errors')]
    public function testErrorsBecomeTypedExceptions(int $status, string $code, string $class): void
    {
        $this->transport->push(...array_fill(0, 3, MockTransport::problem($status, $code, ['X-Request-Id' => 'req_header'])));
        try {
            $this->client()->generateTypizer(['description' => 'x']);
            self::fail('no exception');
        } catch (ApiException $e) {
            self::assertInstanceOf($class, $e);
            self::assertInstanceOf(TypizerException::class, $e);
            self::assertSame($status, $e->httpStatus);
            self::assertSame($status, $e->getCode());
            self::assertSame($code, $e->errorCode);
            self::assertSame('req_' . $code, $e->requestId, 'request_id from the problem body');
            self::assertSame("detail of {$code}", $e->detail);
            self::assertSame("https://docs.typizer.ru/errors/{$code}", $e->docUrl);
            self::assertSame($code, $e->problem['code']);
            self::assertSame(in_array($code, ['internal_error'], true), $e->retryable, 'retryable');
            self::assertStringContainsString("{$status} {$code}: detail of {$code}", $e->getMessage());
            self::assertStringContainsString('req_' . $code, $e->getMessage());
        }
    }

    public function testANonJsonErrorStillCarriesStatusAndRequestId(): void
    {
        $this->transport->push(new Response(502, ['Content-Type' => 'text/html', 'X-Request-Id' => 'req_proxy'], '<html>Bad gateway</html>'));
        try {
            $this->client(maxRetries: 0)->getMe();
            self::fail('no exception');
        } catch (ServerException $e) {
            self::assertSame(502, $e->httpStatus);
            self::assertSame('unknown', $e->errorCode);
            self::assertSame('req_proxy', $e->requestId);
        }
    }

    public function testDownloadFilenameIsABareNameNeverAPath(): void
    {
        $cases = [
            "attachment; filename*=UTF-8''..%2F..%2Fetc%2Fpasswd" => 'passwd',
            'attachment; filename="..\\..\\boot.ini"' => 'boot.ini',
            'attachment; filename="/var/www/index.php"' => 'index.php',
            "attachment; filename*=UTF-8''..%2F" => null,
            'attachment; filename=".."' => null,
            "attachment; filename*=UTF-8''a%00b%0A.xml" => 'ab.xml',
            "attachment; filename*=UTF-8''%D1%81%D1%87%D1%91%D1%82.xml" => 'счёт.xml',
        ];
        foreach ($cases as $header => $expected) {
            self::assertSame($expected, \Typizer\Download::filenameFrom($header), $header);
        }
    }

    public function testDownloadsReturnBytesTypeAndFilename(): void
    {
        $this->transport->push(new Response(200, [
            'Content-Type' => 'application/xml; charset=utf-8',
            'Content-Disposition' => "attachment; filename=\"run.xml\"; filename*=UTF-8''%D1%81%D1%87%D1%91%D1%82.xml",
        ], '<x/>'));
        $download = $this->client()->getRunOutput('run_1', 'xml');
        self::assertSame('<x/>', $download->body);
        self::assertSame('application/xml', $download->contentType);
        self::assertSame('счёт.xml', $download->filename);
        self::assertSame('https://api.test/v1/runs/run_1/output?format=xml', $this->transport->last()->url);
        self::assertSame('*/*', $this->transport->last()->headers['Accept']);

        $path = tempnam(sys_get_temp_dir(), 'dl');
        self::assertSame(4, $download->saveTo($path));
        self::assertSame('<x/>', file_get_contents($path));
        unlink($path);

        $this->transport->push(new Response(200, ['Content-Type' => 'application/json'], '{"total":1}'));
        self::assertSame(['total' => 1], $this->client()->getRunOutput('run_1')->json());
    }

    public function testWaitForRunPollsUntilTheRunFinishes(): void
    {
        $this->transport->push(
            MockTransport::json(200, self::runFixture('processing')),
            MockTransport::json(200, self::runFixture('succeeded', ['output' => ['total' => 1]])),
        );
        $run = $this->client()->waitForRun(self::runFixture('queued'), pollInterval: 1.0);
        self::assertSame('succeeded', $run['status']);
        self::assertSame(['total' => 1], $run['output']);
        self::assertSame([1.0, 1.5], $this->sleeps);
        self::assertSame('https://api.test/v1/runs/run_1', $this->transport->last()->url);
    }

    public function testWaitForRunReturnsAFinishedRunWithoutARequest(): void
    {
        $failed = self::runFixture('failed', ['error' => ['code' => 'document_unreadable']]);
        self::assertSame($failed, $this->client()->waitForRun($failed));
        self::assertSame([], $this->transport->requests);

        $this->transport->push(MockTransport::json(200, self::runFixture('succeeded')));
        self::assertSame('succeeded', $this->client()->waitForRun('run_1')['status']);
    }

    public function testWaitForRunTimesOut(): void
    {
        $this->transport->push(...array_fill(0, 50, MockTransport::json(200, self::runFixture('processing'))));
        try {
            $this->client()->waitForRun('run_1', timeout: 20, pollInterval: 2);
            self::fail('no exception');
        } catch (WaitTimeoutException $e) {
            self::assertSame('processing', $e->run['status']);
            self::assertStringContainsString('run_1', $e->getMessage());
        }
        self::assertEqualsWithDelta(20.0, array_sum($this->sleeps), 0.001);
    }

    public function testTheKeyNeverLeaks(): void
    {
        $client = $this->client(maxRetries: 0);
        ob_start();
        var_dump($client);
        print_r($client);
        echo json_encode($client);
        $dumped = (string) ob_get_clean();
        self::assertStringNotContainsString(self::KEY, $dumped);
        self::assertStringContainsString('tz_live_…', $dumped);

        ob_start();
        var_dump($this->transportRequestAfter($client));
        self::assertStringNotContainsString(self::KEY, (string) ob_get_clean());

        $this->transport->push(MockTransport::problem(401, 'unauthorized'));
        try {
            $client->getMe();
            self::fail('no exception');
        } catch (AuthenticationException $e) {
            self::assertStringNotContainsString(self::KEY, $e->getMessage());
            self::assertStringNotContainsString(self::KEY, (string) $e);
        }

        $this->expectException(\LogicException::class);
        serialize($client);
    }

    private function transportRequestAfter(Client $client): object
    {
        $client->getMe();

        return $this->transport->last();
    }

    public function testKeyAndBaseUrlFromTheEnvironment(): void
    {
        putenv('TYPIZER_API_KEY=tz_live_from_env');
        putenv('TYPIZER_BASE_URL=https://staging.test');
        try {
            $client = new Client(transport: $this->transport);
            $client->getMe();
            self::assertSame('https://staging.test/v1/me', $this->transport->last()->url);
            self::assertSame('Bearer tz_live_from_env', $this->transport->last()->headers['Authorization']);
        } finally {
            putenv('TYPIZER_API_KEY');
            putenv('TYPIZER_BASE_URL');
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('TYPIZER_API_KEY');
        new Client(transport: $this->transport);
    }

    public function testProxyBelongsToTheDefaultTransport(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Client(self::KEY, proxy: 'http://proxy.test:3128', transport: $this->transport);
    }

    public function testNoProblemBodyMeansAnEmptyArray(): void
    {
        $this->transport->push(new Response(200, ['Content-Type' => 'application/json'], ''));
        self::assertSame([], $this->client()->deleteFile('file_1'));
    }
}
