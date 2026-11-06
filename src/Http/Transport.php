<?php

declare(strict_types=1);

namespace FraudNova\Http;

use FraudNova\Client;
use FraudNova\Exception\ApiException;
use FraudNova\Exception\InvalidArgumentException;
use FraudNova\Exception\RateLimitException;
use FraudNova\Exception\TransportException;
use FraudNova\Exception\UnexpectedResponseException;
use FraudNova\RetryPolicy;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Sends requests to the FraudNova API and decodes the responses.
 *
 * @internal
 */
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
        private readonly ?RetryPolicy $retry = null,
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

        return $this->send(fn (): RequestInterface => $this->newRequest('GET', $url, $authenticated));
    }

    /**
     * @param  array<string, mixed>  $json
     * @return array<string, mixed>
     */
    public function post(string $path, array $json): array
    {
        try {
            $body = json_encode($json, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new InvalidArgumentException('Request body could not be encoded as JSON: '.$e->getMessage(), 0, $e);
        }

        return $this->send(fn (): RequestInterface => $this->newRequest('POST', $this->baseUrl.$path, true)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->streamFactory()->createStream($body)));
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
     * @param  \Closure(): RequestInterface  $buildRequest  called once per attempt so retries get a fresh body stream
     * @return array<string, mixed>
     */
    private function send(\Closure $buildRequest): array
    {
        $attempt = 1;

        while (true) {
            try {
                $response = $this->httpClient()->sendRequest($buildRequest());
            } catch (ClientExceptionInterface $e) {
                throw new TransportException('FraudNova request failed: '.$e->getMessage(), $e);
            }

            try {
                return $this->decode($response);
            } catch (RateLimitException $e) {
                $delay = $this->retry?->delayFor($e, $attempt);

                if ($this->retry === null || $delay === null) {
                    throw $e;
                }

                $this->retry->sleep($delay);
                $attempt++;
            }
        }
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
