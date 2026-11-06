<?php

declare(strict_types=1);

namespace FraudNova\Tests\Http;

use FraudNova\Exception\MissingHttpClientException;
use FraudNova\Http\HttpClientDiscovery;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Psr18Client;

final class HttpClientDiscoveryTest extends TestCase
{
    public function test_prefers_guzzle_when_available(): void
    {
        $discovery = new HttpClientDiscovery;

        $this->assertInstanceOf(Client::class, $discovery->client());
        $this->assertInstanceOf(HttpFactory::class, $discovery->requestFactory());
        $this->assertInstanceOf(HttpFactory::class, $discovery->streamFactory());
    }

    public function test_falls_back_to_symfony_when_guzzle_is_missing(): void
    {
        $discovery = new HttpClientDiscovery(static fn (string $class): bool => $class !== Client::class && class_exists($class));

        $this->assertInstanceOf(Psr18Client::class, $discovery->client());
        $this->assertInstanceOf(Psr18Client::class, $discovery->requestFactory());
        $this->assertInstanceOf(Psr18Client::class, $discovery->streamFactory());
    }

    public function test_throws_a_helpful_error_when_nothing_is_installed(): void
    {
        $discovery = new HttpClientDiscovery(static fn (string $class): bool => false);

        $this->expectException(MissingHttpClientException::class);
        $this->expectExceptionMessage('composer require guzzlehttp/guzzle');

        $discovery->client();
    }

    public function test_guzzle_that_is_not_psr18_falls_back_to_symfony(): void
    {
        $discovery = new HttpClientDiscovery(
            isA: static fn (string $class, string $interface): bool => $class !== Client::class && is_a($class, $interface, true),
        );

        $this->assertInstanceOf(Psr18Client::class, $discovery->client());
        $this->assertInstanceOf(Psr18Client::class, $discovery->requestFactory());
        $this->assertInstanceOf(Psr18Client::class, $discovery->streamFactory());
    }

    public function test_guzzle_without_http_factory_falls_back_to_symfony(): void
    {
        $discovery = new HttpClientDiscovery(static fn (string $class): bool => $class !== HttpFactory::class && class_exists($class));

        $this->assertInstanceOf(Psr18Client::class, $discovery->client());
        $this->assertInstanceOf(Psr18Client::class, $discovery->requestFactory());
        $this->assertInstanceOf(Psr18Client::class, $discovery->streamFactory());
    }

    public function test_unusable_guzzle_and_no_symfony_is_missing(): void
    {
        $discovery = new HttpClientDiscovery(
            static fn (string $class): bool => $class !== Psr18Client::class && class_exists($class),
            static fn (string $class, string $interface): bool => false,
        );

        $this->expectException(MissingHttpClientException::class);

        $discovery->requestFactory();
    }

    public function test_symfony_without_psr17_is_reported_as_missing(): void
    {
        $discovery = new HttpClientDiscovery(
            static fn (string $class): bool => $class !== Client::class && class_exists($class),
            newSymfonyClient: static fn (): Psr18Client => throw new \LogicException('You cannot use the "Psr18Client" as no PSR-17 factories have been provided.'),
        );

        try {
            $discovery->client();
            $this->fail('Expected MissingHttpClientException');
        } catch (MissingHttpClientException $e) {
            $this->assertStringContainsString('nyholm/psr7', $e->getMessage());
            $this->assertInstanceOf(\LogicException::class, $e->getPrevious());
        }
    }
}
