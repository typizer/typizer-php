<?php

declare(strict_types=1);

namespace Typizer;

use Typizer\Exception\WebhookVerificationException;

/**
 * Проверка подписи вебхуков Typizer (Standard Webhooks: HMAC-SHA256 от
 * `{webhook-id}.{webhook-timestamp}.{тело}` на ключе из секрета конечной точки `whsec_…`).
 *
 * ```php
 * $event = Webhook::parse(getenv('TYPIZER_WEBHOOK_SECRET'), getallheaders(), file_get_contents('php://input'));
 * if ($event['type'] === 'run.succeeded') {
 *     $run = $event['data']['object'];
 * }
 * ```
 *
 * Тело передавайте ровно в тех байтах, в которых оно пришло (`php://input`), а не пересобранным
 * `json_encode()` — любая перестановка ключей или пробел ломают подпись.
 */
final class Webhook
{
    /** Допуск по времени — 5 минут, как у Standard Webhooks: защита от повтора перехваченного запроса. */
    public const TOLERANCE_SECONDS = 300;

    /**
     * Проверяет подпись и метку времени. Никогда не бросает исключение: нет заголовка, неверная
     * подпись, просроченная метка — всё даёт `false`.
     *
     * @param array<mixed, mixed> $headers заголовки в любом регистре имён, значение — строка или
     *                                     массив строк (как отдают `getallheaders()` и
     *                                     `$request->headers->all()` в Laravel/Symfony)
     * @param int|null $now текущее время Unix в секундах; по умолчанию `time()`
     */
    public static function verify(
        #[\SensitiveParameter] string $secret,
        array $headers,
        string $rawBody,
        ?int $now = null,
    ): bool {
        $h = self::normalizeHeaders($headers);
        $id = $h['webhook-id'] ?? '';
        $timestamp = $h['webhook-timestamp'] ?? '';
        $signatures = $h['webhook-signature'] ?? '';
        if ($id === '' || $timestamp === '' || $signatures === '') {
            return false;
        }
        if (preg_match('/^\d{1,15}$/', $timestamp) !== 1) {
            return false;
        }
        $timestampSeconds = (int) $timestamp;
        if (abs(($now ?? time()) - $timestampSeconds) > self::TOLERANCE_SECONDS) {
            return false;
        }

        $encoded = str_starts_with($secret, 'whsec_') ? substr($secret, 6) : $secret;
        $key = base64_decode($encoded, true);
        if ($key === false || $key === '') {
            return false;
        }
        $expected = hash_hmac('sha256', "{$id}.{$timestampSeconds}.{$rawBody}", $key, true);

        // Подписей может быть несколько через пробел: годится любая. Сравниваем каждую в постоянное
        // время и не выходим из цикла досрочно.
        $ok = false;
        foreach (explode(' ', $signatures) as $entry) {
            $comma = strpos($entry, ',');
            if ($comma === false || substr($entry, 0, $comma) !== 'v1') {
                continue;
            }
            $given = base64_decode(substr($entry, $comma + 1), true);
            if ($given !== false && strlen($given) === strlen($expected) && hash_equals($expected, $given)) {
                $ok = true;
            }
        }

        return $ok;
    }

    /**
     * Проверяет подпись и возвращает событие: `['id' => 'evt_…', 'type' => 'run.succeeded',
     * 'created_at' => '…', 'data' => ['object' => [...]]]`.
     *
     * @param array<mixed, mixed> $headers
     * @return array<string, mixed>
     *
     * @throws WebhookVerificationException подпись не прошла проверку или тело — не JSON-объект
     */
    public static function parse(
        #[\SensitiveParameter] string $secret,
        array $headers,
        string $rawBody,
        ?int $now = null,
    ): array {
        if (!self::verify($secret, $headers, $rawBody, $now)) {
            throw new WebhookVerificationException('Typizer: подпись вебхука не прошла проверку');
        }
        try {
            $event = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new WebhookVerificationException('Typizer: тело вебхука — не JSON');
        }
        if (!is_array($event) || !isset($event['type'])) {
            throw new WebhookVerificationException('Typizer: тело вебхука — не событие Typizer');
        }

        return $event;
    }

    /**
     * Заголовки текущего запроса PHP: `getallheaders()`, а где её нет (старый php-fpm) — из `$_SERVER`.
     *
     * @return array<string, string>
     */
    public static function requestHeaders(): array
    {
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            if (is_array($headers)) {
                return $headers;
            }
        }
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (is_string($key) && str_starts_with($key, 'HTTP_') && is_string($value)) {
                $headers[str_replace('_', '-', strtolower(substr($key, 5)))] = $value;
            }
        }

        return $headers;
    }

    /**
     * @param array<mixed, mixed> $headers
     * @return array<string, string>
     */
    private static function normalizeHeaders(array $headers): array
    {
        $normalized = [];
        foreach ($headers as $name => $value) {
            if (!is_string($name)) {
                continue;
            }
            if (is_array($value)) {
                $value = reset($value);
            }
            if (is_string($value)) {
                $normalized[strtolower($name)] = $value;
            }
        }

        return $normalized;
    }
}
