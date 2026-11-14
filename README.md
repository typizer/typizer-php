# typizer/typizer — PHP SDK для Typizer API

Официальный PHP SDK для [Typizer](https://typizer.ru) — сервиса, который извлекает данные из
документов (счета, УПД, накладные, договоры, сканы и фото) в JSON по вашей схеме.

Один метод на каждую операцию REST API, с теми же именами, что в справочнике API и в SDK для
TypeScript (`extract`, `createRun`, `getRun`, `getMe`, …), и с теми же именами полей и кодами
ошибок. Ответы — обычные массивы PHP. Полная документация — [docs.typizer.ru](https://docs.typizer.ru).

## Установка

```sh
composer require typizer/typizer
```

Нужен PHP 8.1 или новее и расширение `curl` (есть почти на любом хостинге и в «Битрикс: Управление
сайтом»). Других зависимостей нет: ни Guzzle, ни PSR-пакетов SDK не требует — но умеет работать
через них (см. [«Транспорт»](#транспорт)).

**Без Composer** (старый сервер Битрикс, виртуальный хостинг): скачайте архив SDK, распакуйте его,
например, в `/local/php_interface/typizer` и подключите автозагрузчик из архива:

```php
require_once $_SERVER['DOCUMENT_ROOT'] . '/local/php_interface/typizer/autoload.php';
```

## Ключ API

1. Зарегистрируйтесь в кабинете [my.typizer.ru](https://my.typizer.ru).
2. Откройте раздел «Ключи API» и создайте ключ со скоупом `runs:write` (для запуска прогонов) —
   подробнее о скоупах в [«Ключах API»](https://docs.typizer.ru/how-to/api-keys/).
3. Ключ (`tz_live_…`) показывается один раз. Сохраните его, например в переменную окружения:

```sh
export TYPIZER_API_KEY="tz_live_…"
```

`new Client()` без аргументов берёт ключ из `TYPIZER_API_KEY`. Ключ — секрет сервера: не
передавайте его в браузер и не храните в репозитории. SDK ничего не пишет в лог, а ключ не попадает
ни в сообщения исключений, ни в `var_dump()`/`print_r()` клиента.

## Быстрый старт

Извлечь данные из документа по разовой схеме (`POST /v1/extract`). `wait: 60` держит запрос до
завершения прогона, но не дольше 60 секунд:

```php
<?php

require __DIR__ . '/vendor/autoload.php';

$typizer = new Typizer\Client(getenv('TYPIZER_API_KEY'));

$run = $typizer->extract([
    'url' => 'https://typizer.ru/samples/invoice.pdf',
    'schema' => [
        'type' => 'object',
        'properties' => [
            'invoice_number' => ['type' => 'string'],
            'total' => ['type' => 'number'],
            'inn' => ['type' => 'string', 'x-typizer-check' => 'inn'],
        ],
        'required' => ['invoice_number', 'total'],
    ],
], wait: 60);

// Не успел за 60 секунд — дождаться опросом (завершённый прогон вернётся сразу)
$run = $typizer->waitForRun($run);

echo $run['status'], PHP_EOL; // succeeded | failed
print_r($run['output']);      // результат по вашей схеме
```

`Idempotency-Key` для `extract`, `createRun`, `createBatch` и других создающих операций SDK
подставляет сам — случайный на каждый вызов, так что его собственные повторы после сетевой ошибки
или `503` ничего не создадут и не спишут дважды. Свой ключ (например, из номера заказа) передайте в
`options: ['idempotency_key' => '…']`.

## Файл с диска, из памяти или из потока

Вместо `url` передайте `file` — запрос уйдёт как `multipart/form-data`, а `schema` и `metadata` SDK
сам закодирует в JSON:

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use Typizer\File;

$typizer = new Typizer\Client(getenv('TYPIZER_API_KEY'));

$run = $typizer->extract([
    'file' => File::fromPath(__DIR__ . '/invoice.pdf'),  // читается с диска потоком
    'catalog' => 'schet-na-oplatu@1',                      // или готовая схема из каталога вместо schema
    'metadata' => ['order_id' => '12345'],
], wait: 60);

// Другие источники того же файла:
$fromMemory = File::fromString(file_get_contents(__DIR__ . '/invoice.pdf'), 'invoice.pdf');
$fromStream = File::fromStream(fopen(__DIR__ . '/invoice.pdf', 'rb'));

// Загрузить один раз и запускать по file_id
$file = $typizer->createFile($fromMemory);
echo $file['id'], PHP_EOL;
```

## Типизатор: схема, сохранённая один раз

Схему удобно сохранить как типизатор (`POST /v1/typizers`) и запускать его по `id`. Результат можно
сразу получить в XML для 1С, CSV или XLSX:

```php
<?php

require __DIR__ . '/vendor/autoload.php';

$typizer = new Typizer\Client(getenv('TYPIZER_API_KEY'));

$invoices = $typizer->createTypizer([
    'name' => 'Счета поставщиков',
    'schema' => [
        'type' => 'object',
        'properties' => [
            'invoice_number' => ['type' => 'string'],
            'total' => ['type' => 'number'],
        ],
        'required' => ['invoice_number', 'total'],
    ],
]);

$run = $typizer->createRun($invoices['id'], ['url' => 'https://typizer.ru/samples/invoice.pdf'], wait: 60);
$run = $typizer->waitForRun($run, timeout: 300);

$xml = $typizer->getRunOutput($run['id'], 'xml');
$xml->saveTo(sys_get_temp_dir() . '/' . ($xml->filename ?? 'invoice.xml'));
```

## Обработка ошибок

Ошибка API — исключение `Typizer\Exception\ApiException` (или его подкласс) с полями из ответа
`application/problem+json` (RFC 9457):
- `errorCode` — машиночитаемый код (`invalid_request`, `insufficient_balance`, `rate_limited`, …);
- `httpStatus` — статус HTTP; `detail` — описание случая, `param` — виноватое поле запроса;
- `requestId` — приложите его к обращению в поддержку (support@typizer.ru);
- `docUrl` — страница кода: `https://docs.typizer.ru/errors/<code>/`; `retryAfter` — секунды из
  `Retry-After`; `retryable` — поможет ли повтор позже (`rate_limited`, `upstream_unavailable`,
  `internal_error`).

| Исключение                     | Когда                                                       |
| ------------------------------ | ----------------------------------------------------------- |
| `InvalidRequestException`      | 400, 413, 415: неверный запрос, схема, слишком большой файл |
| `AuthenticationException`      | 401: ключа нет, он неверен или отозван                      |
| `InsufficientBalanceException` | 402: не хватает страниц на балансе                          |
| `PermissionDeniedException`    | 403: у ключа нет скоупа или доступа                         |
| `NotFoundException`            | 404                                                         |
| `ConflictException`            | 409, в том числе `idempotency_conflict`                     |
| `DocumentException`            | 422: документ не читается или данные не извлеклись          |
| `RateLimitException`           | 429 после всех повторов                                     |
| `ServerException`              | 5xx после всех повторов                                     |
| `NetworkException`             | ответа нет: DNS, соединение, таймаут (`isTimeout`)          |
| `WaitTimeoutException`         | `waitForRun()` не дождался; прогон — в `$e->run`            |

Все они наследуют `Typizer\Exception\TypizerException`.

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use Typizer\Exception\ApiException;
use Typizer\Exception\InsufficientBalanceException;
use Typizer\Exception\NetworkException;

$typizer = new Typizer\Client(getenv('TYPIZER_API_KEY'));

try {
    $run = $typizer->createRun('tz_…', ['url' => 'https://typizer.ru/samples/invoice.pdf'], wait: 60);
} catch (InsufficientBalanceException $e) {
    echo 'Пополните баланс в кабинете', PHP_EOL;
} catch (ApiException $e) {
    echo "{$e->errorCode}: {$e->detail} ({$e->docUrl}, request_id {$e->requestId})", PHP_EOL;
} catch (NetworkException $e) {
    echo 'Сеть: ', $e->getMessage(), PHP_EOL;
}
```

SDK сам повторяет запрос (по умолчанию до 2 раз, `new Client(maxRetries: …)`): при `429` — всегда,
выждав `Retry-After` (если тот не длиннее минуты); при `500`/`502`/`503`/`504` и сетевой ошибке —
только когда повтор безопасен: `GET`/`PUT`/`DELETE` или запрос с `Idempotency-Key`. Остальные коды
не исчезнут от повтора: сначала исправьте причину. Все коды со статусами HTTP —
[«Коды ошибок»](https://docs.typizer.ru/reference/errors/), правила повторов —
[«Обработка ошибок и повторов»](https://docs.typizer.ru/how-to/errors/).

## Вебхуки

Вместо ожидания результат можно получать вебхуком: укажите `webhook_url` в запросе на прогон или
создайте конечную точку:

```php
<?php

require __DIR__ . '/vendor/autoload.php';

$typizer = new Typizer\Client(getenv('TYPIZER_API_KEY'));

$endpoint = $typizer->createWebhookEndpoint([
    'url' => 'https://your-server.example/typizer-webhook.php',
    'events' => ['run.succeeded', 'run.failed'],
]);
echo $endpoint['secret'], PHP_EOL; // whsec_… — ключ для проверки подписи, храните как секрет

$typizer->testWebhookEndpoint($endpoint['id']); // пришлёт тестовый run.succeeded
```

Вебхуки подписываются по спецификации [Standard Webhooks](https://www.standardwebhooks.com)
(HMAC-SHA256). `Typizer\Webhook::parse()` проверяет подпись и метку времени (не старше 5 минут) и
возвращает событие, а при неверной подписи бросает `WebhookVerificationException`; `Webhook::verify()`
— то же самое, но возвращает `true`/`false`. Тело передавайте ровно в тех байтах, в которых оно
пришло (`php://input`), а не пересобранным `json_encode()`. Подробнее — в
[«Приёме вебхуков»](https://docs.typizer.ru/how-to/webhooks/). Никогда не обрабатывайте тело
вебхука, не проверив подпись.

## Битрикс24 и amoCRM: файл из сделки — данные обратно в сделку

Типичная задача интегратора: менеджер прикладывает счёт к сделке, а номер и сумма должны сами
оказаться в полях сделки. Ниже — два обработчика для Битрикс24.

**1. Исходящий вебхук Битрикс24** на событие `ONCRMDEALUPDATE` (Приложения → Разработчикам →
Другое → Исходящий вебхук). Обработчик берёт файл из поля сделки через входящий вебхук
(`crm.item.get`, ссылка `urlMachine`), отправляет его в Typizer и сразу отвечает — результат придёт
вебхуком Typizer, а номер сделки едет с прогоном в `metadata`:

```php
<?php
// bitrix24-deal.php — обработчик исходящего вебхука Битрикс24 (ONCRMDEALUPDATE)

require __DIR__ . '/vendor/autoload.php';

use Typizer\File;

$portal = rtrim(getenv('BITRIX24_WEBHOOK_URL'), '/'); // входящий вебхук: https://портал.bitrix24.ru/rest/1/…
$fileField = 'ufCrm_1700000000';   // поле «Файл» сделки; точное имя — в crm.item.fields
$numberField = 'ufCrm_1700000001'; // строковое поле для номера счёта

// Запрос действительно от вашего портала: токен из настроек исходящего вебхука
$expected = (string) getenv('BITRIX24_APP_TOKEN');
$got = (string) ($_POST['auth']['application_token'] ?? '');
if ($expected === '' || !hash_equals($expected, $got)) {
    http_response_code(403);
    exit;
}
$dealId = (int) ($_POST['data']['FIELDS']['ID'] ?? 0);

$bitrix = static function (string $method, array $params) use ($portal): array {
    $response = file_get_contents("{$portal}/{$method}.json", false, stream_context_create(['http' => [
        'method' => 'POST',
        'header' => 'Content-Type: application/x-www-form-urlencoded',
        'content' => http_build_query($params),
    ]]));

    return json_decode($response, true)['result'];
};

$deal = $bitrix('crm.item.get', ['entityTypeId' => 2, 'id' => $dealId])['item'];
$file = $deal[$fileField] ?? null;
$file = isset($file[0]) ? $file[0] : $file;        // поле с несколькими файлами — список
if (!isset($file['urlMachine']) || !empty($deal[$numberField])) {
    exit;                                          // файла нет или сделка уже заполнена
}

$typizer = new Typizer\Client(getenv('TYPIZER_API_KEY'));
$typizer->extract([
    'file' => File::fromString(file_get_contents($file['urlMachine']), 'deal-' . $dealId . '.pdf'),
    'catalog' => 'schet-na-oplatu@1',
    'webhook_url' => 'https://your-server.example/typizer-webhook.php',
    'metadata' => ['bitrix_deal_id' => (string) $dealId],
], wait: 0, options: [
    // Тот же файл той же сделки — тот же ключ: повтор события в течение суток не создаст второй прогон
    'idempotency_key' => "bitrix24-deal-{$dealId}-file-{$file['id']}",
]);
```

**2. Вебхук Typizer** — проверяет подпись и записывает результат в сделку (`crm.item.update`):

```php
<?php
// typizer-webhook.php — приём результата от Typizer

require __DIR__ . '/vendor/autoload.php';

use Typizer\Exception\WebhookVerificationException;
use Typizer\Webhook;

try {
    $event = Webhook::parse(getenv('TYPIZER_WEBHOOK_SECRET'), Webhook::requestHeaders(), file_get_contents('php://input'));
} catch (WebhookVerificationException $e) {
    http_response_code(400);
    exit;
}

$run = $event['data']['object'];
$dealId = $run['metadata']['bitrix_deal_id'] ?? null;
if ($event['type'] === 'run.succeeded' && $dealId !== null) {
    $portal = rtrim(getenv('BITRIX24_WEBHOOK_URL'), '/');
    file_get_contents("{$portal}/crm.item.update.json", false, stream_context_create(['http' => [
        'method' => 'POST',
        'header' => 'Content-Type: application/x-www-form-urlencoded',
        'content' => http_build_query([
            'entityTypeId' => 2,
            'id' => $dealId,
            'fields' => [
                'ufCrm_1700000001' => $run['output']['number'] ?? '',
                'opportunity' => $run['output']['total'] ?? 0,
            ],
        ]),
    ]]));
}
http_response_code(200);
```

Поля результата (`number`, `total`) — из схемы каталога `schet-na-oplatu@1`; со своей схемой будут
ваши имена. Вебхук может прийти повторно с тем же заголовком `webhook-id` — запись в одни и те же
поля сделки от этого не ломается.

**amoCRM** — та же схема: вебхук amoCRM о сделке (`leads[status][0][id]` или `leads[update][0][id]`
в теле запроса) → файл сделки из API файлов amoCRM → `File::fromString()` → `extract()` с
`webhook_url` и `metadata` → в обработчике вебхука Typizer — `PATCH /api/v4/leads/{id}` с полями
результата.

## Транспорт

По умолчанию запросы идут через ext-curl, документ отправляется потоком (до 2 МиБ в памяти, остальное
— во временном файле). Таймаут одного запроса — 90 секунд (`new Client(timeout: …)`), для
`extract`/`createRun` с `wait` SDK сам продлевает его до `wait + 15`.

Прокси — `new Client(proxy: 'http://user:pass@proxy.local:3128')` (без него cURL сам берёт
`https_proxy` из окружения). Свои параметры cURL — например, свежий набор корневых сертификатов на
старом сервере:

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use Typizer\Http\CurlTransport;

$typizer = new Typizer\Client(
    getenv('TYPIZER_API_KEY'),
    transport: new CurlTransport(connectTimeout: 5, curlOptions: [
        CURLOPT_CAINFO => __DIR__ . '/cacert.pem',
    ]),
);
print_r($typizer->getBalance());
```

Если ext-curl нет или у проекта уже есть HTTP-клиент, подключите любой PSR-18 клиент —
`new Typizer\Http\Psr18Transport($client, $requestFactory, $streamFactory)`, или
`Psr18Transport::discover()`, если установлен `php-http/discovery`
(`composer require guzzlehttp/guzzle php-http/discovery`). Без ext-curl `new Client()` пробует
`discover()` сам.

## Методы

Каждый метод — одна операция REST API; параметры пути — первыми аргументами, тело — массивом
`$body`, параметры строки запроса — массивом `$query` (или отдельным аргументом: `wait`, `format`),
а последним всегда идёт `options` (`idempotency_key`, `organization`, `timeout`, `max_retries`,
`headers`). Ответ последнего запроса со статусом и заголовками — `$typizer->lastResponse()`.

| Метод                                                                                                                                                                                                                                                                                 | REST                                                     |
| ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------- |
| `extract($body, wait:)`                                                                                                                                                                                                                                                               | `POST /v1/extract`                                       |
| `createRun($id, $body, wait:)`                                                                                                                                                                                                                                                        | `POST /v1/typizers/{id}/runs`                            |
| `getRun($id)`, `listRuns($query)`, `retryRun($id)`                                                                                                                                                                                                                                    | `/v1/runs…`                                              |
| `getRunOutput($id, $format)` → `Download`                                                                                                                                                                                                                                             | `GET /v1/runs/{id}/output`                               |
| `waitForRun($runOrId, timeout:, pollInterval:)`                                                                                                                                                                                                                                       | опрос `GET /v1/runs/{id}`                                |
| `createBatch`, `listBatches`, `getBatch`, `exportBatch`                                                                                                                                                                                                                               | `/v1/batches…`                                           |
| `createFile(File)`, `getFile`, `deleteFile`, `getFileContent`                                                                                                                                                                                                                         | `/v1/files…`                                             |
| `createTypizer`, `generateTypizer`, `listTypizers`, `getTypizer`, `updateTypizer`, `deleteTypizer`, `listTypizerVersions`, `getTypizerVersion`, `getTypizerXsd`                                                                                                                       | `/v1/typizers…`                                          |
| `listCatalog`, `getCatalogEntry`, `getCommunityCatalogEntry`, `getCatalogPublisher`, `listCatalogVersions`, `installCatalogEntry`, `forkCatalogEntry`, `favoriteCatalogEntry`, `unfavoriteCatalogEntry`, `reportCatalogEntry`, `getTypizerCatalogUpdate`, `applyTypizerCatalogUpdate` | `/v1/catalog…`                                           |
| `createWebhookEndpoint`, `listWebhookEndpoints`, `getWebhookEndpoint`, `updateWebhookEndpoint`, `deleteWebhookEndpoint`, `testWebhookEndpoint`, `listWebhookDeliveries`, `retryWebhookDelivery`                                                                                       | `/v1/webhook_endpoints…`                                 |
| `getMe`, `getOrganization`, `getBalance`, `getUsage($query)`                                                                                                                                                                                                                          | `/v1/me`, `/v1/organization`, `/v1/balance`, `/v1/usage` |
| `getStatus`, `getOpenApiDocument`                                                                                                                                                                                                                                                     | `/v1/status`, `/v1/openapi.json`                         |

## Документация

- [Документация Typizer](https://docs.typizer.ru)
- [SDK](https://docs.typizer.ru/reference/sdks/)
- [Справочник API](https://docs.typizer.ru/reference/api/) — REST-операция за каждым методом SDK
- [Первый вызов API](https://docs.typizer.ru/tutorials/first-api-call/)

Вопросы и проблемы — support@typizer.ru.

## Лицензия

MIT — см. файл [LICENSE](LICENSE).
