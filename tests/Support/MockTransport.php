<?php

declare(strict_types=1);

namespace Typizer\Tests\Support;

use Typizer\Exception\NetworkException;
use Typizer\Http\MultipartBody;
use Typizer\Http\Request;
use Typizer\Http\Response;
use Typizer\Http\Transport;

/**
 * Records every request and answers from a queue (a Response, a NetworkException to throw, or a
 * callable); with the queue empty it answers 200 `{}`. Multipart bodies are captured as bytes at
 * send time, the way a real transport would read them.
 */
final class MockTransport implements Transport
{
    /** @var list<Request> */
    public array $requests = [];

    /** @var list<string|null> raw body of each request, multipart serialized */
    public array $bodies = [];

    /** @var list<Response|NetworkException|callable(Request): Response> */
    private array $queue = [];

    public function push(Response|NetworkException|callable ...$responses): self
    {
        foreach ($responses as $response) {
            $this->queue[] = $response;
        }

        return $this;
    }

    /** @param array<string, mixed> $data */
    public static function json(int $status, array $data, array $headers = []): Response
    {
        return new Response($status, ['Content-Type' => $status >= 400 ? 'application/problem+json' : 'application/json'] + $headers, (string) json_encode($data));
    }

    /** @param array<string, string> $headers */
    public static function problem(int $status, string $code, array $headers = []): Response
    {
        return self::json($status, [
            'type' => "https://docs.typizer.ru/errors/{$code}",
            'title' => $code,
            'status' => $status,
            'detail' => "detail of {$code}",
            'code' => $code,
            'param' => $code === 'invalid_request' ? 'schema' : null,
            'request_id' => 'req_' . $code,
            'doc_url' => "https://docs.typizer.ru/errors/{$code}",
        ], $headers);
    }

    public function send(Request $request): Response
    {
        $this->requests[] = $request;
        $this->bodies[] = $request->body instanceof MultipartBody ? $request->body->contents() : $request->body;
        $next = array_shift($this->queue) ?? new Response(200, ['Content-Type' => 'application/json'], '{}');
        if ($next instanceof NetworkException) {
            throw $next;
        }
        if (is_callable($next)) {
            return $next($request);
        }

        return $next;
    }

    public function last(): Request
    {
        return $this->requests[count($this->requests) - 1];
    }

    /**
     * Parses a multipart body into parts: name, filename (or null), content type, bytes.
     *
     * @return list<array{name: string, filename: ?string, type: ?string, body: string}>
     */
    public static function parseMultipart(string $body, string $contentType): array
    {
        if (preg_match('/boundary=([^;]+)/', $contentType, $m) !== 1) {
            throw new \RuntimeException("no boundary in {$contentType}");
        }
        $boundary = $m[1];
        if (!str_ends_with($body, "--{$boundary}--\r\n")) {
            throw new \RuntimeException('multipart body does not end with the closing boundary');
        }
        $parts = [];
        $chunks = explode("--{$boundary}", $body);
        array_shift($chunks); // preamble
        array_pop($chunks);   // "--\r\n" after the closing boundary
        foreach ($chunks as $chunk) {
            if (!str_starts_with($chunk, "\r\n") || !str_ends_with($chunk, "\r\n")) {
                throw new \RuntimeException('malformed part');
            }
            $chunk = substr($chunk, 2, -2);
            [$head, $content] = explode("\r\n\r\n", $chunk, 2);
            preg_match('/name="([^"]*)"/', $head, $name);
            $hasFilename = preg_match('/filename="([^"]*)"/', $head, $filename) === 1;
            $hasType = preg_match('/Content-Type: (.+)/', $head, $type) === 1;
            $parts[] = [
                'name' => $name[1] ?? '',
                'filename' => $hasFilename ? $filename[1] : null,
                'type' => $hasType ? trim($type[1]) : null,
                'body' => $content,
            ];
        }

        return $parts;
    }
}
