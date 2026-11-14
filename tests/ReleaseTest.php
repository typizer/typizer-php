<?php

declare(strict_types=1);

namespace Typizer\Tests;

use PHPUnit\Framework\TestCase;
use Typizer\Client;

/**
 * Release metadata: the Packagist package is complete and light (PHP 8.1+, no runtime dependencies
 * beyond ext-json), the README is the real one, the LICENSE is the other SDKs' license, the
 * Composer-less autoloader works, and nothing in the package names an internal component.
 */
final class ReleaseTest extends TestCase
{
    private const ROOT = __DIR__ . '/..';

    /** @return array<string, mixed> */
    private static function composer(): array
    {
        return json_decode((string) file_get_contents(self::ROOT . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    public function testComposerMetadata(): void
    {
        $composer = self::composer();
        self::assertSame('typizer/typizer', $composer['name']);
        self::assertSame('library', $composer['type']);
        self::assertSame('MIT', $composer['license']);
        self::assertStringContainsString('Typizer API', $composer['description']);
        self::assertSame('https://typizer.ru', $composer['homepage']);
        self::assertSame(['php' => '>=8.1', 'ext-json' => '*'], $composer['require'], 'no runtime dependencies');
        self::assertSame(['Typizer\\' => 'src/'], $composer['autoload']['psr-4']);
        self::assertArrayHasKey('ext-curl', $composer['suggest']);
        self::assertArrayNotHasKey('version', $composer, 'Packagist takes the version from the git tag');
        self::assertNotEmpty($composer['keywords']);
    }

    public function testVersionConstantIsSemver(): void
    {
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+(-[0-9A-Za-z.-]+)?$/', Client::VERSION);
    }

    public function testLicenseIsTheSdkLicense(): void
    {
        $license = (string) file_get_contents(self::ROOT . '/LICENSE');
        self::assertStringStartsWith("MIT License\n\nCopyright (c) 2026 Typizer\n", $license);
        $python = self::ROOT . '/../sdk-python/LICENSE';
        if (is_file($python)) {
            self::assertSame(file_get_contents($python), $license);
        }
    }

    public function testReadmeIsTheRealOne(): void
    {
        $readme = (string) file_get_contents(self::ROOT . '/README.md');
        self::assertStringContainsString('composer require typizer/typizer', $readme);
        self::assertStringContainsString('https://my.typizer.ru', $readme);
        self::assertStringContainsString('https://docs.typizer.ru', $readme);
        self::assertStringContainsString('Битрикс24', $readme);
        self::assertStringContainsString('amoCRM', $readme);
        self::assertStringNotContainsString('example.com', $readme);
    }

    public function testNoInternalNamesInThePackage(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::ROOT, \FilesystemIterator::SKIP_DOTS));
        $forbidden = 'doc' . 'ling';
        foreach ($files as $file) {
            $path = (string) $file;
            if (str_contains($path, '/vendor/') || str_contains($path, '/.phpunit.cache/') || !$file->isFile()) {
                continue;
            }
            self::assertStringNotContainsStringIgnoringCase($forbidden, (string) file_get_contents($path), $path);
        }
    }

    public function testAutoloadPhpWorksWithoutComposer(): void
    {
        $code = 'require ' . var_export(realpath(self::ROOT . '/autoload.php'), true) . ';'
            . ' $c = new Typizer\Client("tz_live_x", transport: new Typizer\Http\CurlTransport());'
            . ' echo get_class($c), " ", Typizer\Webhook::TOLERANCE_SECONDS, " ", class_exists("Typizer\Exception\RateLimitException") ? "ok" : "no";';
        $process = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        self::assertSame(0, proc_close($process), $out);
        self::assertSame('Typizer\Client 300 ok', $out);
    }

    public function testMirrorLayoutShipsOnlyTheLibrary(): void
    {
        $attributes = (string) file_get_contents(self::ROOT . '/.gitattributes');
        foreach (['/tests', '/phpunit.xml.dist', '/scripts', '/package.json', '/turbo.json'] as $path) {
            self::assertStringContainsString("{$path} export-ignore", $attributes);
        }
    }
}
