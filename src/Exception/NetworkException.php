<?php

declare(strict_types=1);

namespace Typizer\Exception;

/**
 * Ответа от API нет совсем: DNS, соединение, TLS или истёк таймаут запроса.
 *
 * Запрос мог дойти до сервера. Повторяйте его с тем же `Idempotency-Key` — второй раз ничего не
 * создастся и не спишется (SDK делает это сам, пока не кончатся `maxRetries`).
 */
final class NetworkException extends TypizerException
{
    /** Сетевую ошибку повторять можно — с тем же `Idempotency-Key`. */
    public readonly bool $retryable;

    public function __construct(
        string $message,
        public readonly string $method,
        public readonly string $url,
        public readonly bool $isTimeout = false,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
        $this->retryable = true;
    }
}
