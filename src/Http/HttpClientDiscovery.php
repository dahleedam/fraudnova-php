<?php

declare(strict_types=1);

namespace FraudNova\Http;

use FraudNova\Exception\MissingHttpClientException;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\HttpFactory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Symfony\Component\HttpClient\Psr18Client;

final class HttpClientDiscovery
{
    /** @var \Closure(string): bool */
    private \Closure $classExists;

    /**
     * @param  (\Closure(string): bool)|null  $classExists
     */
    public function __construct(?\Closure $classExists = null)
    {
        $this->classExists = $classExists ?? static fn (string $class): bool => class_exists($class);
    }

    public function client(float $timeout = 30.0, float $connectTimeout = 10.0): ClientInterface
    {
        if (($this->classExists)(GuzzleClient::class)) {
            return new GuzzleClient(['timeout' => $timeout, 'connect_timeout' => $connectTimeout]);
        }

        if (($this->classExists)(Psr18Client::class)) {
            return new Psr18Client;
        }

        throw $this->missing();
    }

    public function requestFactory(): RequestFactoryInterface
    {
        return $this->factory();
    }

    public function streamFactory(): StreamFactoryInterface
    {
        return $this->factory();
    }

    private function factory(): HttpFactory|Psr18Client
    {
        if (($this->classExists)(GuzzleClient::class)) {
            return new HttpFactory;
        }

        if (($this->classExists)(Psr18Client::class)) {
            return new Psr18Client;
        }

        throw $this->missing();
    }

    private function missing(): MissingHttpClientException
    {
        return new MissingHttpClientException('No PSR-18 HTTP client found. Run "composer require guzzlehttp/guzzle" or pass a client to FraudNova\Client.');
    }
}
