<?php

declare(strict_types=1);

namespace Typizer\Exception;

/**
 * Общий предок всех исключений SDK: `catch (TypizerException $e)` ловит любую ошибку Typizer —
 * ответ API с ошибкой, сетевую ошибку, истёкшее ожидание прогона и неверную подпись вебхука.
 */
class TypizerException extends \RuntimeException
{
}
