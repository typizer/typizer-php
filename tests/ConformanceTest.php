<?php

declare(strict_types=1);

namespace Typizer\Tests;

use PHPUnit\Framework\TestCase;
use Typizer\Client;
use Typizer\Exception\ConflictException;
use Typizer\Exception\InvalidRequestException;
use Typizer\Run;

/**
 * Conformance checks from packages/sdk-spec/conformance/SCENARIOS.md against the shared mock
 * (`node packages/sdk-spec/conformance/server.js`). scripts/test.sh starts the mock next to PHPUnit
 * and passes TYPIZER_MOCK_URL; without it these tests are skipped (TYPIZER_CONFORMANCE=1 turns the
 * skip into a failure). The scenario is the API key: `tz_test_<scenario>__<case>`; every test
 * resets its key's state first, so reruns against the same mock (one per PHP version) agree.
 *
 * Covered here: field checks and validators, SC-36…SC-39 (SPEC 1.2). The other SC-xx are covered by
 * ContractTest, ClientTest, TransportTest, WebhookTest against recorded/echo transports.
 */
final class ConformanceTest extends TestCase
{
    private const INLINE_CHECK = [
        'kind' => 'rules',
        'applies_to' => 'string',
        'rules' => [['type' => 'pattern', 'pattern' => 'СП-\d{4}']],
        'message' => 'Номер спецификации не по шаблону СП-NNNN',
    ];

    private static function mockUrl(): string
    {
        $url = getenv('TYPIZER_MOCK_URL');
        if (!is_string($url) || $url === '') {
            if (getenv('TYPIZER_CONFORMANCE') === '1') {
                self::fail('TYPIZER_CONFORMANCE=1, but TYPIZER_MOCK_URL is not set');
            }
            self::markTestSkipped('TYPIZER_MOCK_URL is not set (scripts/test.sh starts the conformance mock)');
        }

        return rtrim($url, '/');
    }

    private static function client(string $key): Client
    {
        $base = self::mockUrl();
        self::mock('POST', '/_mock/reset?key=' . rawurlencode($key));

        return new Client($key, $base, maxRetries: 0);
    }

    /** @return array<string, mixed> */
    private static function mock(string $method, string $path): array
    {
        // the mock container may still be starting when the first test runs
        for ($attempt = 0; ; $attempt++) {
            $handle = curl_init(self::mockUrl() . $path);
            curl_setopt_array($handle, [
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
            ]);
            $body = curl_exec($handle);
            if ($body !== false || $attempt >= 50) {
                break;
            }
            usleep(200_000);
        }
        $body = (string) $body;
        self::assertSame(200, curl_getinfo($handle, CURLINFO_RESPONSE_CODE), "{$method} {$path}: {$body}");

        return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return list<array<string, mixed>> the mock's journal for one key (and operation) */
    private static function journal(string $key, ?string $operation = null): array
    {
        $query = 'key=' . rawurlencode($key) . ($operation === null ? '' : '&operation_id=' . rawurlencode($operation));

        return self::mock('GET', '/_mock/requests?' . $query)['data'];
    }

    public function testSc36FieldChecksWarningsAndChecksUsed(): void
    {
        $key = 'tz_test_checks__sc36';
        $typizer = self::client($key);
        $schema = [
            'type' => 'object',
            'properties' => [
                'contract_no' => ['type' => 'string', 'x-typizer-check' => 'my/contract-no@1'],
                'spec_no' => ['type' => 'string', 'x-typizer-check' => self::INLINE_CHECK],
                'seller' => ['type' => 'object', 'properties' => ['inn' => ['type' => 'string', 'x-typizer-check' => 'inn']]],
            ],
        ];

        $run = $typizer->extract(['url' => 'https://typizer.ru/samples/invoice.pdf', 'schema' => $schema], wait: 60);
        self::assertSame('succeeded', $run['status']);

        $contract = $run['fields']['contract_no'];
        self::assertSame('warn', $contract['status']);
        self::assertSame('pattern_mismatch', $contract['reason']);
        self::assertSame('Номер договора не по шаблону ДГ-ГГГГ/NNNN', $contract['message']);
        $check = $contract['checks'][0];
        self::assertSame('my/contract-no', $check['ref']);
        self::assertSame('1.0.0', $check['version']);
        self::assertSame('Номер договора', $check['name']);
        self::assertSame('fail', $check['status']);
        self::assertSame('pattern', $check['rule']);
        self::assertSame('pattern_mismatch', $check['reason']);

        $inn = $run['fields']['seller.inn'];
        self::assertSame('ok', $inn['status']);
        self::assertSame('inn', $inn['checks'][0]['ref']);
        self::assertSame('pass', $inn['checks'][0]['status']);

        $warnings = Run::warnings($run);
        self::assertSame(['contract_no', 'spec_no'], array_column($warnings, 'path'));
        self::assertSame('Номер договора не по шаблону ДГ-ГГГГ/NNNN', $warnings[0]['message']);
        self::assertSame('pattern_mismatch', $warnings[0]['reason']);
        self::assertSame(self::INLINE_CHECK['message'], $warnings[1]['message']);
        self::assertSame('pattern_mismatch', $warnings[1]['reason']);

        self::assertCount(2, $run['checks_used']);
        [$own, $inline] = $run['checks_used'];
        self::assertStringStartsWith('my/contract-no', $own['ref']);
        self::assertSame('1.0.0', $own['version']);
        self::assertSame('Номер договора', $own['name']);
        self::assertSame('inline', $inline['ref']);
        self::assertNull($inline['version']);
        self::assertNull($inline['name']);
        self::assertSame('spec_no', $inline['field']);

        [$entry] = self::journal($key, 'extract');
        self::assertSame('json', $entry['body']['type']);
        self::assertSame(self::INLINE_CHECK, $entry['body']['value']['schema']['properties']['spec_no']['x-typizer-check'], 'the inline check is sent as is');
    }

    public function testSc37OwnValidatorsCrud(): void
    {
        $key = 'tz_test_ok__sc37';
        $typizer = self::client($key);

        $refs = array_column(iterator_to_array($typizer->validators->list(), false), 'ref');
        self::assertContains('my/contract-no', $refs);

        $bySlug = $typizer->validators->get('my/contract-no');
        self::assertSame('/v1/validators/my/contract-no', self::journal($key, 'getValidatorBySlug')[0]['path']);
        self::assertSame('my/contract-no', $bySlug['ref']);
        self::assertIsArray($bySlug['versions']);
        self::assertIsArray($bySlug['used_by']);

        $byId = $typizer->validators->get($bySlug['id']);
        self::assertSame('/v1/validators/' . $bySlug['id'], self::journal($key, 'getValidator')[0]['path']);
        self::assertSame($bySlug['id'], $byId['id']);
        self::assertNotEmpty($byId['versions']);
        self::assertIsArray($byId['used_by']);
        self::assertSame(array_column($byId['versions'], 'version'), array_column($typizer->validators->versions('my/contract-no@1'), 'version'));

        $spec = ['kind' => 'rules', 'applies_to' => 'string', 'rules' => [['type' => 'pattern', 'pattern' => 'СЧ-\d{2,6}']], 'message' => 'Номер счёта не по шаблону СЧ-NN'];
        $created = $typizer->validators->create('Номер счёта', $spec, tests: [
            ['value' => 'СЧ-17', 'expect' => 'pass'],
            ['value' => 'АБ-1', 'expect' => 'fail'],
        ], slug: 'invoice-no');
        self::assertSame('my/invoice-no', $created['ref']);
        self::assertSame('1.0.0', $created['version']['version']);
        self::assertSame($spec, $created['version']['spec'], 'spec comes back as a plain array');

        $updated = $typizer->validators->update($created['id'], tests: [
            ['value' => 'СЧ-17', 'expect' => 'pass'],
            ['value' => 'АБ-1', 'expect' => 'fail'],
            ['value' => 'СЧ-2026', 'expect' => 'pass'],
        ]);
        self::assertSame('validator_update', $updated['object']);
        self::assertSame('minor', $updated['bump']);
        self::assertSame('1.1.0', $updated['validator']['version']['version']);

        try {
            $typizer->validators->create('Красный', $spec, tests: [['value' => 'АБ-1', 'expect' => 'pass']], slug: 'red-one');
            self::fail('a red test must fail the create');
        } catch (InvalidRequestException $e) {
            self::assertSame(400, $e->httpStatus);
            self::assertSame('tests/0', $e->param);
        }

        try {
            $typizer->validators->create('Дубль', $spec, slug: 'contract-no');
            self::fail('a taken slug must conflict');
        } catch (ConflictException $e) {
            self::assertSame(409, $e->httpStatus);
        }

        $typizer->createTypizer(['name' => 'Договоры', 'schema' => [
            'type' => 'object',
            'properties' => ['contract_no' => ['type' => 'string', 'x-typizer-check' => 'my/contract-no@1']],
        ]]);
        try {
            $typizer->validators->archive($bySlug['id']);
            self::fail('a validator a typizer uses must not be archived');
        } catch (ConflictException $e) {
            self::assertSame('used_by', $e->param);
        }

        $archived = $typizer->validators->archive($created['id']);
        self::assertSame($created['id'], $archived['id']);
        self::assertTrue($archived['deleted']);
        self::assertSame('DELETE', self::journal($key, 'deleteValidator')[1]['method']);
    }

    public function testSc38TestValuesByRefAndSpec(): void
    {
        $key = 'tz_test_ok__sc38';
        $typizer = self::client($key);

        $byRef = $typizer->validators->test(['ДГ-2026/0153', 'ДГ-26/153'], ref: 'my/contract-no@1');
        self::assertSame('validator_test', $byRef['object']);
        self::assertIsString($byRef['explain']);
        [$ok, $bad] = $byRef['results'];
        self::assertSame(['value' => 'ДГ-2026/0153', 'pass' => true, 'reason' => null, 'message' => null, 'rule_index' => null], $ok);
        self::assertSame('ДГ-26/153', $bad['value']);
        self::assertFalse($bad['pass']);
        self::assertSame('pattern_mismatch', $bad['reason']);
        self::assertSame('Номер договора не по шаблону ДГ-ГГГГ/NNNN', $bad['message']);
        self::assertSame(0, $bad['rule_index']);

        $bySpec = $typizer->validators->test(['7707083893', '7707083890'], spec: ['kind' => 'native', 'id' => 'inn']);
        self::assertTrue($bySpec['results'][0]['pass']);
        self::assertFalse($bySpec['results'][1]['pass']);
        self::assertSame('checksum_mismatch', $bySpec['results'][1]['reason']);
        self::assertIsString($bySpec['results'][1]['message']);
        self::assertIsInt($bySpec['results'][1]['rule_index']);

        foreach ([[null, null], ['inn@1', ['kind' => 'native', 'id' => 'inn']]] as [$ref, $spec]) {
            try {
                $typizer->validators->test(['1'], ref: $ref, spec: $spec);
                self::fail('ref and spec: exactly one');
            } catch (\InvalidArgumentException) {
                // before the network
            }
        }
        self::assertCount(2, self::journal($key, 'testValidator'), 'the argument errors sent nothing');

        try {
            $typizer->validators->test(['1'], ref: 'my/no-such-check@1');
            self::fail('an unknown ref must fail');
        } catch (InvalidRequestException $e) {
            self::assertSame('ref', $e->param);
        }
    }

    public function testSc39Suggest(): void
    {
        $key = 'tz_test_ok__sc39';
        $typizer = self::client($key);

        $suggestion = $typizer->validators->suggest(
            examples: ['pass' => ['ДГ-2026/0153', 'ДГ-2025/0001'], 'fail' => ['ДГ-26/153']],
            field: ['name' => 'Номер договора'],
        );
        self::assertSame('validator_suggestion', $suggestion['object']);
        self::assertIsArray($suggestion['spec']);
        self::assertArrayHasKey('kind', $suggestion['spec']);
        self::assertNotEmpty($suggestion['tests']);
        foreach ($suggestion['tests'] as $test) {
            self::assertContains($test['expect'], ['pass', 'fail']);
            self::assertContains($test['got'], ['pass', 'fail']);
        }
        self::assertIsBool($suggestion['converged']);
        self::assertIsString($suggestion['explain']);
        self::assertIsString($suggestion['source']);
        self::assertIsArray($suggestion['candidates_from_catalog']);
        self::assertContains('my/contract-no@1', array_column($suggestion['candidates_from_catalog'], 'ref'));

        try {
            $typizer->validators->suggest(field: ['name' => 'Номер договора']);
            self::fail('no description and no examples must fail');
        } catch (InvalidRequestException $e) {
            self::assertSame(400, $e->httpStatus);
        }
    }
}
