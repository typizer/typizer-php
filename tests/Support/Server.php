<?php

declare(strict_types=1);

namespace Typizer\Tests\Support;

/**
 * A `php -S` server running tests/Fixtures/router.php on a free port: the fake Typizer API, a fake
 * Bitrix24 portal, a raw-request echo and the README's webhook handlers, all in one router. Four
 * workers, so a handler can call back into the same server (the README's Bitrix24 flow does).
 */
final class Server
{
    /** @var resource */
    private $process;

    public readonly string $url;

    public readonly string $log;

    /**
     * @param array<string, string> $env
     */
    public function __construct(array $env = [])
    {
        $port = self::freePort();
        $this->url = "http://127.0.0.1:{$port}";
        $log = tempnam(sys_get_temp_dir(), 'typizer-fake-');
        if ($log === false) {
            throw new \RuntimeException('tempnam failed');
        }
        $this->log = $log;

        $environment = array_merge(getenv(), $env, [
            'PHP_CLI_SERVER_WORKERS' => '4',
            'FAKE_LOG' => $this->log,
            'FAKE_URL' => $this->url,
        ]);
        $process = proc_open(
            [PHP_BINARY, '-d', 'enable_post_data_reading=0', '-d', 'variables_order=EGPCS', '-S', "127.0.0.1:{$port}", __DIR__ . '/../Fixtures/router.php'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            null,
            $environment,
        );
        if (!is_resource($process)) {
            throw new \RuntimeException('cannot start php -S');
        }
        $this->process = $process;

        $deadline = microtime(true) + 10;
        while (microtime(true) < $deadline) {
            $socket = @stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $error, 0.2);
            if ($socket !== false) {
                fclose($socket);

                return;
            }
            usleep(50_000);
        }
        throw new \RuntimeException("php -S did not start on port {$port}");
    }

    public function stop(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
        @unlink($this->log);
    }

    /**
     * Requests the router has seen, in order.
     *
     * @return list<array{method: string, path: string, query: string, headers: array<string, string>, body: string}>
     */
    public function requests(): array
    {
        $lines = file($this->log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

        return array_map(static function (string $line): array {
            $request = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            $request['body'] = base64_decode($request['body'], true);

            return $request;
        }, $lines);
    }

    public function clear(): void
    {
        file_put_contents($this->log, '');
    }

    public static function freePort(): int
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if ($server === false) {
            throw new \RuntimeException("no free port: {$error}");
        }
        $name = stream_socket_get_name($server, false);
        fclose($server);

        return (int) substr((string) $name, strrpos((string) $name, ':') + 1);
    }
}
