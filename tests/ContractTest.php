<?php

declare(strict_types=1);

namespace Typizer\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionNamedType;
use Typizer\Client;
use Typizer\Download;
use Typizer\File;
use Typizer\Operations;
use Typizer\Tests\Support\MockTransport;

/**
 * The SDK against the public API description (apps/docs/openapi.public.json — the same document the
 * API reference on docs.typizer.ru is built from): every public operation has a row in
 * Operations::ALL with the same method, path, query parameters, body kinds, Idempotency-Key and
 * response kind; Client has exactly one method per operation, shaped after it; and calling each
 * method hits exactly that method + path. A new operation in the spec fails this test until the SDK
 * covers it.
 */
final class ContractTest extends TestCase
{
    /** Client's public methods that are not operations. */
    private const HELPERS = ['__construct', '__debugInfo', '__serialize', 'lastResponse', 'waitForRun'];

    /** @var array<string, mixed>|null */
    private static ?array $spec = null;

    /** @return array<string, mixed> */
    private static function spec(): array
    {
        if (self::$spec === null) {
            // the monorepo copy; in the public mirror, the snapshot scripts/export-mirror.sh puts in Fixtures
            $path = __DIR__ . '/../../../apps/docs/openapi.public.json';
            if (!is_file($path)) {
                $path = __DIR__ . '/Fixtures/openapi.public.json';
            }
            self::$spec = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        }

        return self::$spec;
    }

    /** @return array<string, array<string, mixed>> operationId => facts from the spec */
    private static function specOperations(): array
    {
        $spec = self::spec();
        $resolve = static function (array $param) use ($spec): array {
            if (isset($param['$ref'])) {
                $name = substr($param['$ref'], strrpos($param['$ref'], '/') + 1);

                return $spec['components']['parameters'][$name] + ['ref' => $name];
            }

            return $param;
        };
        $operations = [];
        foreach ($spec['paths'] as $path => $item) {
            foreach ($item as $method => $op) {
                if (!is_array($op) || !isset($op['operationId'])) {
                    continue;
                }
                $params = array_map($resolve, array_merge($item['parameters'] ?? [], $op['parameters'] ?? []));
                $okTypes = [];
                foreach ($op['responses'] as $status => $response) {
                    if ((int) $status >= 200 && (int) $status < 300) {
                        if (isset($response['$ref'])) {
                            $response = $spec['components']['responses'][substr($response['$ref'], strrpos($response['$ref'], '/') + 1)];
                        }
                        $okTypes = array_merge($okTypes, array_keys($response['content'] ?? []));
                    }
                }
                $contentTypes = array_keys($op['requestBody']['content'] ?? []);
                $operations[$op['operationId']] = [
                    'method' => strtoupper($method),
                    'path' => $path,
                    'pathParams' => array_values(array_map(static fn ($p) => $p['name'], array_filter($params, static fn ($p) => $p['in'] === 'path'))),
                    'query' => array_values(array_map(static fn ($p) => $p['name'], array_filter($params, static fn ($p) => $p['in'] === 'query'))),
                    'body' => $contentTypes === [] ? null : implode('+', array_map(static fn ($t) => $t === 'application/json' ? 'json' : 'multipart', $contentTypes)),
                    'bodyRequired' => (bool) ($op['requestBody']['required'] ?? false),
                    'idempotent' => in_array('IdempotencyKey', array_map(static fn ($p) => $p['ref'] ?? null, $params), true),
                    'download' => array_diff($okTypes, ['application/json']) !== [],
                ];
            }
        }

        return $operations;
    }

    public function testEveryPublicOperationIsInTheTableAndNothingElse(): void
    {
        $spec = array_keys(self::specOperations());
        $table = array_keys(Operations::ALL);
        sort($spec);
        sort($table);
        self::assertSame($spec, $table);
        self::assertCount(49, $table, 'the public API has 49 operations today; update this count with the table');
    }

    /** @return iterable<string, array{string}> */
    public static function operationIds(): iterable
    {
        foreach (array_keys(Operations::ALL) as $id) {
            yield $id => [$id];
        }
    }

    #[DataProvider('operationIds')]
    public function testTableRowMatchesTheSpec(string $id): void
    {
        $spec = self::specOperations()[$id] ?? null;
        self::assertNotNull($spec, "{$id} is not in openapi.public.json");
        $row = Operations::ALL[$id];
        self::assertSame($spec['method'], $row[0], "{$id}: method");
        self::assertSame($spec['path'], $row[1], "{$id}: path");
        self::assertSame($spec['query'], $row['query'], "{$id}: query parameters");
        self::assertSame($spec['body'], $row['body'], "{$id}: request body kinds");
        self::assertSame($spec['idempotent'], $row['idempotent'], "{$id}: Idempotency-Key");
        self::assertSame($spec['download'], $row['download'], "{$id}: file response");
    }

    #[DataProvider('operationIds')]
    public function testClientMethodIsShapedAfterTheOperation(string $id): void
    {
        $spec = self::specOperations()[$id];
        self::assertTrue(method_exists(Client::class, $id), "Client::{$id}() is missing");
        $method = new ReflectionMethod(Client::class, $id);
        self::assertTrue($method->isPublic());
        $params = $method->getParameters();
        $names = array_map(static fn ($p) => $p->getName(), $params);

        // path parameters first, in path order, required
        foreach ($spec['pathParams'] as $i => $name) {
            self::assertSame($name, $names[$i] ?? null, "{$id}: argument #{$i} is the path parameter {$name}");
            self::assertFalse($params[$i]->isOptional(), "{$id}: \${$name} is required");
        }

        // body: $body (or $file for createFile), optional exactly when the spec's body is
        if ($spec['body'] === null) {
            self::assertNotContains('body', $names, "{$id} has no request body");
        } elseif ($id === 'createFile') {
            self::assertSame('file', $names[0]);
            self::assertSame(File::class, (string) $params[0]->getType());
        } else {
            self::assertContains('body', $names, "{$id}: \$body");
            $body = $params[array_search('body', $names, true)];
            self::assertSame(!$spec['bodyRequired'], $body->isOptional(), "{$id}: \$body optional = body not required");
        }

        // query: a dedicated argument per parameter (wait, format) or a $query array
        $dedicated = array_values(array_intersect($spec['query'], $names));
        if ($spec['query'] !== [] && $dedicated !== $spec['query']) {
            self::assertContains('query', $names, "{$id}: query parameters need \$query");
        }
        if ($spec['query'] === []) {
            self::assertNotContains('query', $names);
        }

        self::assertSame('options', end($names), "{$id}: options come last");

        $return = $method->getReturnType();
        self::assertInstanceOf(ReflectionNamedType::class, $return);
        self::assertSame($spec['download'] ? Download::class : 'array', $return->getName(), "{$id}: return type");
    }

    public function testClientHasNoMethodsBeyondOperationsAndHelpers(): void
    {
        $public = array_map(
            static fn (ReflectionMethod $m) => $m->getName(),
            (new \ReflectionClass(Client::class))->getMethods(ReflectionMethod::IS_PUBLIC),
        );
        $extra = array_diff($public, array_keys(Operations::ALL), self::HELPERS);
        self::assertSame([], array_values($extra));
    }

    #[DataProvider('operationIds')]
    public function testCallingTheMethodHitsTheOperation(string $id): void
    {
        $spec = self::specOperations()[$id];
        $transport = new MockTransport();
        $client = new Client('tz_live_contract', 'https://api.test', transport: $transport);

        $args = [];
        $expectedPath = $spec['path'];
        foreach ((new ReflectionMethod(Client::class, $id))->getParameters() as $param) {
            $name = $param->getName();
            if (in_array($name, $spec['pathParams'], true)) {
                $value = $name === 'n' ? 3 : "{$name}_x/y";
                $args[$name] = $value;
                $expectedPath = str_replace('{' . $name . '}', rawurlencode((string) $value), $expectedPath);
            } elseif ($name === 'file') {
                $args[$name] = File::fromString('%PDF-1.4', 'a.pdf');
            } elseif ($name === 'body') {
                $args[$name] = ['name' => 'x'];
            } elseif ($name === 'format') {
                $args[$name] = 'csv';
            }
        }

        $client->{$id}(...$args);

        $request = $transport->last();
        self::assertSame($spec['method'], $request->method);
        self::assertSame('https://api.test' . $expectedPath, explode('?', $request->url)[0]);
        self::assertSame('Bearer tz_live_contract', $request->headers['Authorization']);
        self::assertSame($spec['idempotent'], isset($request->headers['Idempotency-Key']), "{$id}: Idempotency-Key sent");
    }
}
