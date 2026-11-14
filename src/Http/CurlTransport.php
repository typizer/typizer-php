<?php

declare(strict_types=1);

namespace Typizer\Http;

use Typizer\Exception\NetworkException;

/**
 * Транспорт по умолчанию — ext-curl, без сторонних пакетов.
 *
 * Документ из multipart отправляется потоком из временного файла (не строкой в памяти). Свои
 * параметры cURL — например, `CURLOPT_CAINFO` со свежим набором корневых сертификатов на старом
 * сервере — передайте в `$curlOptions`. Прокси — `$proxy` (`http://user:pass@host:3128`,
 * `socks5://host:1080`); без него cURL сам берёт `https_proxy`/`HTTPS_PROXY` из окружения.
 */
final class CurlTransport implements Transport
{
    /**
     * @param float $connectTimeout секунды на установку соединения
     * @param array<int, mixed> $curlOptions дополнительные `CURLOPT_*`, применяются последними
     * @param string|null $proxy адрес прокси для всех запросов
     */
    public function __construct(
        private readonly float $connectTimeout = 10.0,
        private readonly array $curlOptions = [],
        #[\SensitiveParameter] private readonly ?string $proxy = null,
    ) {
        if (!extension_loaded('curl')) {
            throw new \LogicException('Typizer: расширение ext-curl не установлено — используйте Psr18Transport');
        }
    }

    public function send(Request $request): Response
    {
        $handle = curl_init();
        if ($handle === false) {
            throw new NetworkException('Typizer: не удалось инициализировать cURL', $request->method, $request->url);
        }

        $headers = [];
        $headerLines = [];
        foreach ($request->headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        $options = [
            CURLOPT_URL => $request->url,
            CURLOPT_CUSTOMREQUEST => $request->method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_ENCODING => '',
            CURLOPT_CONNECTTIMEOUT_MS => (int) ceil($this->connectTimeout * 1000),
            CURLOPT_TIMEOUT_MS => (int) ceil($request->timeout * 1000),
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$headers): int {
                $trimmed = trim($line);
                if (str_starts_with($trimmed, 'HTTP/')) {
                    $headers = []; // новый ответ (после 100 Continue) — начинаем заново
                } elseif ($trimmed !== '' && str_contains($trimmed, ':')) {
                    [$name, $value] = explode(':', $trimmed, 2);
                    $headers[strtolower(trim($name))] = trim($value);
                }

                return strlen($line);
            },
        ];

        if ($this->proxy !== null && $this->proxy !== '') {
            $options[CURLOPT_PROXY] = $this->proxy;
        }

        $body = $request->body;
        if ($body instanceof MultipartBody) {
            $stream = $body->stream();
            $headerLines[] = 'Content-Type: ' . $body->contentType();
            $headerLines[] = 'Content-Length: ' . $body->size();
            $headerLines[] = 'Expect:';
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_READFUNCTION] = static function ($handle, $unused, int $length) use ($stream): string {
                $chunk = fread($stream, $length);

                return $chunk === false ? '' : $chunk;
            };
        } elseif (is_string($body)) {
            $options[CURLOPT_POSTFIELDS] = $body;
            $headerLines[] = 'Expect:';
        }
        // CUSTOMREQUEST задаёт сам метод: CURLOPT_POST выше лишь включает отправку тела.
        $options[CURLOPT_HTTPHEADER] = $headerLines;

        curl_setopt_array($handle, $options);
        if ($this->curlOptions !== []) {
            curl_setopt_array($handle, $this->curlOptions);
        }

        $responseBody = curl_exec($handle);
        if ($responseBody === false) {
            $errno = curl_errno($handle);
            $error = curl_error($handle);
            throw new NetworkException(
                "Typizer: сетевая ошибка при {$request->method} " . self::path($request->url) . ": {$error} (cURL {$errno})",
                $request->method,
                $request->url,
                $errno === CURLE_OPERATION_TIMEDOUT,
            );
        }
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

        return new Response($status, $headers, is_string($responseBody) ? $responseBody : '');
    }

    private static function path(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);

        return is_string($path) ? $path : $url;
    }
}
