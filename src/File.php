<?php

declare(strict_types=1);

namespace Typizer;

use InvalidArgumentException;

/**
 * Документ для загрузки в multipart-запросе: `extract`, `createRun`, `createFile`, `createBatch`.
 *
 * ```php
 * File::fromPath('/tmp/invoice.pdf');                        // файл на диске, читается потоком
 * File::fromString($bytes, 'invoice.pdf');                   // байты уже в памяти (скачали из CRM)
 * File::fromStream(fopen('php://temp', 'r+b'), 'scan.jpg');  // ресурс потока или PSR-7 поток
 * ```
 *
 * Тип файла API определяет по содержимому, а не по `Content-Type` части; имя файла важно только
 * для `.zip` в `createBatch` и для того, как файл подписан в кабинете.
 */
final class File
{
    private const TYPES = [
        'pdf' => 'application/pdf',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'gif' => 'image/gif',
        'tif' => 'image/tiff',
        'tiff' => 'image/tiff',
        'heic' => 'image/heic',
        'heif' => 'image/heif',
        'bmp' => 'image/bmp',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'odt' => 'application/vnd.oasis.opendocument.text',
        'rtf' => 'application/rtf',
        'txt' => 'text/plain',
        'csv' => 'text/csv',
        'html' => 'text/html',
        'xml' => 'application/xml',
        'zip' => 'application/zip',
    ];

    /** Позиция потока при создании: к ней он перематывается перед каждой отправкой. */
    private ?int $start = null;

    /**
     * @param 'path'|'string'|'stream' $kind
     */
    private function __construct(
        private readonly string $kind,
        private readonly mixed $source,
        public readonly string $filename,
        public readonly string $contentType,
    ) {
        if ($kind === 'stream' && is_resource($source)) {
            $meta = stream_get_meta_data($source);
            if ($meta['seekable']) {
                $position = ftell($source);
                $this->start = $position === false ? null : $position;
            }
        }
    }

    /**
     * Файл на диске. Читается потоком в момент отправки, целиком в память не загружается.
     */
    public static function fromPath(string $path, ?string $filename = null, ?string $contentType = null): self
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new InvalidArgumentException("Typizer: файл не найден или недоступен для чтения: {$path}");
        }
        $name = $filename ?? basename($path);

        return new self('path', $path, $name, $contentType ?? self::guessType($name));
    }

    /**
     * Байты документа, уже прочитанные в строку, — например, скачанные из Битрикс24 или amoCRM.
     */
    public static function fromString(string $contents, string $filename, ?string $contentType = null): self
    {
        return new self('string', $contents, $filename, $contentType ?? self::guessType($filename));
    }

    /**
     * Поток: ресурс (`fopen`, `php://temp`, `php://input`) или PSR-7 `StreamInterface`. Перематываемый
     * поток можно отправлять повторно; неперематываемый (`php://input`, сокет) — только один раз.
     *
     * @param resource|object $stream
     */
    public static function fromStream(mixed $stream, ?string $filename = null, ?string $contentType = null): self
    {
        if (is_resource($stream)) {
            if (get_resource_type($stream) !== 'stream') {
                throw new InvalidArgumentException('Typizer: File::fromStream() ждёт ресурс потока');
            }
            if ($filename === null) {
                $uri = stream_get_meta_data($stream)['uri'] ?? '';
                $filename = is_string($uri) && $uri !== '' && !str_starts_with($uri, 'php://') ? basename($uri) : null;
            }
        } elseif (!is_object($stream) || !method_exists($stream, 'read') || !method_exists($stream, 'eof')) {
            throw new InvalidArgumentException('Typizer: File::fromStream() ждёт ресурс потока или PSR-7 StreamInterface');
        }
        $name = $filename ?? 'document';

        return new self('stream', $stream, $name, $contentType ?? self::guessType($name));
    }

    /**
     * Копирует байты документа в поток `$target`.
     *
     * @internal используется {@see Http\MultipartBody}
     *
     * @param resource $target
     */
    public function writeTo($target): void
    {
        switch ($this->kind) {
            case 'string':
                self::write($target, $this->source);

                return;
            case 'path':
                $in = @fopen($this->source, 'rb');
                if ($in === false) {
                    throw new InvalidArgumentException("Typizer: не удалось открыть файл: {$this->source}");
                }
                try {
                    stream_copy_to_stream($in, $target);
                } finally {
                    fclose($in);
                }

                return;
            default:
                if (is_resource($this->source)) {
                    if ($this->start !== null) {
                        fseek($this->source, $this->start);
                    }
                    stream_copy_to_stream($this->source, $target);

                    return;
                }
                $stream = $this->source;
                if (method_exists($stream, 'isSeekable') && $stream->isSeekable() && method_exists($stream, 'rewind')) {
                    $stream->rewind();
                }
                while (!$stream->eof()) {
                    $chunk = $stream->read(65536);
                    if ($chunk === '') {
                        break;
                    }
                    self::write($target, $chunk);
                }
        }
    }

    /** @param resource $target */
    private static function write($target, string $bytes): void
    {
        $length = strlen($bytes);
        $written = 0;
        while ($written < $length) {
            $n = fwrite($target, $written === 0 ? $bytes : substr($bytes, $written));
            if ($n === false || $n === 0) {
                throw new \RuntimeException('Typizer: не удалось записать тело запроса во временный поток');
            }
            $written += $n;
        }
    }

    private static function guessType(string $filename): string
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return self::TYPES[$extension] ?? 'application/octet-stream';
    }
}
