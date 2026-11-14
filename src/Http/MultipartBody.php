<?php

declare(strict_types=1);

namespace Typizer\Http;

use Typizer\File;

/**
 * Тело `multipart/form-data` (RFC 7578), собранное во временный поток `php://temp`.
 *
 * Поток хранит до 2 МиБ в памяти, остальное — во временном файле, поэтому документ на 50 МиБ не
 * занимает 50 МиБ `memory_limit`. Тело собирается один раз на вызов и перематывается перед каждой
 * попыткой, так что повтор после 429/503 отправляет те же байты.
 */
final class MultipartBody
{
    public readonly string $boundary;

    /** @var resource */
    private $stream;

    private int $size;

    /**
     * @param list<array{0: string, 1: string|File}> $parts имя поля и значение; повтор имени — повтор части
     */
    public function __construct(array $parts)
    {
        $this->boundary = 'typizer-' . bin2hex(random_bytes(12));
        $stream = fopen('php://temp/maxmemory:2097152', 'w+b');
        if ($stream === false) {
            throw new \RuntimeException('Typizer: не удалось открыть временный поток для multipart');
        }
        $this->stream = $stream;

        foreach ($parts as [$name, $value]) {
            $head = "--{$this->boundary}\r\nContent-Disposition: form-data; name=\"" . self::quote($name) . '"';
            if ($value instanceof File) {
                $head .= '; filename="' . self::quote($value->filename) . "\"\r\n"
                    . 'Content-Type: ' . $value->contentType . "\r\n\r\n";
                fwrite($stream, $head);
                $value->writeTo($stream);
                fwrite($stream, "\r\n");
            } else {
                fwrite($stream, $head . "\r\n\r\n" . $value . "\r\n");
            }
        }
        fwrite($stream, "--{$this->boundary}--\r\n");
        $size = ftell($stream);
        $this->size = $size === false ? 0 : $size;
        rewind($stream);
    }

    public function __destruct()
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
    }

    public function contentType(): string
    {
        return 'multipart/form-data; boundary=' . $this->boundary;
    }

    public function size(): int
    {
        return $this->size;
    }

    /**
     * Поток с телом, перемотанный в начало. Не закрывайте его: он принадлежит этому объекту.
     *
     * @return resource
     */
    public function stream()
    {
        rewind($this->stream);

        return $this->stream;
    }

    /** Всё тело строкой — для транспортов, которым нужен буфер (PSR-18). */
    public function contents(): string
    {
        rewind($this->stream);
        $contents = stream_get_contents($this->stream);

        return $contents === false ? '' : $contents;
    }

    /** Экранирование имени поля и файла, как у браузеров (WHATWG): `"` и переводы строки. */
    private static function quote(string $value): string
    {
        return str_replace(["\r", "\n", '"'], ['%0D', '%0A', '%22'], $value);
    }
}
