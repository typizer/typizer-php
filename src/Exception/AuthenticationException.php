<?php

declare(strict_types=1);

namespace Typizer\Exception;

/**
 * 401 `unauthorized`: ключ API не передан, неверен или отозван.
 */
final class AuthenticationException extends ApiException
{
}
