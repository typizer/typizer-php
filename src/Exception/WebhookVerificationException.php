<?php

declare(strict_types=1);

namespace Typizer\Exception;

/**
 * Подпись вебхука не прошла проверку ({@see \Typizer\Webhook::parse()}): нет заголовков, подпись не
 * совпала, метка времени старше 5 минут или тело — не JSON. Ответьте на такой запрос `400` и не
 * обрабатывайте его.
 */
final class WebhookVerificationException extends TypizerException
{
}
