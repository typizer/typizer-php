<?php

declare(strict_types=1);

namespace Typizer\Exception;

use Typizer\Http\Response;

/**
 * API ответил ошибкой (`application/problem+json`, RFC 9457).
 *
 * ```php
 * try {
 *     $run = $typizer->extract([...]);
 * } catch (ApiException $e) {
 *     echo $e->errorCode;   // insufficient_balance, rate_limited, invalid_schema, …
 *     echo $e->httpStatus;  // 402
 *     echo $e->requestId;   // req_… — приложите к обращению в поддержку
 *     echo $e->docUrl;      // https://docs.typizer.ru/errors/<code>
 * }
 * ```
 *
 * Конкретный класс зависит от статуса: {@see InvalidRequestException} (400, 413, 415),
 * {@see AuthenticationException} (401), {@see InsufficientBalanceException} (402),
 * {@see PermissionDeniedException} (403), {@see NotFoundException} (404), {@see ConflictException}
 * (409), {@see DocumentException} (422), {@see RateLimitException} (429), {@see ServerException} (5xx).
 * `$retryable` подсказывает, поможет ли повтор (SDK сам уже повторил, сколько разрешает `maxRetries`).
 */
class ApiException extends TypizerException
{
    /**
     * @param string $errorCode машиночитаемый код (`invalid_request`, `rate_limited`, …); `unknown`,
     *                          если ответ — не problem+json (например, страница ошибки прокси)
     * @param array<string, mixed> $problem тело ошибки целиком
     * @param int|null $retryAfter секунды из `Retry-After` (429, 503)
     */
    public function __construct(
        string $message,
        public readonly int $httpStatus,
        public readonly string $errorCode,
        public readonly ?string $requestId,
        public readonly ?string $detail,
        public readonly ?string $param,
        public readonly ?string $docUrl,
        public readonly ?int $retryAfter,
        public readonly array $problem,
    ) {
        parent::__construct($message, $httpStatus);
        $this->retryable = in_array($httpStatus, [429, 500, 502, 503, 504], true)
            || in_array($errorCode, ['rate_limited', 'upstream_unavailable', 'internal_error'], true);
    }

    /**
     * Есть ли смысл повторить тот же запрос позже: `rate_limited` (после `Retry-After`),
     * `upstream_unavailable`, `internal_error` и другие 5xx. Остальные ошибки от повтора не исчезнут.
     */
    public readonly bool $retryable;

    /**
     * Исключение нужного класса для ответа с ошибкой.
     *
     * @internal
     */
    public static function fromResponse(Response $response): self
    {
        $problem = [];
        if ($response->body !== '') {
            try {
                $decoded = json_decode($response->body, true, 64, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    $problem = $decoded;
                }
            } catch (\JsonException) {
                // не JSON — например, HTML страницы ошибки прокси; ниже будет код `unknown`
            }
        }

        $string = static fn (string $key): ?string => isset($problem[$key]) && is_scalar($problem[$key]) ? (string) $problem[$key] : null;
        $code = $string('code') ?? 'unknown';
        $detail = $string('detail') ?? $string('title');
        $requestId = $string('request_id') ?? $response->requestId();
        $retryAfter = self::parseRetryAfter($response->header('retry-after'));

        $message = "Typizer API {$response->status} {$code}";
        if ($detail !== null && $detail !== '') {
            $message .= ': ' . $detail;
        }
        if ($string('param') !== null) {
            $message .= ' (param: ' . $string('param') . ')';
        }
        if ($requestId !== null) {
            $message .= " [request_id: {$requestId}]";
        }

        $class = match (true) {
            $response->status === 401 => AuthenticationException::class,
            $response->status === 402 => InsufficientBalanceException::class,
            $response->status === 403 => PermissionDeniedException::class,
            $response->status === 404 => NotFoundException::class,
            $response->status === 409 => ConflictException::class,
            $response->status === 422 => DocumentException::class,
            $response->status === 429 => RateLimitException::class,
            in_array($response->status, [400, 408, 413, 415], true) => InvalidRequestException::class,
            $response->status >= 500 => ServerException::class,
            default => self::class,
        };

        return new $class(
            $message,
            $response->status,
            $code,
            $requestId,
            $detail,
            $string('param'),
            $string('doc_url'),
            $retryAfter,
            $problem,
        );
    }

    /** `Retry-After` в секундах: число секунд или HTTP-дата (RFC 9110). */
    public static function parseRetryAfter(?string $value): ?int
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $value = trim($value);
        if (ctype_digit($value)) {
            return (int) $value;
        }
        $time = strtotime($value);

        return $time === false ? null : max(0, $time - time());
    }
}
