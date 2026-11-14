<?php

declare(strict_types=1);

namespace Typizer\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Typizer\Tests\Support\MockTransport;
use Typizer\Tests\Support\Server;

/**
 * Every `<?php` example in README.md runs for real, as its own PHP process, against the fake API and
 * fake Bitrix24 portal in tests/Fixtures/router.php — the same way the Python SDK's README test
 * runs its examples. Examples that read `php://input` are request handlers: they are served by the
 * same `php -S` and called with a Bitrix24 event and a signed Typizer webhook.
 */
final class ReadmeTest extends TestCase
{
    private const KEY = 'tz_live_readme';

    private const SECRET_KEY = 'readme-webhook-secret';

    private static Server $server;

    private static string $dir;

    public static function setUpBeforeClass(): void
    {
        self::$dir = sys_get_temp_dir() . '/typizer-readme-' . bin2hex(random_bytes(4));
        mkdir(self::$dir);
        file_put_contents(self::$dir . '/invoice.pdf', "%PDF-1.4\n% readme invoice\n");
        file_put_contents(self::$dir . '/cacert.pem', '');
        foreach (self::blocks() as $name => [$code]) {
            file_put_contents(self::$dir . '/' . self::fileName($name, $code), self::prepare($code));
        }
        self::$server = new Server([
            'APP_DIR' => self::$dir,
            'TYPIZER_API_KEY' => self::KEY,
            'BITRIX24_APP_TOKEN' => 'bx-app-token',
            'TYPIZER_WEBHOOK_SECRET' => 'whsec_' . base64_encode(self::SECRET_KEY),
        ]);
        // Known only after start: the handlers reach the fake API and portal through the same server
        file_put_contents(self::$dir . '/env.php', '<?php putenv(' . var_export('TYPIZER_BASE_URL=' . self::$server->url, true) . '); putenv('
            . var_export('BITRIX24_WEBHOOK_URL=' . self::$server->url . '/rest/1/secret/', true) . ');');
    }

    public static function tearDownAfterClass(): void
    {
        self::$server->stop();
        array_map('unlink', glob(self::$dir . '/*') ?: []);
        rmdir(self::$dir);
    }

    /** @return array<string, array{string}> */
    public static function blocks(): array
    {
        $readme = (string) file_get_contents(__DIR__ . '/../README.md');
        preg_match_all('/```php\n(.*?)```/s', $readme, $matches);
        $blocks = [];
        foreach ($matches[1] as $i => $code) {
            if (str_starts_with($code, '<?php')) {
                $blocks["php block {$i}"] = [$code];
            }
        }

        return $blocks;
    }

    private static function fileName(string $name, string $code): string
    {
        if (preg_match('#^// ([\w.-]+\.php)#m', $code, $m) === 1) {
            return $m[1];
        }

        return str_replace(' ', '-', $name) . '.php';
    }

    /** The README's `require __DIR__ . '/vendor/autoload.php'` points at this package's autoloader. */
    private static function prepare(string $code): string
    {
        $autoload = var_export(realpath(__DIR__ . '/../vendor/autoload.php'), true);
        $count = 0;
        $code = str_replace(
            "require __DIR__ . '/vendor/autoload.php';",
            "require {$autoload}; require __DIR__ . '/env.php';",
            $code,
            $count,
        );
        if ($count !== 1) {
            throw new \RuntimeException('every README example starts with the vendor/autoload.php require');
        }

        return $code;
    }

    public function testThereAreExamplesAndHandlers(): void
    {
        $blocks = self::blocks();
        self::assertGreaterThanOrEqual(7, count($blocks));
        $handlers = array_filter($blocks, static fn (array $b) => str_contains($b[0], 'php://input') || str_contains($b[0], '$_POST'));
        self::assertCount(2, $handlers, 'the Bitrix24 handler and the Typizer webhook handler');
    }

    #[DataProvider('blocks')]
    public function testScriptExampleRuns(string $code): void
    {
        if (str_contains($code, 'php://input') || str_contains($code, '$_POST')) {
            self::assertTrue(true, 'handlers are run by the Bitrix24 test below');

            return;
        }
        self::$server->clear();
        $file = self::$dir . '/' . self::fileName((string) $this->dataName(), $code);
        $process = proc_open(
            [PHP_BINARY, $file],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            self::$dir,
            ['TYPIZER_API_KEY' => self::KEY, 'PATH' => (string) getenv('PATH')],
        );
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        $exit = proc_close($process);

        self::assertSame(0, $exit, "README example failed:\n{$stdout}\n{$stderr}");
        self::assertSame('', $stderr);
        self::assertDoesNotMatchRegularExpression('/(Warning|Notice|Deprecated|Fatal error):/', (string) $stdout);
        $requests = array_filter(self::$server->requests(), static fn (array $r) => str_starts_with($r['path'], '/v1/'));
        self::assertNotEmpty($requests, 'the example made no API call');
        foreach ($requests as $request) {
            self::assertSame('Bearer ' . self::KEY, $request['headers']['authorization']);
        }
        if (str_contains($code, 'InsufficientBalanceException $e')) {
            self::assertStringContainsString('Пополните баланс', (string) $stdout);
        }
        self::assertStringNotContainsString(self::KEY, (string) $stdout);
    }

    /** The docs' /reference/sdks PHP snippet (apps/docs/snippets/sdk/get_me.php), monorepo only. */
    public function testDocsSnippetRuns(): void
    {
        $snippet = __DIR__ . '/../../../apps/docs/snippets/sdk/get_me.php';
        if (!is_file($snippet)) {
            self::markTestSkipped('the docs snippet is only in the monorepo');
        }
        self::$server->clear();
        $file = self::$dir . '/docs-get-me.php';
        file_put_contents($file, self::prepare((string) file_get_contents($snippet)));
        $process = proc_open([PHP_BINARY, $file], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, self::$dir, [
            'TYPIZER_API_KEY' => self::KEY,
        ]);
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        self::assertSame(0, proc_close($process), "{$stdout}\n{$stderr}");
        self::assertSame("ООО Тест\n", $stdout);
        [$request] = self::$server->requests();
        self::assertSame('/v1/me', $request['path']);
        self::assertSame('Bearer ' . self::KEY, $request['headers']['authorization']);
    }

    public function testBitrix24DealFlow(): void
    {
        self::$server->clear();

        // 1. A forged Bitrix24 event is refused without touching the portal or the API
        $forged = self::post('/app/bitrix24-deal.php', http_build_query([
            'event' => 'ONCRMDEALUPDATE',
            'data' => ['FIELDS' => ['ID' => '42']],
            'auth' => ['application_token' => 'wrong'],
        ]), ['Content-Type: application/x-www-form-urlencoded']);
        self::assertSame(403, $forged['status']);
        self::assertCount(1, self::$server->requests());

        // 2. The real event: deal -> file -> Typizer run with webhook_url and metadata
        self::$server->clear();
        $event = self::post('/app/bitrix24-deal.php', http_build_query([
            'event' => 'ONCRMDEALUPDATE',
            'data' => ['FIELDS' => ['ID' => '42']],
            'ts' => '1700000000',
            'auth' => ['domain' => 'example.bitrix24.ru', 'application_token' => 'bx-app-token'],
        ]), ['Content-Type: application/x-www-form-urlencoded']);
        self::assertSame(200, $event['status'], $event['body']);

        $paths = array_column(self::$server->requests(), 'path');
        self::assertSame(['/app/bitrix24-deal.php', '/rest/1/secret/crm.item.get.json', '/files/invoice.pdf', '/v1/extract'], $paths);
        $requests = self::$server->requests();
        parse_str($requests[1]['body'], $get);
        self::assertSame(['entityTypeId' => '2', 'id' => '42'], $get);
        $extract = $requests[3];
        self::assertSame('wait=0', $extract['query']);
        self::assertSame('bitrix24-deal-42-file-777', $extract['headers']['idempotency-key']);
        $parts = MockTransport::parseMultipart($extract['body'], $extract['headers']['content-type']);
        $fields = array_column($parts, 'body', 'name');
        self::assertSame("%PDF-1.4\n% fake invoice\n", $fields['file']);
        self::assertSame('schet-na-oplatu@1', $fields['catalog']);
        self::assertSame('{"bitrix_deal_id":"42"}', $fields['metadata']);
        self::assertSame('https://your-server.example/typizer-webhook.php', $fields['webhook_url']);

        // 3. Typizer's webhook: the signature is checked, the result lands in the deal
        self::$server->clear();
        $body = json_encode([
            'id' => 'evt_1',
            'type' => 'run.succeeded',
            'created_at' => '2026-11-13T00:00:00.000Z',
            'data' => ['object' => ['id' => 'run_1', 'object' => 'run', 'status' => 'succeeded', 'metadata' => ['bitrix_deal_id' => '42'], 'output' => ['number' => 'СЧ-17', 'total' => 1180.5]]],
        ], JSON_UNESCAPED_UNICODE);
        $ts = (string) time();
        $signature = base64_encode(hash_hmac('sha256', "evt_1.{$ts}.{$body}", self::SECRET_KEY, true));
        $headers = ['Content-Type: application/json', 'Webhook-Id: evt_1', "Webhook-Timestamp: {$ts}"];

        $bad = self::post('/app/typizer-webhook.php', $body, [...$headers, 'Webhook-Signature: v1,' . base64_encode(str_repeat('x', 32))]);
        self::assertSame(400, $bad['status']);
        self::assertCount(1, self::$server->requests(), 'nothing written for a bad signature');

        $good = self::post('/app/typizer-webhook.php', $body, [...$headers, "Webhook-Signature: v1,{$signature}"]);
        self::assertSame(200, $good['status'], $good['body']);
        $update = self::$server->requests()[2];
        self::assertSame('/rest/1/secret/crm.item.update.json', $update['path']);
        parse_str($update['body'], $fields);
        self::assertSame(['entityTypeId' => '2', 'id' => '42', 'fields' => ['ufCrm_1700000001' => 'СЧ-17', 'opportunity' => '1180.5']], $fields);
    }

    /**
     * @param list<string> $headers
     * @return array{status: int, body: string}
     */
    private static function post(string $path, string $body, array $headers): array
    {
        $handle = curl_init(self::$server->url . $path);
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
        ]);
        $response = (string) curl_exec($handle);

        return ['status' => (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE), 'body' => $response];
    }
}
