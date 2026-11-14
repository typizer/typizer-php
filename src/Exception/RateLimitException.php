<?php

declare(strict_types=1);

namespace Typizer\Exception;

/**
 * 429 `rate_limited`: слишком много запросов. SDK сам повторяет запрос после `Retry-After`; это исключение — когда повторы кончились. Сколько ждать — в `$retryAfter`.
 */
final class RateLimitException extends ApiException
{
}
