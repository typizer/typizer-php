<?php

declare(strict_types=1);

namespace Typizer;

use InvalidArgumentException;
use Typizer\Exception\ApiException;
use Typizer\Exception\NetworkException;
use Typizer\Exception\WaitTimeoutException;
use Typizer\Http\CurlTransport;
use Typizer\Http\MultipartBody;
use Typizer\Http\Psr18Transport;
use Typizer\Http\Request;
use Typizer\Http\Response;
use Typizer\Http\Transport;

/**
 * Клиент Typizer API: один метод на каждую операцию REST API, с тем же именем, что `operationId`
 * в справочнике API и функции TypeScript SDK (`getMe`, `extract`, `createRun`, `getRun`, …).
 *
 * ```php
 * $typizer = new \Typizer\Client(getenv('TYPIZER_API_KEY'));
 * $run = $typizer->extract(['url' => 'https://typizer.ru/samples/invoice.pdf', 'schema' => $schema], wait: 60);
 * $run = $typizer->waitForRun($run);
 * ```
 *
 * Ответы — ассоциативные массивы в том виде, в каком их отдаёт API (те же имена полей, что в
 * справочнике). Ошибки API — исключения {@see ApiException} с кодом, статусом и `request_id`.
 * Запросы с `Idempotency-Key`, ответы 429/503 и сетевые сбои SDK повторяет сам (см. `$maxRetries`).
 * SDK ничего не пишет в лог, а ключ API не попадает ни в сообщения исключений, ни в `var_dump()`.
 */
final class Client
{
    public const VERSION = '0.2.0';

    public const DEFAULT_BASE_URL = 'https://api.typizer.ru';

    /** Дольше этого `Retry-After` SDK не ждёт: исключение сразу, решать вам. */
    public const MAX_RETRY_AFTER_SECONDS = 60;

    private const TERMINAL_STATUSES = ['succeeded', 'failed'];

    /** Ключи, значение которых — всегда объект JSON, даже пустой (см. {@see encodeJson()}). */
    private const OBJECT_KEYS = ['schema', 'metadata', 'properties', 'patternProperties', '$defs', 'definitions', 'dependentSchemas'];

    private readonly string $apiKey;

    private readonly string $baseUrl;

    private readonly Transport $transport;

    /** @var \Closure(float): void */
    private readonly \Closure $sleep;

    private ?Response $lastResponse = null;

    /**
     * @param string|null $apiKey ключ API (`tz_live_…`), без слова `Bearer`; по умолчанию —
     *                            переменная окружения `TYPIZER_API_KEY`
     * @param string|null $baseUrl адрес API; по умолчанию `TYPIZER_BASE_URL` или https://api.typizer.ru
     * @param float $timeout секунды на один запрос; для `extract`/`createRun` с `wait` SDK сам
     *                       увеличивает его до `wait + 15`
     * @param int $maxRetries сколько раз повторить запрос при 429, 5xx или сетевой ошибке
     * @param string|null $organization `Typizer-Organization` (`org_…`) для всех вызовов
     * @param Transport|null $transport по умолчанию ext-curl, без него — PSR-18 клиент через
     *                                  php-http/discovery
     * @param (\Closure(float): void)|null $sleep пауза между повторами и опросами (для тестов)
     * @param string|null $proxy прокси для транспорта по умолчанию (`http://user:pass@host:3128`);
     *                          без него cURL берёт `https_proxy` из окружения
     */
    public function __construct(
        #[\SensitiveParameter] ?string $apiKey = null,
        ?string $baseUrl = null,
        private readonly float $timeout = 90.0,
        private readonly int $maxRetries = 2,
        private readonly ?string $organization = null,
        ?Transport $transport = null,
        ?\Closure $sleep = null,
        #[\SensitiveParameter] ?string $proxy = null,
    ) {
        $apiKey ??= self::env('TYPIZER_API_KEY');
        if ($apiKey === null || trim($apiKey) === '') {
            throw new InvalidArgumentException(
                'Typizer: не задан ключ API — передайте его в new Client(...) или в переменной окружения TYPIZER_API_KEY',
            );
        }
        if (preg_match('/^[\x21-\x7E]+$/', $apiKey) !== 1) {
            throw new InvalidArgumentException('Typizer: ключ API должен состоять из печатных символов ASCII без пробелов');
        }
        if ($maxRetries < 0) {
            throw new InvalidArgumentException('Typizer: maxRetries не может быть отрицательным');
        }
        $this->apiKey = $apiKey;
        $this->baseUrl = rtrim($baseUrl ?? self::env('TYPIZER_BASE_URL') ?? self::DEFAULT_BASE_URL, '/');
        if ($transport !== null && $proxy !== null) {
            throw new InvalidArgumentException('Typizer: proxy задаётся либо в Client, либо в своём транспорте, не в обоих');
        }
        $this->transport = $transport ?? (extension_loaded('curl') ? new CurlTransport(proxy: $proxy) : Psr18Transport::discover());
        $this->sleep = $sleep ?? static function (float $seconds): void {
            if ($seconds > 0) {
                usleep((int) round($seconds * 1_000_000));
            }
        };
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return [
            'apiKey' => substr($this->apiKey, 0, 8) . '…',
            'baseUrl' => $this->baseUrl,
            'timeout' => $this->timeout,
            'maxRetries' => $this->maxRetries,
            'organization' => $this->organization,
            'transport' => $this->transport::class,
        ];
    }

    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        throw new \LogicException('Typizer: клиент с ключом API не сериализуется');
    }

    /**
     * Ответ последнего запроса: статус, заголовки (`X-Request-Id`, `RateLimit-*`,
     * `Idempotent-Replayed`), тело. `null`, пока запросов не было.
     */
    public function lastResponse(): ?Response
    {
        return $this->lastResponse;
    }

    // ------------------------------------------------------------------------------------------
    // Прогоны

    /**
     * `POST /v1/extract` — извлечь данные из документа по разовой схеме (`schema`) или схеме из
     * каталога (`catalog`). Документ — `url`, `file_id` или `file` ({@see File}): с `file` запрос
     * уходит multipart, `schema` и `metadata` SDK сам кодирует в JSON.
     *
     * @param array<string, mixed> $body
     * @param int|null $wait 0–60 секунд держать запрос до завершения прогона; если не успел —
     *                       вернётся прогон в `queued`/`processing`, дождитесь его {@see waitForRun()}
     * @param RequestOptions|array<string, mixed>|null $options
     * @return array<string, mixed> прогон (`Run`)
     */
    public function extract(array $body = [], ?int $wait = null, RequestOptions|array|null $options = null): array
    {
        return $this->json('extract', [], ['wait' => $wait], $body, $options);
    }

    /**
     * `POST /v1/typizers/{id}/runs` — запустить сохранённый типизатор на документе.
     *
     * @param string $id типизатор (`tz_…`)
     * @param array<string, mixed> $body `url`, `file_id` или `file` ({@see File}); `webhook_url`, `metadata`, `format`, `mode`
     * @param RequestOptions|array<string, mixed>|null $options
     * @return array<string, mixed> прогон (`Run`)
     */
    public function createRun(string $id, array $body = [], ?int $wait = null, RequestOptions|array|null $options = null): array
    {
        return $this->json('createRun', ['id' => $id], ['wait' => $wait], $body, $options);
    }

    /**
     * `GET /v1/runs` — прогоны организации, новые сначала.
     *
     * @param array{limit?: int, starting_after?: string, status?: string, typizer_id?: string, batch_id?: string, source?: string, created_after?: string, created_before?: string} $query
     * @param RequestOptions|array<string, mixed>|null $options
     * @return array<string, mixed> `['data' => [...], 'has_more' => bool, 'next_cursor' => ?string]`
     */
    public function listRuns(array $query = [], RequestOptions|array|null $options = null): array
    {
        return $this->json('listRuns', [], $query, null, $options);
    }

    /**
     * `GET /v1/runs/{id}` — прогон.
     *
     * @param RequestOptions|array<string, mixed>|null $options
     * @return array<string, mixed>
     */
    public function getRun(string $id, RequestOptions|array|null $options = null): array
    {
        return $this->json('getRun', ['id' => $id], [], null, $options);
    }

    /**
     * `GET /v1/runs/{id}/output` — результат прогона файлом: `json`, `xml`, `csv` или `xlsx`.
     *
     * @param string|null $format по умолчанию — `format` прогона
     * @param RequestOptions|array<string, mixed>|null $options
     */
    public function getRunOutput(string $id, ?string $format = null, RequestOptions|array|null $options = null): Download
    {
        return $this->download('getRunOutput', ['id' => $id], ['format' => $format], $options);
    }

    /**
     * `POST /v1/runs/{id}/retry` — повторить неудавшийся прогон.
     *
     * @param RequestOptions|array<string, mixed>|null $options
     * @return array<string, mixed>
     */
    public function retryRun(string $id, RequestOptions|array|null $options = null): array
    {
        return $this->json('retryRun', ['id' => $id], [], null, $options);
    }

    /**
     * Ждёт, пока прогон завершится (`succeeded` или `failed`), опрашивая `GET /v1/runs/{id}`.
     *
     * Принимает id прогона или сам прогон — например, ответ `extract()`/`createRun()`: уже
     * завершённый прогон возвращается сразу, без запроса. Неудачный прогон (`failed`) тоже
     * возвращается, а не бросает исключение: причина — в его поле `error`.
     *
     * @param string|array<string, mixed> $run id (`run_…`) или прогон
     * @param float $timeout сколько секунд ждать всего
     * @param float $pollInterval первая пауза между опросами; дальше растёт до 10 секунд
     * @param RequestOptions|array<string, mixed>|null $options
     * @return array<string, mixed> завершённый прогон
     *
     * @throws WaitTimeoutException прогон не завершился за `$timeout` (он продолжает выполняться)
     */
    public function waitForRun(
        string|array $run,
        float $timeout = 300.0,
        float $pollInterval = 2.0,
        RequestOptions|array|null $options = null,
    ): array {
        $current = is_array($run) ? $run : $this->getRun($run, $options);
        $id = $current['id'] ?? null;
        if (!is_string($id) || $id === '') {
            throw new InvalidArgumentException('Typizer: waitForRun() ждёт id прогона или прогон с полем id');
        }

        $started = microtime(true);
        $slept = 0.0;
        $interval = max(0.1, $pollInterval);
        while (!in_array($current['status'] ?? null, self::TERMINAL_STATUSES, true)) {
            $spent = max(microtime(true) - $started, $slept);
            $remaining = $timeout - $spent;
            if ($remaining <= 0) {
                throw new WaitTimeoutException(
                    "Typizer: прогон {$id} не завершился за {$timeout} с (статус: " . (string) ($current['status'] ?? '?') . ')',
                    $current,
                );
            }
            $delay = min($interval, $remaining);
            ($this->sleep)($delay);
            $slept += $delay;
            $interval = min($interval * 1.5, 10.0);
            $current = $this->getRun($id, $options);
        }

        return $current;
    }

    // ------------------------------------------------------------------------------------------
    // Пакеты

    /**
     * `POST /v1/batches` — пакет прогонов одного типизатора: `file_ids` (JSON) или `files` — список
     * {@see File} (multipart; один из них может быть `.zip`).
     *
     * @param array<string, mixed> $body
     * @param RequestOptions|array<string, mixed>|null $options
     * @return array<string, mixed>
     */
    public function createBatch(array $body = [], RequestOptions|array|null $options = null): array
    {
        return $this->json('createBatch', [], [], $body, $options);
    }

    /**
     * `GET /v1/batches`.
     *
     * @param array{limit?: int, starting_after?: string} $query
     * @param RequestOptions|array<string, mixed>|null $options
     * @return array<string, mixed>
     */
    public function listBatches(array $query = [], RequestOptions|array|null $options = null): array
    {
        return $this->json('listBatches', [], $query, null, $options);
    }

    /**
     * `GET /v1/batches/{id}`.
     *
     * @param RequestOptions|array<string, mixed>|null $options
     * @return array<string, mixed>
     */
    public function getBatch(string $id, RequestOptions|array|null $options = null): array
    {
        return $this->json('getBatch', ['id' => $id], [], null, $options);
    }

    /**
     * `GET /v1/batches/{id}/export` — результаты пакета одним файлом: `csv`, `xlsx` или `zip`.
     *
     * @param RequestOptions|array<string, mixed>|null $options
     */
    public function exportBatch(string $id, string $format, RequestOptions|array|null $options = null): Download
    {
        return $this->download('exportBatch', ['id' => $id], ['format' => $format], $options);
    }

    // ------------------------------------------------------------------------------------------
    // Файлы

    /**
     * `POST /v1/files` — загрузить документ, чтобы потом передавать его как `file_id`.
     *
     * @param RequestOptions|array<string, mixed>|null $options
     * @return array<string, mixed>
     */
    public function createFile(File $file, RequestOptions|array|null $options = null): array
    {
        return $this->json('createFile', [], [], ['file' => $file], $options);
    }

    /**
     * `GET /v1/files/{id}`.
     *
     * @param RequestOptions|array<string, mixed>|null $options
     * @return array<string, mixed>
     */
    public function getFile(string $id, RequestOptions|array|null $options = null): array
    {
        return $this->json('getFile', ['id' => $id], [], null, $options);
    }

    /**
     * `DELETE /v1/files/{id}`.
     *
     * @param RequestOptions|array<string, mixed>|null $options
     * @return array<string, mixed>
     */
    public function deleteFile(string $id, RequestOptions|array|null $options = null): array
    {
        return $this->json('deleteFile', ['id' => $id], [], null, $options);
    }

    /**
     * `GET /v1/files/{id}/content` — сам документ.
     *
     * @param RequestOptions|array<string, mixed>|null $options
     */
    public function getFileContent(string $id, RequestOptions|array|null $options = null): Download
    {
        return $this->download('getFileContent', ['id' => $id], [], $options);
    }

    // ------------------------------------------------------------------------------------------
    // Типизаторы (сохранённые схемы)

    /**
     * `GET /v1/typizers`.
     *
     * @param array{limit?: int, starting_after?: string} $query
     * @param RequestOptions|array<string, mixed>|null $options
     * @return array<string, mixed>
     */
    public function listTypizers(array $query = [], RequestOptions|array|null $options = null): array
    {
        return $this->json('listTypizers', [], $query, null, $options);
    }

    /**
     * `POST /v1/typizers` — сохранить схему как типизатор.
     *
     * @param array<string, mixed> $body `name`, `schema`, `instructions`, `default_format`, `mode`, …
     * @param RequestOptions|array<string, mixed>|null $options
     * @return array<string, mixed>
     */
    public function createTypizer(array $body, RequestOptions|array|null $options = null): array
    {
        return $this->json('createTypizer', [], [], $body, $options);
    }

    /**
     * `POST /v1/typizers/generate` — черновик схемы по описанию на естественном языке.
     *
     * @param array<string, mixed> $body
     * @param RequestOptions|array<string, mixed>|null $options
     * @return array<string, mixed>
     */
    public function generateTypizer(array $body, RequestOptions|array|null $options = null): array
    {
        return $this->json('generateTypizer', [], [], $body, $options);
    }

    /**
     * `GET /v1/typizers/{id}`.
     *
     * @param RequestOptions|array<string, mixed>|null $options
     * @return array<string, mixed>
     */
    public function getTypizer(string $id, RequestOptions|array|null $options = null): array
    {
        return $this->json('getTypizer', ['id' => $id], [], null, $options);
    }

    /**
     * `PATCH /v1/typizers/{id}` — изменение схемы создаёт новую версию.
     *
     * @param array<string, mixed> $body
     * @param RequestOptions|array<string, mixed>|null $options
     * @return array<string, mixed>
     */
    public function updateTypizer(string $id, array $body, RequestOptions|array|null $options = null): array
    {
        return $this->json('updateTypizer', ['id' => $id], [], $body, $options);
    }

    /**
     * `DELETE /v1/typizers/{id}`.
     *
     * @param RequestOptions|array<string, mixed>|null $options
     * @return array<string, mixed>
     */
    public function deleteTypizer(string $id, RequestOptions|array|null $options = null): array
    {
        return $this->json('deleteTypizer', ['id' => $id], [], null, $options);
    }

    /**
     * `GET /v1/typizers/{id}/versions`.
     *
     * @param array{limit?: int, starting_after?: string} $query
     * @param RequestOptions|array<string, mixed>|null $options
     * @return array<string, mixed>
     */
    public function listTypizerVersions(string $id, array $query = [], RequestOptions|array|null $options = null): array
    {
        return $this->json('listTypizerVersions', ['id' => $id], $query, null, $options);
    }

    /**
     * `GET /v1/typizers/{id}/versions/{n}`.
     *
     * @param RequestOptions|array<string, mixed>|null $options
     * @return array<string, mixed>
     */
    public function getTypizerVersion(string $id, int $n, RequestOptions|array|null $options = null): array
    {
        return $this->json('getTypizerVersion', ['id' => $id, 'n' => (string) $n], [], null, $options);
    }

    /**
     * `GET /v1/typizers/{id}/xsd` — XSD результата в формате XML (для 1С и других учётных систем).
     *
     * @param RequestOptions|array<string, mixed>|null $options
     */
    public function getTypizerXsd(string $id, RequestOptions|array|null $options = null): Download
    {
        return $this->download('getTypizerXsd', ['id' => $id], [], $options);
    }

    // ------------------------------------------------------------------------------------------
    // Каталог схем

    /**
     * `GET /v1/catalog` — каталог готовых схем документов.
     *
     * @param array{q?: string, category?: string, kind?: string, publisher?: string, favorites?: bool, sort?: string, limit?: int, cursor?: string} $query
     * @param RequestOptions|array<string, mixed>|null $options
     * @return array<string, mixed>
     */
    public function listCatalog(array $query = [], RequestOptions|array|null $options = null): array
    {
        return $this->json('listCatalog', [], $query, null, $options);
    }

    /**
     * `GET /v1/catalog/{entry}` — схема каталога (`slug`).
     *
     * @param array{version?: string} $query
     * @param RequestOptions|array<string, mixed>|null $options
     * @return array<string, mixed>
     */
    public function getCatalogEntry(string $entry, array $query = [], RequestOptions|array|null $options = null): array
    {
        return $this->json('getCatalogEntry', ['entry' => $entry], $query, null, $options);
    }

    /**
     * `GET /v1/catalog/{publisher}/{slug}` — схема сообщества.
     *
     * @param array{version?: string} $query
     * @param RequestOptions|array<string, mixed>|null $options
     * @return array<string, mixed>
     */
    public function getCommunityCatalogEntry(string $publisher, string $slug, array $query = [], RequestOptions|array|null $options = null): array
    {
        return $this->json('getCommunityCatalogEntry', ['publisher' => $publisher, 'slug' => $slug], $query, null, $options);
    }

    /**
     * `GET /v1/catalog/publishers/{slug}`.
     *
     * @param RequestOptions|array<string, mixed>|null $options
     * @return array<string, mixed>
     */
    public function getCatalogPublisher(string $slug, RequestOptions|array|null $options = null): array
    {
        return $this->json('getCatalogPublisher', ['slug' => $slug], [], null, $options);
    }

    /**
     * `GET /v1/catalog/{entry}/versions`.
     *
     * @param RequestOptions|array<string, mixed>|null $options
     * @return array<string, mixed>
     */
    public function listCatalogVersions(string $entry, RequestOptions|array|null $options = null): array
    {
        return $this->json('listCatalogVersions', ['entry' => $entry], [], null, $options);
    }

    /**
     * `POST /v1/catalog/{entry}/install` — установить схему каталога как типизатор.
     *
     * @param array<string, mixed> $body
     * @param RequestOptions|array<string, mixed>|null $options
     * @return array<string, mixed>
     */
    public function installCatalogEntry(string $entry, array $body = [], RequestOptions|array|null $options = null): array
    {
        return $this->json('installCatalogEntry', ['entry' => $entry], [], $body, $options);
    }

    /**
     * `POST /v1/catalog/{entry}/fork` — копия схемы каталога для правки.
     *
     * @param array<string, mixed> $body
     * @param RequestOptions|array<string, mixed>|null $options
     * @return array<string, mixed>
     */
    public function forkCatalogEntry(string $entry, array $body = [], RequestOptions|array|null $options = null): array
    {
        return $this->json('forkCatalogEntry', ['entry' => $entry], [], $body, $options);
    }

    /**
     * `PUT /v1/catalog/{entry}/favorite`.
     *
     * @param RequestOptions|array<string, mixed>|null $options
     * @return array<string, mixed>
     */
    public function favoriteCatalogEntry(string $entry, RequestOptions|array|null $options = null): array
    {
        return $this->json('favoriteCatalogEntry', ['entry' => $entry], [], null, $options);
    }

    /**
     * `DELETE /v1/catalog/{entry}/favorite`.
     *
     * @param RequestOptions|array<string, mixed>|null $options
     * @return array<string, mixed>
     */
    public function unfavoriteCatalogEntry(string $entry, RequestOptions|array|null $options = null): array
    {
        return $this->json('unfavoriteCatalogEntry', ['entry' => $entry], [], null, $options);
    }

    /**
     * `POST /v1/catalog/{entry}/reports` — пожаловаться на схему каталога.
     *
     * @param array<string, mixed> $body
     * @param RequestOptions|array<string, mixed>|null $options
     * @return array<string, mixed>
     */
    public function reportCatalogEntry(string $entry, array $body, RequestOptions|array|null $options = null): array
    {
        return $this->json('reportCatalogEntry', ['entry' => $entry], [], $body, $options);
    }

    /**
     * `GET /v1/typizers/{id}/catalog_update` — есть ли новая версия схемы каталога для типизатора.
     *
     * @param array{version?: string} $query
     * @param RequestOptions|array<string, mixed>|null $options
     * @return array<string, mixed>
     */
    public function getTypizerCatalogUpdate(string $id, array $query = [], RequestOptions|array|null $options = null): array
    {
        return $this->json('getTypizerCatalogUpdate', ['id' => $id], $query, null, $options);
    }

    /**
     * `POST /v1/typizers/{id}/catalog_update` — обновить типизатор до новой версии схемы каталога.
     *
     * @param array<string, mixed> $body
     * @param RequestOptions|array<string, mixed>|null $options
     * @return array<string, mixed>
     */
    public function applyTypizerCatalogUpdate(string $id, array $body, RequestOptions|array|null $options = null): array
    {
        return $this->json('applyTypizerCatalogUpdate', ['id' => $id], [], $body, $options);
    }

    // ------------------------------------------------------------------------------------------
    // Вебхуки (проверка подписи входящих — {@see Webhook})

    /**
     * `GET /v1/webhook_endpoints`.
     *
     * @param array{limit?: int, starting_after?: string} $query
     * @param RequestOptions|array<string, mixed>|null $options
     * @return array<string, mixed>
     */
    public function listWebhookEndpoints(array $query = [], RequestOptions|array|null $options = null): array
    {
        return $this->json('listWebhookEndpoints', [], $query, null, $options);
    }

    /**
     * `POST /v1/webhook_endpoints` — в ответе `secret` (`whsec_…`) для {@see Webhook::parse()}.
     *
     * @param array<string, mixed> $body `url`, `events`
     * @param RequestOptions|array<string, mixed>|null $options
     * @return array<string, mixed>
     */
    public function createWebhookEndpoint(array $body, RequestOptions|array|null $options = null): array
    {
        return $this->json('createWebhookEndpoint', [], [], $body, $options);
    }

    /**
     * `GET /v1/webhook_endpoints/{id}`.
     *
     * @param RequestOptions|array<string, mixed>|null $options
     * @return array<string, mixed>
     */
    public function getWebhookEndpoint(string $id, RequestOptions|array|null $options = null): array
    {
        return $this->json('getWebhookEndpoint', ['id' => $id], [], null, $options);
    }

    /**
     * `PATCH /v1/webhook_endpoints/{id}` — например, `['status' => 'active']`.
     *
     * @param array<string, mixed> $body
     * @param RequestOptions|array<string, mixed>|null $options
     * @return array<string, mixed>
     */
    public function updateWebhookEndpoint(string $id, array $body, RequestOptions|array|null $options = null): array
    {
        return $this->json('updateWebhookEndpoint', ['id' => $id], [], $body, $options);
    }

    /**
     * `DELETE /v1/webhook_endpoints/{id}`.
     *
     * @param RequestOptions|array<string, mixed>|null $options
     * @return array<string, mixed>
     */
    public function deleteWebhookEndpoint(string $id, RequestOptions|array|null $options = null): array
    {
        return $this->json('deleteWebhookEndpoint', ['id' => $id], [], null, $options);
    }

    /**
     * `POST /v1/webhook_endpoints/{id}/test` — прислать тестовый `run.succeeded`.
     *
     * @param RequestOptions|array<string, mixed>|null $options
     * @return array<string, mixed>
     */
    public function testWebhookEndpoint(string $id, RequestOptions|array|null $options = null): array
    {
        return $this->json('testWebhookEndpoint', ['id' => $id], [], null, $options);
    }

    /**
     * `GET /v1/webhook_endpoints/{id}/deliveries` — журнал доставок.
     *
     * @param array{limit?: int, starting_after?: string} $query
     * @param RequestOptions|array<string, mixed>|null $options
     * @return array<string, mixed>
     */
    public function listWebhookDeliveries(string $id, array $query = [], RequestOptions|array|null $options = null): array
    {
        return $this->json('listWebhookDeliveries', ['id' => $id], $query, null, $options);
    }

    /**
     * `POST /v1/webhook_deliveries/{id}/retry` — повторить доставку вручную.
     *
     * @param RequestOptions|array<string, mixed>|null $options
     * @return array<string, mixed>
     */
    public function retryWebhookDelivery(string $id, RequestOptions|array|null $options = null): array
    {
        return $this->json('retryWebhookDelivery', ['id' => $id], [], null, $options);
    }

    // ------------------------------------------------------------------------------------------
    // Аккаунт, баланс, служебное

    /**
     * `GET /v1/me` — кто вызывает: организация, ключ, пользователь.
     *
     * @param RequestOptions|array<string, mixed>|null $options
     * @return array<string, mixed>
     */
    public function getMe(RequestOptions|array|null $options = null): array
    {
        return $this->json('getMe', [], [], null, $options);
    }

    /**
     * `GET /v1/organization`.
     *
     * @param RequestOptions|array<string, mixed>|null $options
     * @return array<string, mixed>
     */
    public function getOrganization(RequestOptions|array|null $options = null): array
    {
        return $this->json('getOrganization', [], [], null, $options);
    }

    /**
     * `GET /v1/balance` — остаток страниц.
     *
     * @param RequestOptions|array<string, mixed>|null $options
     * @return array<string, mixed>
     */
    public function getBalance(RequestOptions|array|null $options = null): array
    {
        return $this->json('getBalance', [], [], null, $options);
    }

    /**
     * `GET /v1/usage` — расход страниц за период.
     *
     * @param array{from?: string, to?: string, group_by?: string} $query
     * @param RequestOptions|array<string, mixed>|null $options
     * @return array<string, mixed>
     */
    public function getUsage(array $query = [], RequestOptions|array|null $options = null): array
    {
        return $this->json('getUsage', [], $query, null, $options);
    }

    /**
     * `GET /v1/status` — состояние сервиса и плановые работы (без авторизации).
     *
     * @param RequestOptions|array<string, mixed>|null $options
     * @return array<string, mixed>
     */
    public function getStatus(RequestOptions|array|null $options = null): array
    {
        return $this->json('getStatus', [], [], null, $options);
    }

    /**
     * `GET /v1/openapi.json` — описание API в формате OpenAPI.
     *
     * @param RequestOptions|array<string, mixed>|null $options
     * @return array<string, mixed>
     */
    public function getOpenApiDocument(RequestOptions|array|null $options = null): array
    {
        return $this->json('getOpenApiDocument', [], [], null, $options);
    }

    // ------------------------------------------------------------------------------------------

    /**
     * @param array<string, string> $path
     * @param array<string, mixed> $query
     * @param array<string, mixed>|null $body
     * @param RequestOptions|array<string, mixed>|null $options
     * @return array<string, mixed>
     */
    private function json(string $operation, array $path, array $query, ?array $body, RequestOptions|array|null $options): array
    {
        $response = $this->call($operation, $path, $query, $body, $options);
        if ($response->body === '') {
            return [];
        }
        try {
            $decoded = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ApiException(
                "Typizer API: ответ {$operation} — не JSON (HTTP {$response->status})",
                $response->status,
                'invalid_response',
                $response->requestId(),
                null,
                null,
                null,
                null,
                [],
            );
        }

        return is_array($decoded) ? $decoded : ['value' => $decoded];
    }

    /**
     * @param array<string, string> $path
     * @param array<string, mixed> $query
     * @param RequestOptions|array<string, mixed>|null $options
     */
    private function download(string $operation, array $path, array $query, RequestOptions|array|null $options): Download
    {
        $response = $this->call($operation, $path, $query, null, $options);

        return new Download(
            $response->body,
            $response->contentType(),
            Download::filenameFrom($response->header('content-disposition')),
        );
    }

    /**
     * @param array<string, string> $path
     * @param array<string, mixed> $query
     * @param array<string, mixed>|null $body
     * @param RequestOptions|array<string, mixed>|null $options
     */
    private function call(string $operation, array $path, array $query, ?array $body, RequestOptions|array|null $options): Response
    {
        $spec = Operations::ALL[$operation];
        [$method, $template] = $spec;
        $opts = RequestOptions::from($options);

        $url = $this->baseUrl . preg_replace_callback(
            '/\{(\w+)\}/',
            static function (array $m) use ($path, $operation): string {
                $value = $path[$m[1]] ?? '';
                if ($value === '') {
                    throw new InvalidArgumentException("Typizer: {$operation}: пустой параметр пути {$m[1]}");
                }

                return rawurlencode($value);
            },
            $template,
        );
        $queryString = $this->queryString($operation, $spec['query'], $query);
        if ($queryString !== '') {
            $url .= '?' . $queryString;
        }

        $headers = [];
        foreach ($opts->headers as $name => $value) {
            // Ключ, тело и параметры вызова задаёт сам SDK: дополнительные заголовки их не подменяют
            if (!in_array(strtolower((string) $name), ['authorization', 'content-type', 'content-length', 'idempotency-key', 'typizer-organization'], true)) {
                $headers[(string) $name] = (string) $value;
            }
        }
        $headers['Authorization'] = 'Bearer ' . $this->apiKey;
        $headers['Accept'] = $spec['download'] ? '*/*' : 'application/json';
        $headers['User-Agent'] = 'typizer-php/' . self::VERSION . ' PHP/' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
        $organization = $opts->organization ?? $this->organization;
        if ($organization !== null) {
            $headers['Typizer-Organization'] = $organization;
        }
        $idempotencyKey = $opts->idempotencyKey ?? ($spec['idempotent'] ? self::uuid4() : null);
        if ($idempotencyKey !== null) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        $payload = null;
        if ($body !== null) {
            if ($spec['body'] === null) {
                throw new InvalidArgumentException("Typizer: {$operation} не принимает тело запроса");
            }
            if (self::hasFile($body)) {
                if (!str_contains($spec['body'], 'multipart')) {
                    throw new InvalidArgumentException("Typizer: {$operation} не принимает файлы — загрузите файл через createFile() и передайте file_id");
                }
                $payload = new MultipartBody(self::multipartParts($body));
            } else {
                $payload = self::encodeJson($body);
                $headers['Content-Type'] = 'application/json';
            }
        }

        $timeout = $opts->timeout ?? $this->timeout;
        if (isset($query['wait']) && is_int($query['wait'])) {
            $timeout = max($timeout, $query['wait'] + 15.0);
        }

        $request = new Request($method, $url, $headers, $payload, $timeout);
        $maxRetries = $opts->maxRetries ?? $this->maxRetries;
        $safeToRepeat = in_array($method, ['GET', 'HEAD', 'PUT', 'DELETE'], true) || $idempotencyKey !== null && $spec['idempotent'];

        for ($attempt = 0; ; $attempt++) {
            try {
                $response = $this->transport->send($request);
            } catch (NetworkException $e) {
                if ($attempt < $maxRetries && $safeToRepeat) {
                    ($this->sleep)(self::backoff($attempt));
                    continue;
                }
                throw $e;
            }
            $this->lastResponse = $response;
            if ($response->status < 400) {
                return $response;
            }

            $retryable = $response->status === 429
                || ($safeToRepeat && in_array($response->status, [500, 502, 503, 504], true));
            if ($retryable && $attempt < $maxRetries) {
                $retryAfter = ApiException::parseRetryAfter($response->header('retry-after'));
                if ($retryAfter === null || $retryAfter <= self::MAX_RETRY_AFTER_SECONDS) {
                    ($this->sleep)($retryAfter ?? self::backoff($attempt));
                    continue;
                }
            }

            throw ApiException::fromResponse($response);
        }
    }

    /**
     * @param list<string> $allowed
     * @param array<string, mixed> $query
     */
    private function queryString(string $operation, array $allowed, array $query): string
    {
        $pairs = [];
        foreach ($query as $name => $value) {
            if (!in_array($name, $allowed, true)) {
                throw new InvalidArgumentException(
                    "Typizer: {$operation}: неизвестный параметр запроса «{$name}»"
                    . ($allowed === [] ? '' : ' (допустимы: ' . implode(', ', $allowed) . ')'),
                );
            }
            if ($value === null) {
                continue;
            }
            if (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            } elseif ($value instanceof \DateTimeInterface) {
                $value = $value->format(\DateTimeInterface::RFC3339);
            } elseif (!is_scalar($value)) {
                throw new InvalidArgumentException("Typizer: {$operation}: параметр «{$name}» должен быть строкой или числом");
            }
            $pairs[] = rawurlencode((string) $name) . '=' . rawurlencode((string) $value);
        }

        return implode('&', $pairs);
    }

    /** @param array<string, mixed> $body */
    private static function hasFile(array $body): bool
    {
        foreach ($body as $value) {
            if ($value instanceof File) {
                return true;
            }
            if (is_array($value) && $value !== [] && array_is_list($value) && $value[0] instanceof File) {
                return true;
            }
        }

        return false;
    }

    /**
     * Поля multipart: файлы — частями (список файлов — повтором части), массивы (`schema`,
     * `metadata`) — JSON, булевы — `true`/`false`, `null` пропускается.
     *
     * @param array<string, mixed> $body
     * @return list<array{0: string, 1: string|File}>
     */
    private static function multipartParts(array $body): array
    {
        $parts = [];
        foreach ($body as $name => $value) {
            $name = (string) $name;
            if ($value === null) {
                continue;
            }
            if ($value instanceof File) {
                $parts[] = [$name, $value];
            } elseif (is_array($value) && $value !== [] && array_is_list($value) && $value[0] instanceof File) {
                foreach ($value as $file) {
                    if (!$file instanceof File) {
                        throw new InvalidArgumentException("Typizer: поле {$name}: в списке файлов должны быть только File");
                    }
                    $parts[] = [$name, $file];
                }
            } elseif (is_array($value) || is_object($value)) {
                $parts[] = [$name, self::encodeJson($value)];
            } elseif (is_bool($value)) {
                $parts[] = [$name, $value ? 'true' : 'false'];
            } else {
                $parts[] = [$name, (string) $value];
            }
        }

        return $parts;
    }

    /**
     * JSON тела запроса. Пустой массив PHP кодируется как `[]`, а API ждёт объект там, где по смыслу
     * объект: само тело, `schema`, `metadata`, `properties` и другие словари JSON Schema — там
     * пустой массив становится `{}`, чтобы `properties => []` не ломал схему.
     */
    private static function encodeJson(mixed $value): string
    {
        return json_encode(
            $value === [] ? new \stdClass() : self::objectify($value),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION,
        );
    }

    private static function objectify(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        foreach ($value as $key => $item) {
            $value[$key] = $item === [] && in_array($key, self::OBJECT_KEYS, true) ? new \stdClass() : self::objectify($item);
        }

        return $value;
    }

    private static function backoff(int $attempt): float
    {
        $base = min(8.0, 0.5 * (2 ** $attempt));

        return $base * (0.75 + mt_rand() / mt_getrandmax() * 0.5);
    }

    private static function uuid4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    private static function env(string $name): ?string
    {
        $value = getenv($name);
        if (is_string($value) && $value !== '') {
            return $value;
        }
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
