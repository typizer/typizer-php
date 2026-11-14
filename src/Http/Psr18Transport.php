<?php

declare(strict_types=1);

namespace Typizer\Http;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Typizer\Exception\NetworkException;

/**
 * Транспорт поверх любого PSR-18 клиента: Guzzle, Symfony HttpClient, клиент вашего фреймворка.
 *
 * Пакеты `psr/http-client` и `psr/http-factory` SDK сам не требует — они приходят вместе с
 * клиентом. Таймауты и прокси настраиваются в самом клиенте (у PSR-18 нет таймаута на запрос),
 * а multipart передаётся ему строкой: документ целиком окажется в памяти.
 *
 * ```php
 * $transport = new Psr18Transport(new \GuzzleHttp\Client(['timeout' => 90]), $factory, $factory);
 * // или найти установленный клиент и фабрики автоматически (нужен php-http/discovery):
 * $transport = Psr18Transport::discover();
 * ```
 */
final class Psr18Transport implements Transport
{
    public function __construct(
        private readonly ClientInterface $client,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
    ) {
    }

    /**
     * Находит установленный PSR-18 клиент и PSR-17 фабрики через php-http/discovery.
     */
    public static function discover(): self
    {
        if (!class_exists(\Http\Discovery\Psr18ClientDiscovery::class)) {
            throw new \LogicException(
                'Typizer: для Psr18Transport::discover() установите php-http/discovery и PSR-18 клиент, '
                . 'например: composer require guzzlehttp/guzzle php-http/discovery',
            );
        }

        return new self(
            \Http\Discovery\Psr18ClientDiscovery::find(),
            \Http\Discovery\Psr17FactoryDiscovery::findRequestFactory(),
            \Http\Discovery\Psr17FactoryDiscovery::findStreamFactory(),
        );
    }

    public function send(Request $request): Response
    {
        $psrRequest = $this->requestFactory->createRequest($request->method, $request->url);
        foreach ($request->headers as $name => $value) {
            $psrRequest = $psrRequest->withHeader($name, $value);
        }
        $body = $request->body;
        if ($body instanceof MultipartBody) {
            $psrRequest = $psrRequest
                ->withHeader('Content-Type', $body->contentType())
                ->withHeader('Content-Length', (string) $body->size())
                ->withBody($this->streamFactory->createStream($body->contents()));
        } elseif (is_string($body)) {
            $psrRequest = $psrRequest->withBody($this->streamFactory->createStream($body));
        }

        try {
            $psrResponse = $this->client->sendRequest($psrRequest);
        } catch (ClientExceptionInterface $e) {
            $path = parse_url($request->url, PHP_URL_PATH);
            throw new NetworkException(
                "Typizer: сетевая ошибка при {$request->method} " . (is_string($path) ? $path : $request->url) . ': ' . $e->getMessage(),
                $request->method,
                $request->url,
                $e instanceof NetworkExceptionInterface && stripos($e->getMessage(), 'timed out') !== false,
                $e,
            );
        }

        $headers = [];
        foreach ($psrResponse->getHeaders() as $name => $values) {
            $headers[(string) $name] = implode(', ', $values);
        }

        return new Response($psrResponse->getStatusCode(), $headers, (string) $psrResponse->getBody());
    }
}
