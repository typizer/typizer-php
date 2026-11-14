<?php

declare(strict_types=1);

namespace Typizer\Http;

/**
 * HTTP-запрос, который {@see \Typizer\Client} передаёт транспорту.
 *
 * В нём есть заголовок `Authorization`, поэтому {@see __debugInfo()} (var_dump, print_r) скрывает
 * его значение.
 */
final class Request
{
    /**
     * @param array<string, string> $headers имя => значение
     * @param string|MultipartBody|null $body JSON-строка, multipart или null (без тела)
     * @param float $timeout секунды на весь запрос
     */
    public function __construct(
        public readonly string $method,
        public readonly string $url,
        public readonly array $headers,
        public readonly string|MultipartBody|null $body,
        public readonly float $timeout,
    ) {
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        $headers = [];
        foreach ($this->headers as $name => $value) {
            $headers[$name] = in_array(strtolower($name), ['authorization', 'cookie'], true) ? '***' : $value;
        }

        return [
            'method' => $this->method,
            'url' => $this->url,
            'headers' => $headers,
            'body' => $this->body instanceof MultipartBody ? 'multipart' : ($this->body === null ? null : 'json'),
            'timeout' => $this->timeout,
        ];
    }
}
