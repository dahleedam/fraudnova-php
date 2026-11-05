<?php

declare(strict_types=1);

namespace FraudNova\Http;

use FraudNova\Client;
use FraudNova\Exception\ApiException;
use FraudNova\Exception\TransportException;
use FraudNova\Exception\UnexpectedResponseException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

final class Transport
{
    private readonly string $baseUrl;

    private readonly string $apiKey;

    private readonly HttpClientDiscovery $discovery;

    public function __construct(
        #[\SensitiveParameter] string $apiKey,
        string $baseUrl,
        private ?ClientInterface $httpClient = null,
        private ?RequestFactoryInterface $requestFactory = null,
        private ?StreamFactoryInterface $streamFactory = null,
        /** @phpstan-ignore property.onlyWritten */
        private readonly mixed $retry = null,
        ?HttpClientDiscovery $discovery = null,
    ) {
        $this->apiKey = $apiKey;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->discovery = $discovery ?? new HttpClientDiscovery;
    }

    /**
     * @param  array<string, string|int>  $query
     * @return array<string, mixed>
     */
    public function get(string $path, array $query = [], bool $authenticated = true): array
    {
        $url = $this->baseUrl.$path;
        if ($query !== []) {
            $url .= '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        $request = $this->newRequest('GET', $url, $authenticated);

        return $this->send($request);
    }

    /**
     * @param  array<string, mixed>  $json
     * @return array<string, mixed>
     */
    public function post(string $path, array $json): array
    {
        $request = $this->newRequest('POST', $this->baseUrl.$path, true)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->streamFactory()->createStream(json_encode($json, JSON_THROW_ON_ERROR)));

        return $this->send($request);
    }

    private function newRequest(string $method, string $url, bool $authenticated): RequestInterface
    {
        $request = $this->requestFactory()->createRequest($method, $url)
            ->withHeader('Accept', 'application/json')
            ->withHeader('User-Agent', 'fraudnova-php/'.Client::VERSION.' php/'.PHP_VERSION);

        if ($authenticated) {
            $request = $request->withHeader('Authorization', 'Bearer '.$this->apiKey);
        }

        return $request;
    }

    /**
     * @return array<string, mixed>
     */
    private function send(RequestInterface $request): array
    {
        try {
            $response = $this->httpClient()->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw new TransportException('FraudNova request failed: '.$e->getMessage(), $e);
        }

        return $this->decode($response);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(ResponseInterface $response): array
    {
        $status = $response->getStatusCode();

        if ($status < 200 || $status >= 300) {
            throw ApiException::fromResponse($response);
        }

        $decoded = json_decode((string) $response->getBody(), true);

        if (! is_array($decoded) || ($decoded['success'] ?? null) !== true) {
            /** @var array<string, mixed> $body */
            $body = is_array($decoded) ? $decoded : [];

            throw new UnexpectedResponseException($status, 'Unexpected response body', $body);
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private function httpClient(): ClientInterface
    {
        return $this->httpClient ??= $this->discovery->client();
    }

    private function requestFactory(): RequestFactoryInterface
    {
        return $this->requestFactory ??= $this->discovery->requestFactory();
    }

    private function streamFactory(): StreamFactoryInterface
    {
        return $this->streamFactory ??= $this->discovery->streamFactory();
    }
}
