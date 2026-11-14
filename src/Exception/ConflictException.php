<?php

declare(strict_types=1);

namespace Typizer\Exception;

/**
 * 409 `conflict` или `idempotency_conflict`: состояние ресурса не позволяет операцию, или `Idempotency-Key` уже использован с другим запросом (либо первый запрос с ним ещё выполняется).
 */
final class ConflictException extends ApiException
{
}
