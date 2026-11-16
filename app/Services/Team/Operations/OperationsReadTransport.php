<?php

namespace App\Services\Team\Operations;

use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Utils;
use Psr\Http\Message\RequestInterface;

/** Request/job-scoped sockets only: never retains credentials, cookies or responses. */
final class OperationsReadTransport
{
    private mixed $handler = null;

    public function __invoke(RequestInterface $request, array $options): PromiseInterface
    {
        // Reuse cURL's connection pool where available, retaining Guzzle's normal
        // stream fallback on installations without cURL. Each request still gets
        // its own Laravel middleware, headers and freshly admitted credentials.
        $this->handler ??= Utils::chooseHandler();

        return ($this->handler)($request, $options);
    }
}
