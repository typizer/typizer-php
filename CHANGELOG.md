# Изменения

Формат — [Keep a Changelog](https://keepachangelog.com/ru/1.1.0/), версии — [SemVer](https://semver.org/lang/ru/);
версия пакета `typizer/typizer` на Packagist — git-тег `vX.Y.Z`.

## [0.3.0] — 2026-11-18

Совместимо с 0.2.0: прежние вызовы работают без изменений.

### Добавлено

- Свои проверки полей: методы `listValidators`, `getValidator`, `getValidatorBySlug`,
  `createValidator`, `updateValidator`, `deleteValidator`, `testValidator`, `suggestValidator`
  и `$client->validators` с именованными аргументами — `list()`
  (генератор по всем страницам), `get('my/<slug>[@версия]' | 'val_…')`, `create()`, `update()`,
  `archive()`, `versions()`, `test($values, ref: | spec:)` (ровно один из них — иначе
  `InvalidArgumentException` до запроса), `suggest()`. `spec` — обычный массив, передаётся как есть.
- `Typizer\Run::warnings($run)` — поля прогона со статусом `warn` по порядку `fields`:
  `['path', 'message', 'reason']` (`message` — `null` у прогонов и вебхуков до его появления).
- Каталог проверок: `$client->validators->fork('iban@1', slug: 'my-iban')` — своя копия
  проверки каталога `my/<slug>`; у `listCatalog` и `getCatalogEntry`/`getCommunityCatalogEntry` —
  `type` в `$query` (`'validator'` — проверки полей), у `listCatalogVersions`, `forkCatalogEntry`,
  `favoriteCatalogEntry`, `unfavoriteCatalogEntry`, `reportCatalogEntry` — аргумент `type:`.
  Записи каталога несут `type`, `rule_preview` и `validator`; причина жалобы `false_positive`.
- `fields[*].checks[*].corrected` — значение прочитано повторно после проваленной проверки
  («Перечитать при ошибке», режим «Точный»).
- Тесты конформанса SC-36…SC-39 против общего мок-сервера (`scripts/test.sh` поднимает его рядом).

### Изменено

- Пустой массив в ключе `spec` уходит в JSON объектом `{}`, как `schema` и `metadata`.
- У `listCatalogVersions`, `forkCatalogEntry`, `favoriteCatalogEntry`, `unfavoriteCatalogEntry` и
  `reportCatalogEntry` перед `$options` появился аргумент `?string $type`: передавайте опции
  именованным аргументом (`options: […]`) — так, как во всех примерах README.

## [0.2.0] — 2026-11-16

Совместимо с 0.1.0: прежние вызовы работают без изменений.

### Добавлено

- Причина `code_mismatch` у `$run['fields'][…]['reason']`: значение поля расходится со штрихкодом или QR-кодом
  на документе. Сервис сам читает коды (платёжный QR по ГОСТ Р 56042-2014, QR чека, «Честный знак»), чтобы точнее
  заполнять поля вашей схемы; ответ — по-прежнему только поля схемы, отдельного списка кодов в прогоне
  нет.

## [0.1.0] — 2026-11-14

Первый выпуск: `Typizer\Client` со всеми операциями публичного API, `extract()` — документ и схема →
данные, повторы с `Retry-After`, постраничные списки, проверка подписи вебхуков
(`Typizer\Webhook::parse()` и `Webhook::verify()`).
