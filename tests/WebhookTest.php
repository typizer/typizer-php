<?php

declare(strict_types=1);

namespace Typizer\Tests;

use PHPUnit\Framework\TestCase;
use Typizer\Exception\WebhookVerificationException;
use Typizer\Webhook;

/**
 * Webhook verification against the docs' test vector (apps/docs/test/fixtures/webhook-vector.json:
 * signed by the API's own signing code, the same vector the docs' Node, Python and PHP snippets
 * are tested with).
 */
final class WebhookTest extends TestCase
{
    /** @var array{secret: string, headers: array<string, string>, body: string, ts: int} */
    private array $vector;

    protected function setUp(): void
    {
        // the monorepo copy; in the public mirror, the snapshot scripts/export-mirror.sh puts in Fixtures
        $path = __DIR__ . '/../../../apps/docs/test/fixtures/webhook-vector.json';
        if (!is_file($path)) {
            $path = __DIR__ . '/Fixtures/webhook-vector.json';
        }
        $this->vector = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    public function testTheVectorVerifies(): void
    {
        ['secret' => $secret, 'headers' => $headers, 'body' => $body, 'ts' => $ts] = $this->vector;
        self::assertTrue(Webhook::verify($secret, $headers, $body, $ts));
        self::assertTrue(Webhook::verify($secret, $headers, $body, $ts + Webhook::TOLERANCE_SECONDS));
        self::assertTrue(Webhook::verify($secret, $headers, $body, $ts - Webhook::TOLERANCE_SECONDS));
    }

    public function testHeaderNamesInAnyCaseAndArrayValues(): void
    {
        ['secret' => $secret, 'headers' => $headers, 'body' => $body, 'ts' => $ts] = $this->vector;
        $fpm = [];
        $laravel = [];
        foreach ($headers as $name => $value) {
            $fpm[ucwords($name, '-')] = $value;
            $laravel[$name] = [$value];
        }
        self::assertTrue(Webhook::verify($secret, $fpm, $body, $ts));
        self::assertTrue(Webhook::verify($secret, $laravel, $body, $ts));
    }

    public function testAnyOfSeveralSignaturesMatches(): void
    {
        ['secret' => $secret, 'headers' => $headers, 'body' => $body, 'ts' => $ts] = $this->vector;
        $headers['webhook-signature'] = 'v1,AAAA v2,zzz ' . $headers['webhook-signature'];
        self::assertTrue(Webhook::verify($secret, $headers, $body, $ts));
    }

    public function testRejections(): void
    {
        ['secret' => $secret, 'headers' => $headers, 'body' => $body, 'ts' => $ts] = $this->vector;
        self::assertFalse(Webhook::verify($secret, $headers, $body . ' ', $ts), 'tampered body');
        self::assertFalse(Webhook::verify($secret, $headers, $body, $ts + Webhook::TOLERANCE_SECONDS + 1), 'expired');
        self::assertFalse(Webhook::verify($secret, $headers, $body, $ts - Webhook::TOLERANCE_SECONDS - 1), 'from the future');
        self::assertFalse(Webhook::verify('whsec_' . base64_encode('other'), $headers, $body, $ts), 'other secret');
        self::assertFalse(Webhook::verify('whsec_!!!', $headers, $body, $ts), 'secret not base64');
        foreach (array_keys($headers) as $missing) {
            $partial = $headers;
            unset($partial[$missing]);
            self::assertFalse(Webhook::verify($secret, $partial, $body, $ts), "no {$missing}");
        }
        self::assertFalse(Webhook::verify($secret, ['webhook-timestamp' => '1e9'] + $headers, $body, $ts), 'timestamp not an integer');
        self::assertFalse(Webhook::verify($secret, ['webhook-signature' => 'v1,' . base64_encode('short')] + $headers, $body, $ts));
    }

    public function testParseReturnsTheEvent(): void
    {
        ['secret' => $secret, 'headers' => $headers, 'body' => $body, 'ts' => $ts] = $this->vector;
        $event = Webhook::parse($secret, $headers, $body, $ts);
        self::assertSame('run.succeeded', $event['type']);
        self::assertSame('run_test', $event['data']['object']['id']);

        $this->expectException(WebhookVerificationException::class);
        Webhook::parse($secret, $headers, $body, $ts + 3600);
    }

    public function testParseRejectsASignedNonEvent(): void
    {
        $secret = 'whsec_' . base64_encode('k');
        $body = '[1,2]';
        $headers = [
            'webhook-id' => 'evt_1',
            'webhook-timestamp' => '1000',
            'webhook-signature' => 'v1,' . base64_encode(hash_hmac('sha256', "evt_1.1000.{$body}", 'k', true)),
        ];
        self::assertTrue(Webhook::verify($secret, $headers, $body, 1000));
        $this->expectException(WebhookVerificationException::class);
        Webhook::parse($secret, $headers, $body, 1000);
    }

    public function testRequestHeadersFallsBackToServer(): void
    {
        $saved = $_SERVER;
        $_SERVER['HTTP_WEBHOOK_ID'] = 'evt_1';
        try {
            $headers = Webhook::requestHeaders();
            if (!function_exists('getallheaders')) {
                self::assertSame('evt_1', $headers['webhook-id']);
            } else {
                self::assertIsArray($headers);
            }
        } finally {
            $_SERVER = $saved;
        }
    }
}
