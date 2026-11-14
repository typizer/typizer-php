<?php

declare(strict_types=1);

namespace Typizer;

/**
 * Ответ-файл: результат прогона в XML/CSV/XLSX/ZIP (`getRunOutput`), выгрузка пакета
 * (`exportBatch`), исходный документ (`getFileContent`), XSD типизатора (`getTypizerXsd`).
 *
 * ```php
 * $xml = $typizer->getRunOutput('run_…', 'xml');
 * // filename приходит от сервера; SDK уже оставляет в нём только базовое имя файла.
 * $xml->saveTo('/upload/typizer/' . ($xml->filename ?? 'result.xml'));
 * ```
 */
final class Download
{
    public function __construct(
        public readonly string $body,
        public readonly string $contentType,
        public readonly ?string $filename = null,
    ) {
    }

    /**
     * Имя файла из `Content-Disposition`, если сервер его прислал. Возвращается только базовое
     * имя: без каталогов (`../`, `/`, `\\`), управляющих символов и `.`/`..` — его можно
     * дописывать к своему каталогу, не опасаясь выйти за его пределы.
     */
    public static function filenameFrom(?string $contentDisposition): ?string
    {
        if ($contentDisposition === null) {
            return null;
        }
        if (preg_match("/filename\\*\\s*=\\s*UTF-8''([^;]+)/i", $contentDisposition, $m) === 1) {
            return self::safeName(rawurldecode(trim($m[1])));
        }
        if (preg_match('/filename\s*=\s*"([^"]*)"/i', $contentDisposition, $m) === 1
            || preg_match('/filename\s*=\s*([^;]+)/i', $contentDisposition, $m) === 1) {
            return self::safeName(trim($m[1]));
        }

        return null;
    }

    private static function safeName(string $name): ?string
    {
        $name = (string) preg_replace('/[\x00-\x1F\x7F]/', '', $name);
        $parts = explode('/', str_replace('\\', '/', $name));
        $name = trim((string) end($parts));

        return ($name === '' || $name === '.' || $name === '..') ? null : $name;
    }

    /**
     * Сохраняет тело в файл и возвращает число записанных байт.
     */
    public function saveTo(string $path): int
    {
        $written = file_put_contents($path, $this->body);
        if ($written === false) {
            throw new \RuntimeException("Typizer: не удалось записать файл: {$path}");
        }

        return $written;
    }

    /** Тело, разобранное как JSON (для `getRunOutput(…, 'json')`). */
    public function json(): mixed
    {
        return json_decode($this->body, true, 512, JSON_THROW_ON_ERROR);
    }

    public function __toString(): string
    {
        return $this->body;
    }
}
