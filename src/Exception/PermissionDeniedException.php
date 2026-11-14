<?php

declare(strict_types=1);

namespace Typizer\Exception;

/**
 * 403 `forbidden` или `insufficient_scope`: у ключа нет нужного скоупа или доступа к ресурсу.
 */
final class PermissionDeniedException extends ApiException
{
}
