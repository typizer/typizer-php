<?php

declare(strict_types=1);

namespace Typizer\Http;

use Typizer\Exception\NetworkException;

/**
 * Отправляет один HTTP-запрос и возвращает ответ как есть — любой статус, в том числе 4xx/5xx.
 *
 * Повторы, разбор ошибок API и заголовки авторизации — забота {@see \Typizer\Client}; транспорт
 * только передаёт байты. Своя реализация нужна редко: по умолчанию работает {@see CurlTransport}
 * (ext-curl), а для Guzzle и других PSR-18 клиентов есть {@see Psr18Transport}.
 */
interface Transport
{
    /**
     * @throws NetworkException если ответа нет совсем: DNS, соединение, TLS, таймаут
     */
    public function send(Request $request): Response;
}
