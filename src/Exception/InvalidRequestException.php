<?php

declare(strict_types=1);

namespace Typizer\Exception;

/**
 * Запрос неверен (400, 413, 415): `invalid_request`, `invalid_schema`, `file_too_large`, `unsupported_file_type`, `too_many_pages`, … Повтор того же запроса даст ту же ошибку — исправьте его.
 */
final class InvalidRequestException extends ApiException
{
}
