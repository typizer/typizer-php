<?php

declare(strict_types=1);

/*
 * Router for `php -S` in the tests (tests/Support/Server.php). It runs with
 * enable_post_data_reading=0, so php://input is always the raw body — multipart included.
 *
 *   /__echo…        echoes the raw request back as JSON (transport tests)
 *   /__sleep        answers after 3 s (timeout tests)
 *   /app/<file>     runs <file> from APP_DIR as a request handler (README's webhook handlers)
 *   /rest/…         a fake Bitrix24 portal (incoming webhook REST + a file download)
 *   /files/…        a document to download
 *   /v1/…           a fake Typizer API
 *
 * Every request is appended to FAKE_LOG as one JSON line (body base64-encoded).
 */

$method = $_SERVER['REQUEST_METHOD'];
$uri = $_SERVER['REQUEST_URI'];
$path = rawurldecode((string) parse_url($uri, PHP_URL_PATH));
$query = (string) parse_url($uri, PHP_URL_QUERY);
$headers = array_change_key_case(getallheaders(), CASE_LOWER);
$body = (string) file_get_contents('php://input');

file_put_contents((string) getenv('FAKE_LOG'), json_encode([
    'method' => $method,
    'path' => $path,
    'query' => $query,
    'headers' => $headers,
    'body' => base64_encode($body),
], JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);

$json = static function (int $status, array $data, array $extra = []): void {
    http_response_code($status);
    header('Content-Type: ' . ($status >= 400 ? 'application/problem+json' : 'application/json'));
    header('X-Request-Id: req_fake');
    foreach ($extra as $name => $value) {
        header("{$name}: {$value}");
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
};
$problem = static function (int $status, string $code, string $detail) use ($json): void {
    $json($status, [
        'type' => "https://docs.typizer.ru/errors/{$code}",
        'title' => $code,
        'status' => $status,
        'detail' => $detail,
        'code' => $code,
        'request_id' => 'req_fake',
        'doc_url' => "https://docs.typizer.ru/errors/{$code}",
    ]);
};

if (str_starts_with($path, '/__echo')) {
    parse_str($query, $params);
    $extra = ['X-Echo' => 'yes'];
    $json(isset($params['status']) ? (int) $params['status'] : 200, [
        'method' => $method,
        'path' => $path,
        'query' => $query,
        'headers' => $headers,
        'body' => base64_encode($body),
    ], $extra);

    return true;
}

if ($path === '/__sleep') {
    sleep(3);
    $json(200, ['slept' => true]);

    return true;
}

if (str_starts_with($path, '/app/')) {
    $file = rtrim((string) getenv('APP_DIR'), '/') . '/' . basename($path);
    if (str_starts_with($headers['content-type'] ?? '', 'application/x-www-form-urlencoded')) {
        parse_str($body, $_POST);
    }
    chdir(dirname($file));
    require $file;

    return true;
}

$fakeUrl = (string) getenv('FAKE_URL');

if (str_starts_with($path, '/rest/1/secret/')) {
    $restMethod = basename($path, '.json');
    parse_str($body, $params);
    if ($restMethod === 'crm.item.get') {
        echo json_encode(['result' => ['item' => [
            'id' => (int) $params['id'],
            'title' => 'Сделка',
            'ufCrm_1700000000' => [
                'id' => 777,
                'url' => "{$fakeUrl}/bitrix/services/main/ajax.php?fileId=777",
                'urlMachine' => "{$fakeUrl}/files/invoice.pdf?token=fake",
            ],
            'ufCrm_1700000001' => '',
        ]]]);
    } else {
        echo json_encode(['result' => ['item' => ['id' => (int) ($params['id'] ?? 0)]]]);
    }

    return true;
}

if (str_starts_with($path, '/files/')) {
    header('Content-Type: application/pdf');
    echo "%PDF-1.4\n% fake invoice\n";

    return true;
}

$run = static fn (string $status, ?array $output = null): array => [
    'id' => 'run_fake',
    'object' => 'run',
    'status' => $status,
    'typizer_id' => null,
    'metadata' => [],
    'output' => $output,
    'error' => null,
    'created_at' => '2026-11-13T00:00:00Z',
    'completed_at' => $status === 'succeeded' ? '2026-11-13T00:00:05Z' : null,
];
$output = ['invoice_number' => '42', 'number' => '42', 'total' => 1000.5, 'inn' => '7707083893'];

switch (true) {
    case $method === 'GET' && $path === '/v1/me':
        $json(200, ['object' => 'me', 'auth' => 'api_key', 'organization' => ['id' => 'org_fake', 'name' => 'ООО Тест']]);
        break;
    case $method === 'GET' && $path === '/v1/balance':
        $json(200, ['object' => 'balance', 'pages' => 100]);
        break;
    case $method === 'POST' && $path === '/v1/extract':
        parse_str($query, $params);
        if ((int) ($params['wait'] ?? 0) > 0) {
            $json(200, $run('succeeded', $output));
        } else {
            $json(202, $run('queued'));
        }
        break;
    case $method === 'POST' && $path === '/v1/typizers':
        $json(201, ['id' => 'tz_fake', 'object' => 'typizer', 'name' => 'Счета поставщиков', 'version' => 1]);
        break;
    case $method === 'POST' && $path === '/v1/typizers/tz_…/runs':
        $problem(402, 'insufficient_balance', 'Not enough pages');
        break;
    case $method === 'POST' && preg_match('#^/v1/typizers/[^/]+/runs$#', $path) === 1:
        $json(200, $run('succeeded', $output));
        break;
    case $method === 'GET' && preg_match('#^/v1/runs/[^/]+/output$#', $path) === 1:
        http_response_code(200);
        header('Content-Type: application/xml');
        header('Content-Disposition: attachment; filename="run_fake.xml"');
        echo '<?xml version="1.0" encoding="UTF-8"?><invoice><invoice_number>42</invoice_number></invoice>';
        break;
    case $method === 'GET' && preg_match('#^/v1/runs/[^/]+$#', $path) === 1:
        $json(200, $run('succeeded', $output));
        break;
    case $method === 'POST' && $path === '/v1/files':
        $json(201, ['id' => 'file_fake', 'object' => 'file']);
        break;
    case $method === 'POST' && $path === '/v1/webhook_endpoints':
        $json(201, ['id' => 'whe_fake', 'object' => 'webhook_endpoint', 'secret' => 'whsec_dGVzdA==', 'status' => 'active']);
        break;
    case $method === 'POST' && preg_match('#^/v1/webhook_endpoints/[^/]+/test$#', $path) === 1:
        $json(201, ['id' => 'whd_fake', 'object' => 'webhook_delivery']);
        break;
    default:
        $problem(404, 'not_found', 'Not found');
}

return true;
