<?php

declare(strict_types=1);

namespace Typizer\Exception;

/**
 * 5xx: `internal_error` (500) или `upstream_unavailable` (503). SDK сам повторяет такие запросы, если повтор безопасен (GET или запрос с `Idempotency-Key`).
 */
final class ServerException extends ApiException
{
}
