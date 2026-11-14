<?php

declare(strict_types=1);

namespace Typizer\Exception;

/**
 * {@see \Typizer\Client::waitForRun()} не дождался завершения прогона за отведённое время.
 *
 * Прогон при этом продолжает выполняться: его последнее состояние — в `$run`, дождитесь его
 * позже через `getRun()` или вебхук.
 */
final class WaitTimeoutException extends TypizerException
{
    /**
     * @param array<string, mixed> $run последнее полученное состояние прогона
     */
    public function __construct(string $message, public readonly array $run)
    {
        parent::__construct($message);
    }
}
