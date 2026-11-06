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
     * Keeps the API key out of var_dump() and print_r() output.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'apiKey' => self::maskKey($this->apiKey),
            'baseUrl' => $this->baseUrl,
            'httpClient' => $this->httpClient === null ? null : $this->httpClient::class,
            'retry' => $this->retry,
        ];
    }

    /**
     * "fn_…" plus the last four characters; a short key shows no characters at all.
     */
    public static function maskKey(#[\SensitiveParameter] string $apiKey): string
    {
        return strlen($apiKey) > 12 ? 'fn_…'.substr($apiKey, -4) : 'fn_…';
    }

    /**
     * @param  array<string, string|int>  $query
     * @param  ?\Closure(array<string, mixed>): ?string  $validate  returns an error message when a 2xx body lacks what the caller needs
     * @return array<string, mixed>
     */
    public function get(string $path, array $query = [], bool $authenticated = true, ?\Closure $validate = null): array
    {
        $url = $this->baseUrl.$path;
        if ($query !== []) {
            $url .= '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        return $this->send(fn (): RequestInterface => $this->newRequest('GET', $url, $authenticated), $validate);
    }

    /**
     * @param  array<string, mixed>  $json
     * @param  ?\Closure(array<string, mixed>): ?string  $validate  returns an error message when a 2xx body lacks what the caller needs
     * @return array<string, mixed>
     */
    public function post(string $path, array $json, ?\Closure $validate = null): array
    {
        try {
            $body = json_encode($json, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new InvalidArgumentException('Request body could not be encoded as JSON: '.$e->getMessage(), 0, $e);
        }

        return $this->send(fn (): RequestInterface => $this->newRequest('POST', $this->baseUrl.$path, true)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->streamFactory()->createStream($body)), $validate);
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
     * @param  ?\Closure(array<string, mixed>): ?string  $validate
     * @return array<string, mixed>
     */
    private function send(\Closure $buildRequest, ?\Closure $validate): array
    {
        $attempt = 1;

        while (true) {
            try {
                $response = $this->httpClient()->sendRequest($buildRequest());
            } catch (ClientExceptionInterface $e) {
                throw new TransportException('FraudNova request failed: '.$e->getMessage(), $e);
            }

            try {
                return $this->decode($response, $validate);
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
     * @param  ?\Closure(array<string, mixed>): ?string  $validate
     * @return array<string, mixed>
     */
    private function decode(ResponseInterface $response, ?\Closure $validate): array
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
        $problem = $validate === null ? null : $validate($decoded);

        if ($problem !== null) {
            throw new UnexpectedResponseException($status, $problem, $decoded);
        }

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
