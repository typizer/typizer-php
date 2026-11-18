<?php

declare(strict_types=1);

namespace Typizer;

use InvalidArgumentException;

/**
 * Свои проверки полей — `$client->validators`. Проверку подключают к полю схемы через
 * `x-typizer-check`: ссылкой (`"my/contract-no@1"`) или объектом прямо в схеме — такой объект SDK
 * передаёт как есть и сам не проверяет.
 *
 * ```php
 * $result = $typizer->validators->test(['ДГ-2026/0153', 'ДГ-26/153'], ref: 'my/contract-no@1');
 * foreach ($typizer->validators->list() as $validator) {
 *     echo $validator['ref'], ' ', $validator['version']['version'], PHP_EOL;
 * }
 * ```
 *
 * Ответы — массивы в том виде, в каком их отдаёт API, как у остальных методов {@see Client};
 * `spec` — свободный объект JSON (массив PHP), виды правил пополняются без смены версии API.
 * Каждый метод — обёртка над операцией {@see Client} (`listValidators`, `getValidator`, …) с
 * именованными аргументами; необязательные аргументы, которые вы не задали, в запрос не попадают.
 */
final class Validators
{
    /** @internal создаётся клиентом: `$client->validators` */
    public function __construct(private readonly Client $client)
    {
    }

    /**
     * Все свои проверки организации, новые первыми: генератор сам запрашивает следующие страницы
     * (`GET /v1/validators?starting_after=…`), пока вы читаете. Одна страница — {@see Client::listValidators()}.
     *
     * @param int|null $limit размер страницы, 1–100 (по умолчанию 20)
     * @param string|null $startingAfter начать после этой проверки (`val_…`)
     * @param RequestOptions|array<string, mixed>|null $options
     * @return \Generator<int, array<string, mixed>> проверки (`Validator`)
     */
    public function list(?int $limit = null, ?string $startingAfter = null, RequestOptions|array|null $options = null): \Generator
    {
        $cursor = $startingAfter;
        do {
            $page = $this->client->listValidators(['limit' => $limit, 'starting_after' => $cursor], $options);
            $data = is_array($page['data'] ?? null) ? $page['data'] : [];
            foreach ($data as $validator) {
                yield $validator;
            }
            $next = $page['next_cursor'] ?? null;
            if (!is_string($next) || $next === '') {
                $last = $data === [] ? null : end($data);
                $next = is_array($last) && is_string($last['id'] ?? null) ? $last['id'] : null;
            }
            $more = ($page['has_more'] ?? false) === true && $next !== null && $next !== $cursor;
            $cursor = $next;
        } while ($more);
    }

    /**
     * Проверка со всеми версиями (`versions`, новые первыми) и типизаторами, которые на неё
     * ссылаются (`used_by`).
     *
     * @param string $ref `val_…` или ссылка `my/<slug>` — версия в ссылке (`my/contract-no@1`)
     *                    отбрасывается: ответ несёт все версии
     * @param RequestOptions|array<string, mixed>|null $options
     * @return array<string, mixed> `ValidatorDetail`
     */
    public function get(string $ref, RequestOptions|array|null $options = null): array
    {
        if (str_starts_with($ref, 'my/')) {
            $slug = substr($ref, 3);
            $at = strpos($slug, '@');
            if ($at !== false) {
                $slug = substr($slug, 0, $at);
            }

            return $this->client->getValidatorBySlug($slug, $options);
        }

        return $this->client->getValidator($ref, $options);
    }

    /**
     * Версии проверки, новые первыми — поле `versions` из {@see get()}.
     *
     * @param string $ref `val_…` или `my/<slug>`
     * @param RequestOptions|array<string, mixed>|null $options
     * @return list<array<string, mixed>> `ValidatorVersion`
     */
    public function versions(string $ref, RequestOptions|array|null $options = null): array
    {
        $versions = $this->get($ref, $options)['versions'] ?? [];

        return is_array($versions) ? array_values($versions) : [];
    }

    /**
     * Своя копия проверки из каталога — `my/<slug>` с её правилом и опубликованными примерами,
     * которую можно менять. Проверки каталога устанавливать не нужно: поле ссылается на них
     * (`'x-typizer-check' => 'iban@1'`); копия нужна, только чтобы поменять правило.
     *
     * @param string $ref официальная (`inn`, `inn@1`), `cat_…` или `автор/slug[@версия]`
     * @param string|null $slug ссылка `my/<slug>`; по умолчанию — slug проверки каталога. Занятый — `ConflictException`
     * @param string|null $name название; по умолчанию — название проверки каталога
     * @param RequestOptions|array<string, mixed>|null $options
     * @return array<string, mixed> `Validator`
     */
    public function fork(
        string $ref,
        ?string $slug = null,
        ?string $name = null,
        RequestOptions|array|null $options = null,
    ): array {
        [$entry, $version] = array_pad(explode('@', $ref, 2), 2, null);
        if (str_contains($entry, '/')) {
            [$publisher, $entrySlug] = explode('/', $entry, 2);
            $found = $this->client->getCommunityCatalogEntry($publisher, $entrySlug, ['type' => 'validator'], $options);
            $entry = (string) $found['id'];
        }

        return $this->client->forkCatalogEntry($entry, self::body([
            'version' => $version,
            'slug' => $slug,
            'name' => $name,
        ]), 'validator', $options);
    }

    /**
     * Создать свою проверку `my/<slug>` версии `1.0.0`. Красный тест — `InvalidRequestException`
     * с `param` `tests/<n>`; занятый `slug` — `ConflictException`.
     *
     * @param string $name название, 1–80 символов («Номер договора»)
     * @param array<string, mixed> $spec объект проверки (`kind`, `applies_to`, `rules`, `message`, …) как есть
     * @param list<array<string, mixed>>|null $tests `[['value' => '…', 'expect' => 'pass'|'fail'], …]`
     * @param string|null $slug ссылка `my/<slug>`; по умолчанию — из названия
     * @param RequestOptions|array<string, mixed>|null $options
     * @return array<string, mixed> `Validator`
     */
    public function create(
        string $name,
        array $spec,
        ?array $tests = null,
        ?string $slug = null,
        ?string $description = null,
        ?string $changelog = null,
        RequestOptions|array|null $options = null,
    ): array {
        return $this->client->createValidator(self::body([
            'name' => $name,
            'slug' => $slug,
            'description' => $description,
            'spec' => $spec,
            'tests' => $tests,
            'changelog' => $changelog,
        ]), $options);
    }

    /**
     * Изменить проверку. Новую версию API выбирает сам: `bump` в ответе — `major` (изменился тип
     * значения или удалён тест), `minor` (правила или новые тесты), `patch` (название, описание)
     * или `none`; `propagated_to` — типизаторы, получившие её сразу, `suggested_to` — те, кому
     * major-версию нужно подключить явно.
     *
     * @param string $id `val_…`
     * @param array<string, mixed>|null $spec
     * @param list<array<string, mixed>>|null $tests весь список тестов новой версии
     * @param RequestOptions|array<string, mixed>|null $options
     * @return array<string, mixed> `ValidatorUpdateResult`
     */
    public function update(
        string $id,
        ?string $name = null,
        ?array $spec = null,
        ?array $tests = null,
        ?string $description = null,
        ?string $changelog = null,
        RequestOptions|array|null $options = null,
    ): array {
        return $this->client->updateValidator($id, self::body([
            'name' => $name,
            'description' => $description,
            'spec' => $spec,
            'tests' => $tests,
            'changelog' => $changelog,
        ]), $options);
    }

    /**
     * Отправить проверку в архив (`DELETE /v1/validators/{id}`): её версии остаются у прогонов и
     * прежних версий типизаторов. Пока на неё ссылается типизатор — `ConflictException` с `param`
     * `used_by`.
     *
     * @param string $id `val_…`
     * @param RequestOptions|array<string, mixed>|null $options
     * @return array<string, mixed> `['id' => 'val_…', 'object' => 'validator', 'deleted' => true]`
     */
    public function archive(string $id, RequestOptions|array|null $options = null): array
    {
        return $this->client->deleteValidator($id, $options);
    }

    /**
     * Прогнать значения через проверку без документа и без списания страниц: по каждому значению
     * `pass`, `reason`, `message`, `rule_index`; `explain` — что проверка проверяет, словами.
     *
     * @param list<string|int|float> $values от 1 до 100 строк или чисел
     * @param string|null $ref ссылка: `my/contract-no@1`, `inn@1`, `val_…`
     * @param array<string, mixed>|null $spec объект проверки — вместо `ref`, чтобы проверить черновик
     * @param RequestOptions|array<string, mixed>|null $options
     * @return array<string, mixed> `ValidatorTestResult`
     *
     * @throws InvalidArgumentException до запроса: заданы оба `ref` и `spec` или ни одного,
     *                                  `values` — не список из 1–100 строк или чисел
     */
    public function test(array $values, ?string $ref = null, ?array $spec = null, RequestOptions|array|null $options = null): array
    {
        if (($ref === null) === ($spec === null)) {
            throw new InvalidArgumentException('Typizer: validators->test(): передайте ровно один из ref и spec');
        }
        if ($values === [] || count($values) > 100 || !array_is_list($values)) {
            throw new InvalidArgumentException('Typizer: validators->test(): values — список из 1–100 значений');
        }
        foreach ($values as $value) {
            if (!is_string($value) && !is_int($value) && !is_float($value)) {
                throw new InvalidArgumentException('Typizer: validators->test(): каждое значение — строка или число');
            }
        }

        return $this->client->testValidator(self::body(['ref' => $ref, 'spec' => $spec, 'values' => $values]), $options);
    }

    /**
     * Черновик проверки по описанию словами и примерам: `spec`, `tests` (`expect`/`got`),
     * `converged` (все примеры сошлись), `explain`, `source` и подходящие готовые проверки в
     * `candidates_from_catalog`. Расходует агентский лимит организации, как `generateTypizer`.
     * Ни описания, ни примеров, которые должны пройти, — `InvalidRequestException`.
     *
     * @param array{pass?: list<string|int|float>, fail?: list<string|int|float>}|null $examples
     * @param array{name?: string, description?: string, type?: string}|null $field поле схемы, для которого проверка
     * @param RequestOptions|array<string, mixed>|null $options
     * @return array<string, mixed> `ValidatorSuggestion`
     */
    public function suggest(
        ?string $description = null,
        ?array $examples = null,
        ?array $field = null,
        RequestOptions|array|null $options = null,
    ): array {
        return $this->client->suggestValidator(self::body([
            'description' => $description,
            'examples' => $examples,
            'field' => $field,
        ]), $options);
    }

    /**
     * Тело без незаданных аргументов (SPEC: необязательное, чего пользователь не задал, не отправляется).
     *
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    private static function body(array $fields): array
    {
        return array_filter($fields, static fn (mixed $value): bool => $value !== null);
    }
}
