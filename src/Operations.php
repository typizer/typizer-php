<?php

declare(strict_types=1);

namespace Typizer;

/**
 * Таблица операций Typizer API: `operationId` => метод HTTP, путь, параметры строки запроса, тело
 * (`json`, `multipart` или оба), принимает ли операция `Idempotency-Key` и отдаёт ли файл вместо JSON.
 *
 * Это копия публичного описания API (`openapi.public.json`): тест `ContractTest` проверяет, что
 * таблица совпадает с ним операция в операцию и что у {@see Client} есть метод на каждую.
 *
 * @internal
 */
final class Operations
{
    /**
     * @var array<string, array{0: string, 1: string, query: list<string>, body: 'json'|'multipart'|'json+multipart'|null, idempotent: bool, download: bool}>
     */
    public const ALL = [
        'getStatus' => ['GET', '/v1/status', 'query' => [], 'body' => null, 'idempotent' => false, 'download' => false],
        'getOpenApiDocument' => ['GET', '/v1/openapi.json', 'query' => [], 'body' => null, 'idempotent' => false, 'download' => false],
        'getMe' => ['GET', '/v1/me', 'query' => [], 'body' => null, 'idempotent' => false, 'download' => false],
        'getOrganization' => ['GET', '/v1/organization', 'query' => [], 'body' => null, 'idempotent' => false, 'download' => false],
        'createFile' => ['POST', '/v1/files', 'query' => [], 'body' => 'multipart', 'idempotent' => true, 'download' => false],
        'getFile' => ['GET', '/v1/files/{id}', 'query' => [], 'body' => null, 'idempotent' => false, 'download' => false],
        'deleteFile' => ['DELETE', '/v1/files/{id}', 'query' => [], 'body' => null, 'idempotent' => false, 'download' => false],
        'getFileContent' => ['GET', '/v1/files/{id}/content', 'query' => [], 'body' => null, 'idempotent' => false, 'download' => true],
        'listTypizers' => ['GET', '/v1/typizers', 'query' => ['limit', 'starting_after'], 'body' => null, 'idempotent' => false, 'download' => false],
        'createTypizer' => ['POST', '/v1/typizers', 'query' => [], 'body' => 'json', 'idempotent' => true, 'download' => false],
        'generateTypizer' => ['POST', '/v1/typizers/generate', 'query' => [], 'body' => 'json', 'idempotent' => false, 'download' => false],
        'getTypizer' => ['GET', '/v1/typizers/{id}', 'query' => [], 'body' => null, 'idempotent' => false, 'download' => false],
        'updateTypizer' => ['PATCH', '/v1/typizers/{id}', 'query' => [], 'body' => 'json', 'idempotent' => false, 'download' => false],
        'deleteTypizer' => ['DELETE', '/v1/typizers/{id}', 'query' => [], 'body' => null, 'idempotent' => false, 'download' => false],
        'listTypizerVersions' => ['GET', '/v1/typizers/{id}/versions', 'query' => ['limit', 'starting_after'], 'body' => null, 'idempotent' => false, 'download' => false],
        'getTypizerVersion' => ['GET', '/v1/typizers/{id}/versions/{n}', 'query' => [], 'body' => null, 'idempotent' => false, 'download' => false],
        'getTypizerXsd' => ['GET', '/v1/typizers/{id}/xsd', 'query' => [], 'body' => null, 'idempotent' => false, 'download' => true],
        'getCatalogPublisher' => ['GET', '/v1/catalog/publishers/{slug}', 'query' => [], 'body' => null, 'idempotent' => false, 'download' => false],
        'listCatalog' => ['GET', '/v1/catalog', 'query' => ['type', 'q', 'category', 'kind', 'publisher', 'favorites', 'sort', 'limit', 'cursor'], 'body' => null, 'idempotent' => false, 'download' => false],
        'installCatalogEntry' => ['POST', '/v1/catalog/{entry}/install', 'query' => [], 'body' => 'json', 'idempotent' => true, 'download' => false],
        'forkCatalogEntry' => ['POST', '/v1/catalog/{entry}/fork', 'query' => ['type'], 'body' => 'json', 'idempotent' => true, 'download' => false],
        'favoriteCatalogEntry' => ['PUT', '/v1/catalog/{entry}/favorite', 'query' => ['type'], 'body' => null, 'idempotent' => false, 'download' => false],
        'unfavoriteCatalogEntry' => ['DELETE', '/v1/catalog/{entry}/favorite', 'query' => ['type'], 'body' => null, 'idempotent' => false, 'download' => false],
        'listCatalogVersions' => ['GET', '/v1/catalog/{entry}/versions', 'query' => ['type'], 'body' => null, 'idempotent' => false, 'download' => false],
        'reportCatalogEntry' => ['POST', '/v1/catalog/{entry}/reports', 'query' => ['type'], 'body' => 'json', 'idempotent' => true, 'download' => false],
        'getCatalogEntry' => ['GET', '/v1/catalog/{entry}', 'query' => ['version', 'type'], 'body' => null, 'idempotent' => false, 'download' => false],
        'getCommunityCatalogEntry' => ['GET', '/v1/catalog/{publisher}/{slug}', 'query' => ['version', 'type'], 'body' => null, 'idempotent' => false, 'download' => false],
        'getTypizerCatalogUpdate' => ['GET', '/v1/typizers/{id}/catalog_update', 'query' => ['version'], 'body' => null, 'idempotent' => false, 'download' => false],
        'applyTypizerCatalogUpdate' => ['POST', '/v1/typizers/{id}/catalog_update', 'query' => [], 'body' => 'json', 'idempotent' => true, 'download' => false],
        'listValidators' => ['GET', '/v1/validators', 'query' => ['limit', 'starting_after'], 'body' => null, 'idempotent' => false, 'download' => false],
        'createValidator' => ['POST', '/v1/validators', 'query' => [], 'body' => 'json', 'idempotent' => true, 'download' => false],
        'testValidator' => ['POST', '/v1/validators/test', 'query' => [], 'body' => 'json', 'idempotent' => true, 'download' => false],
        'suggestValidator' => ['POST', '/v1/validators/suggest', 'query' => [], 'body' => 'json', 'idempotent' => false, 'download' => false],
        'getValidatorBySlug' => ['GET', '/v1/validators/my/{slug}', 'query' => [], 'body' => null, 'idempotent' => false, 'download' => false],
        'getValidator' => ['GET', '/v1/validators/{id}', 'query' => [], 'body' => null, 'idempotent' => false, 'download' => false],
        'updateValidator' => ['PATCH', '/v1/validators/{id}', 'query' => [], 'body' => 'json', 'idempotent' => false, 'download' => false],
        'deleteValidator' => ['DELETE', '/v1/validators/{id}', 'query' => [], 'body' => null, 'idempotent' => false, 'download' => false],
        'createRun' => ['POST', '/v1/typizers/{id}/runs', 'query' => ['wait'], 'body' => 'json+multipart', 'idempotent' => true, 'download' => false],
        'listRuns' => ['GET', '/v1/runs', 'query' => ['limit', 'starting_after', 'status', 'typizer_id', 'batch_id', 'source', 'created_after', 'created_before'], 'body' => null, 'idempotent' => false, 'download' => false],
        'getRun' => ['GET', '/v1/runs/{id}', 'query' => [], 'body' => null, 'idempotent' => false, 'download' => false],
        'getRunOutput' => ['GET', '/v1/runs/{id}/output', 'query' => ['format'], 'body' => null, 'idempotent' => false, 'download' => true],
        'retryRun' => ['POST', '/v1/runs/{id}/retry', 'query' => [], 'body' => null, 'idempotent' => true, 'download' => false],
        'createBatch' => ['POST', '/v1/batches', 'query' => [], 'body' => 'json+multipart', 'idempotent' => true, 'download' => false],
        'listBatches' => ['GET', '/v1/batches', 'query' => ['limit', 'starting_after'], 'body' => null, 'idempotent' => false, 'download' => false],
        'getBatch' => ['GET', '/v1/batches/{id}', 'query' => [], 'body' => null, 'idempotent' => false, 'download' => false],
        'exportBatch' => ['GET', '/v1/batches/{id}/export', 'query' => ['format'], 'body' => null, 'idempotent' => false, 'download' => true],
        'extract' => ['POST', '/v1/extract', 'query' => ['wait'], 'body' => 'json+multipart', 'idempotent' => true, 'download' => false],
        'listWebhookEndpoints' => ['GET', '/v1/webhook_endpoints', 'query' => ['limit', 'starting_after'], 'body' => null, 'idempotent' => false, 'download' => false],
        'createWebhookEndpoint' => ['POST', '/v1/webhook_endpoints', 'query' => [], 'body' => 'json', 'idempotent' => true, 'download' => false],
        'getWebhookEndpoint' => ['GET', '/v1/webhook_endpoints/{id}', 'query' => [], 'body' => null, 'idempotent' => false, 'download' => false],
        'updateWebhookEndpoint' => ['PATCH', '/v1/webhook_endpoints/{id}', 'query' => [], 'body' => 'json', 'idempotent' => false, 'download' => false],
        'deleteWebhookEndpoint' => ['DELETE', '/v1/webhook_endpoints/{id}', 'query' => [], 'body' => null, 'idempotent' => false, 'download' => false],
        'testWebhookEndpoint' => ['POST', '/v1/webhook_endpoints/{id}/test', 'query' => [], 'body' => null, 'idempotent' => true, 'download' => false],
        'listWebhookDeliveries' => ['GET', '/v1/webhook_endpoints/{id}/deliveries', 'query' => ['limit', 'starting_after'], 'body' => null, 'idempotent' => false, 'download' => false],
        'retryWebhookDelivery' => ['POST', '/v1/webhook_deliveries/{id}/retry', 'query' => [], 'body' => null, 'idempotent' => true, 'download' => false],
        'getBalance' => ['GET', '/v1/balance', 'query' => [], 'body' => null, 'idempotent' => false, 'download' => false],
        'getUsage' => ['GET', '/v1/usage', 'query' => ['from', 'to', 'group_by'], 'body' => null, 'idempotent' => false, 'download' => false],
    ];
}
