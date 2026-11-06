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

/**
 * Finds an installed PSR-18 client and PSR-17 factories: Guzzle first, then the Symfony HTTP client.
 *
 * @internal
 */
final class HttpClientDiscovery
{
    /** @var \Closure(string): bool */
    private \Closure $classExists;

    /** @var \Closure(string, string): bool */
    private \Closure $isA;

    /** @var \Closure(): Psr18Client */
    private \Closure $newSymfonyClient;

    /**
     * @param  (\Closure(string): bool)|null  $classExists
     * @param  (\Closure(string, string): bool)|null  $isA  is_a($class, $interface, true), replaceable for tests
     * @param  (\Closure(): Psr18Client)|null  $newSymfonyClient
     */
    public function __construct(?\Closure $classExists = null, ?\Closure $isA = null, ?\Closure $newSymfonyClient = null)
    {
        $this->classExists = $classExists ?? static fn (string $class): bool => class_exists($class);
        $this->isA = $isA ?? static fn (string $class, string $interface): bool => is_a($class, $interface, true);
        $this->newSymfonyClient = $newSymfonyClient ?? static fn (): Psr18Client => new Psr18Client;
    }

    public function client(float $timeout = 30.0, float $connectTimeout = 10.0): ClientInterface
    {
        return match ($this->provider()) {
            'guzzle' => new GuzzleClient(['timeout' => $timeout, 'connect_timeout' => $connectTimeout]),
            'symfony' => $this->symfony(),
            null => throw $this->missing(),
        };
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
        return match ($this->provider()) {
            'guzzle' => new HttpFactory,
            'symfony' => $this->symfony(),
            null => throw $this->missing(),
        };
    }

    /**
     * @return 'guzzle'|'symfony'|null
     */
    private function provider(): ?string
    {
        if (($this->classExists)(GuzzleClient::class)
            && ($this->isA)(GuzzleClient::class, ClientInterface::class)
            && ($this->classExists)(HttpFactory::class)) {
            return 'guzzle';
        }

        if (($this->classExists)(Psr18Client::class)) {
            return 'symfony';
        }

        return null;
    }

    private function symfony(): Psr18Client
    {
        try {
            return ($this->newSymfonyClient)();
        } catch (\Throwable $e) {
            throw new MissingHttpClientException('The Symfony HTTP client needs a PSR-17 implementation. Run "composer require nyholm/psr7".', 0, $e);
        }
    }

    private function missing(): MissingHttpClientException
    {
        return new MissingHttpClientException('No PSR-18 HTTP client found. Run "composer require guzzlehttp/guzzle" or pass a client to FraudNova\Client.');
    }
}
