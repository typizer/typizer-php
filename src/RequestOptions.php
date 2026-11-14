<?php

declare(strict_types=1);

namespace Typizer;

use InvalidArgumentException;

/**
 * Параметры одного вызова — последний аргумент любого метода {@see Client}. Можно передать объектом
 * или массивом с ключами в snake_case:
 *
 * ```php
 * $typizer->createRun('tz_…', $body, options: ['idempotency_key' => $orderId . ':invoice']);
 * $typizer->getMe(new RequestOptions(organization: 'org_…'));
 * ```
 */
final class RequestOptions
{
    private const KEYS = ['idempotency_key', 'organization', 'timeout', 'max_retries', 'headers'];

    /**
     * @param string|null $idempotencyKey `Idempotency-Key`: 1–255 печатных ASCII-символов. Если не
     *                                    задан, SDK создаёт случайный ключ на вызов, чтобы его
     *                                    собственные повторы ничего не создали дважды
     * @param string|null $organization `Typizer-Organization` (`org_…`) для этого вызова
     * @param float|null $timeout секунды на запрос вместо таймаута клиента
     * @param int|null $maxRetries повторов при 429/5xx/сетевой ошибке вместо значения клиента
     * @param array<string, string> $headers дополнительные заголовки
     */
    public function __construct(
        public readonly ?string $idempotencyKey = null,
        public readonly ?string $organization = null,
        public readonly ?float $timeout = null,
        public readonly ?int $maxRetries = null,
        public readonly array $headers = [],
    ) {
        if ($idempotencyKey !== null && preg_match('/^[\x20-\x7E]{1,255}$/', $idempotencyKey) !== 1) {
            throw new InvalidArgumentException('Typizer: Idempotency-Key — от 1 до 255 печатных символов ASCII');
        }
        if ($maxRetries !== null && $maxRetries < 0) {
            throw new InvalidArgumentException('Typizer: max_retries не может быть отрицательным');
        }
    }

    /**
     * @param RequestOptions|array<string, mixed>|null $options
     */
    public static function from(self|array|null $options): self
    {
        if ($options instanceof self) {
            return $options;
        }
        if ($options === null || $options === []) {
            return new self();
        }
        $unknown = array_diff(array_keys($options), self::KEYS);
        if ($unknown !== []) {
            throw new InvalidArgumentException(
                'Typizer: неизвестные параметры вызова: ' . implode(', ', $unknown) . ' (допустимы: ' . implode(', ', self::KEYS) . ')',
            );
        }

        return new self(
            idempotencyKey: isset($options['idempotency_key']) ? (string) $options['idempotency_key'] : null,
            organization: isset($options['organization']) ? (string) $options['organization'] : null,
            timeout: isset($options['timeout']) ? (float) $options['timeout'] : null,
            maxRetries: isset($options['max_retries']) ? (int) $options['max_retries'] : null,
            headers: isset($options['headers']) && is_array($options['headers']) ? $options['headers'] : [],
        );
    }
}
