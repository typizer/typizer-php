<?php

declare(strict_types=1);

namespace Typizer\Http;

/**
 * Ответ API: статус, заголовки (имена в нижнем регистре) и тело байтами.
 */
final class Response
{
    /** @var array<string, string> */
    public readonly array $headers;

    /**
     * @param array<string, string> $headers имена в любом регистре; хранятся в нижнем
     */
    public function __construct(
        public readonly int $status,
        array $headers,
        public readonly string $body,
    ) {
        $normalized = [];
        foreach ($headers as $name => $value) {
            $normalized[strtolower((string) $name)] = $value;
        }
        $this->headers = $normalized;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /** `X-Request-Id` ответа — приложите его к обращению в поддержку. */
    public function requestId(): ?string
    {
        return $this->header('x-request-id');
    }

    /** Тип содержимого без параметров, в нижнем регистре: `application/json`, `text/csv`, … */
    public function contentType(): string
    {
        $type = $this->header('content-type') ?? '';
        $semicolon = strpos($type, ';');

        return strtolower(trim($semicolon === false ? $type : substr($type, 0, $semicolon)));
    }

    public function isJson(): bool
    {
        $type = $this->contentType();

        return $type === 'application/json' || str_ends_with($type, '+json');
    }
}
