<?php

declare(strict_types=1);

namespace Typizer;

/**
 * Помощники для прогона. Прогон в PHP SDK — обычный массив в том виде, в каком его отдаёт API
 * (`extract()`, `getRun()`, `waitForRun()`, `$event['data']['object']` вебхука), поэтому
 * производные значения — статические функции над этим массивом, без запросов к сети.
 *
 * ```php
 * foreach (Typizer\Run::warnings($run) as $warning) {
 *     echo $warning['path'], ': ', $warning['message'] ?? $warning['reason'], PHP_EOL;
 * }
 * ```
 */
final class Run
{
    private function __construct()
    {
    }

    /**
     * Поля, не прошедшие проверку (`fields[*].status === 'warn'`), по порядку `fields` — то, что
     * стоит показать человеку. Каждое — `FieldWarning`:
     * - `path` — путь поля в нотации API (`contract_no`, `seller.inn`, `items[0].amount`);
     * - `message` — готовая фраза по-русски из `fields[path].message` или `null` (прогоны и
     *   вебхуки, отправленные до появления поля);
     * - `reason` — код (`pattern_mismatch`, `checksum_mismatch`, …) или `null`.
     *
     * Поля `missing` сюда не входят: у них нет значения, которое можно проверить. Прогон без
     * `fields` (не завершился, элемент списка, результат удалён по сроку) — пустой список.
     *
     * @param array<string, mixed> $run
     * @return list<array{path: string, message: ?string, reason: ?string}>
     */
    public static function warnings(array $run): array
    {
        $fields = $run['fields'] ?? null;
        if (!is_array($fields)) {
            return [];
        }
        $warnings = [];
        foreach ($fields as $path => $field) {
            if (!is_array($field) || ($field['status'] ?? null) !== 'warn') {
                continue;
            }
            $warnings[] = [
                'path' => (string) $path,
                'message' => is_string($field['message'] ?? null) ? $field['message'] : null,
                'reason' => is_string($field['reason'] ?? null) ? $field['reason'] : null,
            ];
        }

        return $warnings;
    }
}
