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
}
