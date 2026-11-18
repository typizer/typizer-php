<?php

declare(strict_types=1);

namespace Typizer\Tests;

use PHPUnit\Framework\TestCase;
use Typizer\Client;
use Typizer\Exception\ConflictException;
use Typizer\Exception\InvalidRequestException;
use Typizer\Run;
use Typizer\Tests\Support\MockTransport;

/**
 * `$client->validators` (SPEC §3, rows validators.*) and `Run::warnings()` (SPEC §5.2) against a
 * recorded transport: routing, request bodies, argument errors before the network, auto-paging and
 * pass-through of field checks, checks_used and fields/enum values the SDK does not know yet.
 */
final class ValidatorsTest extends TestCase
{
    private MockTransport $transport;

    protected function setUp(): void
    {
        $this->transport = new MockTransport();
    }

    private function client(): Client
    {
        return new Client('tz_live_validators', 'https://api.test', transport: $this->transport, sleep: static function (float $s): void {
        });
    }

    /** @return array<string, mixed> */
    private function sentJson(): array
    {
        return json_decode((string) $this->transport->last()->body, true, 512, JSON_THROW_ON_ERROR);
    }

    public function testGetRoutesMySlugsAndIds(): void
    {
        $client = $this->client();
        $client->validators->get('my/contract-no');
        self::assertSame('GET https://api.test/v1/validators/my/contract-no', $this->transport->last()->method . ' ' . $this->transport->last()->url);
        $client->validators->get('my/contract-no@1');
        self::assertSame('https://api.test/v1/validators/my/contract-no', $this->transport->last()->url, 'the version is dropped');
        $client->validators->get('val_123');
        self::assertSame('https://api.test/v1/validators/val_123', $this->transport->last()->url);
    }

    public function testVersionsComeFromGet(): void
    {
        $this->transport->push(MockTransport::json(200, ['id' => 'val_1', 'versions' => [['version' => '1.1.0'], ['version' => '1.0.0']], 'used_by' => []]));
        $versions = $this->client()->validators->versions('my/contract-no@1');
        self::assertSame(['1.1.0', '1.0.0'], array_column($versions, 'version'));
        self::assertSame('https://api.test/v1/validators/my/contract-no', $this->transport->last()->url);
    }

    public function testForkCopiesACatalogValidatorWithTypeValidator(): void
    {
        $client = $this->client();
        $client->validators->fork('iban@1', slug: 'my-iban');
        $request = $this->transport->last();
        self::assertSame('POST https://api.test/v1/catalog/iban/fork?type=validator', $request->method . ' ' . $request->url);
        self::assertSame(['version' => '1', 'slug' => 'my-iban'], $this->sentJson());

        // A community validator is looked up first (its id), as a validator.
        $this->transport->push(MockTransport::json(200, ['id' => 'cat_7', 'type' => 'validator']));
        $client->validators->fork('acme/contract-no');
        $requests = $this->transport->requests;
        self::assertSame('https://api.test/v1/catalog/acme/contract-no?type=validator', $requests[count($requests) - 2]->url);
        self::assertSame('https://api.test/v1/catalog/cat_7/fork?type=validator', $this->transport->last()->url);
        self::assertSame([], $this->sentJson());

        // The schema catalog's calls send no type unless asked.
        $client->favoriteCatalogEntry('upd');
        self::assertSame('https://api.test/v1/catalog/upd/favorite', $this->transport->last()->url);
        $client->favoriteCatalogEntry('inn', type: 'validator');
        self::assertSame('https://api.test/v1/catalog/inn/favorite?type=validator', $this->transport->last()->url);
    }

    public function testCreateSendsOnlyWhatWasGivenAndSpecAsIs(): void
    {
        $spec = ['kind' => 'rules', 'applies_to' => 'string', 'rules' => [['type' => 'pattern', 'pattern' => 'ДГ-\d{4}/\d{4}']], 'message' => 'Не по шаблону', 'future_key' => ['x' => 1]];
        $this->client()->validators->create('Номер договора', $spec, tests: [['value' => 'ДГ-2026/0153', 'expect' => 'pass']]);
        $request = $this->transport->last();
        self::assertSame('POST', $request->method);
        self::assertSame('https://api.test/v1/validators', $request->url);
        self::assertArrayHasKey('Idempotency-Key', $request->headers);
        self::assertSame(['name' => 'Номер договора', 'spec' => $spec, 'tests' => [['value' => 'ДГ-2026/0153', 'expect' => 'pass']]], $this->sentJson());
    }

    public function testUpdateArchiveAndSuggest(): void
    {
        $client = $this->client();
        $client->validators->update('val_1', name: 'Новое имя');
        self::assertSame('PATCH', $this->transport->last()->method);
        self::assertSame('https://api.test/v1/validators/val_1', $this->transport->last()->url);
        self::assertSame('{"name":"Новое имя"}', $this->transport->last()->body);
        self::assertArrayNotHasKey('Idempotency-Key', $this->transport->last()->headers);

        $client->validators->archive('val_1');
        self::assertSame('DELETE', $this->transport->last()->method);
        self::assertSame('https://api.test/v1/validators/val_1', $this->transport->last()->url);

        $client->validators->suggest(examples: ['pass' => ['ДГ-2026/0153'], 'fail' => []], field: ['name' => 'Номер договора']);
        self::assertSame('https://api.test/v1/validators/suggest', $this->transport->last()->url);
        self::assertSame('{"examples":{"pass":["ДГ-2026/0153"],"fail":[]},"field":{"name":"Номер договора"}}', $this->transport->last()->body);
        self::assertArrayNotHasKey('Idempotency-Key', $this->transport->last()->headers, 'suggest ignores Idempotency-Key');

        $client->validators->suggest();
        self::assertSame('{}', $this->transport->last()->body);
    }

    public function testTestNeedsExactlyOneOfRefAndSpecBeforeTheNetwork(): void
    {
        $client = $this->client();
        foreach ([
            static fn () => $client->validators->test(['1']),
            static fn () => $client->validators->test(['1'], ref: 'inn@1', spec: ['kind' => 'native', 'id' => 'inn']),
            static fn () => $client->validators->test([], ref: 'inn@1'),
            static fn () => $client->validators->test(array_fill(0, 101, '1'), ref: 'inn@1'),
            static fn () => $client->validators->test(['a' => '1'], ref: 'inn@1'),
            static fn () => $client->validators->test([['nested']], ref: 'inn@1'),
        ] as $i => $call) {
            try {
                $call();
                self::fail("case {$i}: no exception");
            } catch (\InvalidArgumentException) {
                self::assertSame([], $this->transport->requests, "case {$i}: nothing sent");
            }
        }

        $client->validators->test(['7707083893', 7707083893], spec: ['kind' => 'native', 'id' => 'inn']);
        self::assertSame('https://api.test/v1/validators/test', $this->transport->last()->url);
        self::assertSame('{"spec":{"kind":"native","id":"inn"},"values":["7707083893",7707083893]}', $this->transport->last()->body);
        self::assertArrayHasKey('Idempotency-Key', $this->transport->last()->headers);
    }

    public function testListPagesLazilyWithStartingAfter(): void
    {
        $this->transport->push(
            MockTransport::json(200, ['object' => 'list', 'data' => [['id' => 'val_3'], ['id' => 'val_2']], 'has_more' => true, 'next_cursor' => 'val_2']),
            MockTransport::json(200, ['object' => 'list', 'data' => [['id' => 'val_1']], 'has_more' => false, 'next_cursor' => null]),
        );
        $list = $this->client()->validators->list(limit: 2);
        self::assertSame([], $this->transport->requests, 'nothing is fetched before iterating');

        self::assertSame(['val_3', 'val_2', 'val_1'], array_column(iterator_to_array($list, false), 'id'));
        self::assertSame('https://api.test/v1/validators?limit=2', $this->transport->requests[0]->url);
        self::assertSame('https://api.test/v1/validators?limit=2&starting_after=val_2', $this->transport->requests[1]->url);
    }

    public function testErrorsKeepParam(): void
    {
        $this->transport->push(
            MockTransport::json(400, ['code' => 'invalid_request', 'status' => 400, 'detail' => 'Тест 1 не прошёл', 'param' => 'tests/0']),
            MockTransport::json(409, ['code' => 'conflict', 'status' => 409, 'detail' => 'Используется', 'param' => 'used_by']),
        );
        try {
            $this->client()->validators->create('X', ['kind' => 'rules'], tests: [['value' => 'a', 'expect' => 'pass']]);
            self::fail('no exception');
        } catch (InvalidRequestException $e) {
            self::assertSame('tests/0', $e->param);
        }
        try {
            $this->client()->validators->archive('val_1');
            self::fail('no exception');
        } catch (ConflictException $e) {
            self::assertSame('used_by', $e->param);
        }
    }

    public function testWarningsFollowFieldsOrderAndSkipMissing(): void
    {
        $run = [
            'id' => 'run_1',
            'status' => 'succeeded',
            'fields' => [
                'contract_no' => [
                    'status' => 'warn',
                    'reason' => 'pattern_mismatch',
                    'message' => 'Номер договора не по шаблону ДГ-ГГГГ/NNNN',
                    'checks' => [['ref' => 'my/contract-no', 'version' => '1.0.0', 'name' => 'Номер договора', 'status' => 'fail', 'rule' => 'pattern', 'reason' => 'pattern_mismatch']],
                    'sources' => [],
                ],
                'seller.inn' => ['status' => 'ok', 'checks' => [['ref' => 'inn', 'status' => 'pass']], 'sources' => []],
                'date' => ['status' => 'missing', 'sources' => []],
                'old_field' => ['status' => 'warn', 'reason' => 'checksum_mismatch', 'sources' => []],
                'future' => ['status' => 'warn', 'reason' => 'brand_new_reason', 'message' => 'Новое', 'checks' => [['ref' => 'x', 'status' => 'brand_new_status', 'extra' => 1]], 'sources' => []],
            ],
            'checks_used' => [['ref' => 'my/contract-no@1', 'version' => '1.0.0', 'name' => 'Номер договора'], ['ref' => 'inline', 'version' => null, 'name' => null, 'field' => 'spec_no']],
        ];

        self::assertSame([
            ['path' => 'contract_no', 'message' => 'Номер договора не по шаблону ДГ-ГГГГ/NNNN', 'reason' => 'pattern_mismatch'],
            ['path' => 'old_field', 'message' => null, 'reason' => 'checksum_mismatch'],
            ['path' => 'future', 'message' => 'Новое', 'reason' => 'brand_new_reason'],
        ], Run::warnings($run));

        self::assertSame([], Run::warnings(['id' => 'run_2', 'status' => 'processing', 'fields' => null]));
        self::assertSame([], Run::warnings(['id' => 'run_3']));
    }

    public function testRunsPassFieldChecksAndChecksUsedThrough(): void
    {
        $run = [
            'id' => 'run_1',
            'object' => 'run',
            'status' => 'succeeded',
            'fields' => ['contract_no' => ['status' => 'warn', 'reason' => 'pattern_mismatch', 'message' => 'М', 'checks' => [['ref' => 'my/contract-no', 'status' => 'skipped', 'unknown' => true]], 'sources' => []]],
            'checks_used' => [['ref' => 'inline', 'version' => null, 'name' => null, 'field' => 'spec_no']],
            'brand_new_field' => ['a' => 1],
        ];
        $this->transport->push(MockTransport::json(200, $run));
        self::assertSame($run, $this->client()->getRun('run_1'), 'the run array is the API response as is');
    }
}
