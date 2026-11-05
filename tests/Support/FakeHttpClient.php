<?php

declare(strict_types=1);

namespace FraudNova\Tests\Support;

use LogicException;
use Nyholm\Psr7\Response;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

final class FakeHttpClient implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    /** @var list<ResponseInterface|ClientExceptionInterface> */
    private array $queue = [];

    public function queue(ResponseInterface|ClientExceptionInterface $item): self
    {
        $this->queue[] = $item;

        return $this;
    }

    /**
     * @param  array<mixed>  $body
     * @param  array<string, string>  $headers
     */
    public function json(int $status, array $body, array $headers = []): self
    {
        return $this->raw($status, json_encode($body, JSON_THROW_ON_ERROR), $headers + ['Content-Type' => 'application/json']);
    }

    /**
     * @param  array<string, string>  $headers
     */
    public function raw(int $status, string $body, array $headers = []): self
    {
        return $this->queue(new Response($status, $headers, $body));
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;

        $item = array_shift($this->queue);

        if ($item === null) {
            throw new LogicException('No response queued');
        }

        if ($item instanceof Throwable) {
            throw $item;
        }

        return $item;
    }

    public function lastRequest(): RequestInterface
    {
        if ($this->requests === []) {
            throw new LogicException('No request recorded');
        }

        return $this->requests[array_key_last($this->requests)];
    }
}
