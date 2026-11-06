<?php

declare(strict_types=1);

namespace FraudNova\Tests\Laravel;

use FraudNova\Client;
use FraudNova\Exception\InvalidArgumentException;
use FraudNova\Http\Transport;
use FraudNova\Laravel\Facades\FraudNova;
use FraudNova\Laravel\FraudNovaServiceProvider;
use FraudNova\RetryPolicy;
use FraudNova\Tests\Support\FakeHttpClient;
use GuzzleHttp\Client as GuzzleClient;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Nyholm\Psr7\Factory\Psr17Factory;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class ServiceProviderTest extends TestCase
{
    /**
     * @param  Application  $app
     * @return list<class-string<ServiceProvider>>
     */
    protected function getPackageProviders($app): array
    {
        return [FraudNovaServiceProvider::class];
    }

    /**
     * @param  Application  $app
     * @return array<string, class-string>
     */
    protected function getPackageAliases($app): array
    {
        return ['FraudNova' => FraudNova::class];
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $config = $app->make(Repository::class);
        $config->set('fraudnova.api_key', 'fn_test');
        $config->set('fraudnova.base_url', 'https://example.test/api/v1/');
        $config->set('fraudnova.retry_max_attempts', 3);
    }

    public function test_client_is_a_singleton_built_from_config(): void
    {
        $first = $this->app?->make(Client::class);
        $second = $this->app?->make(Client::class);

        $this->assertInstanceOf(Client::class, $first);
        $this->assertSame($first, $second);
        $this->assertSame($first, $this->app?->make('fraudnova'));
    }

    public function test_facade_resolves_and_calls_the_client(): void
    {
        $fake = (new FakeHttpClient)->json(200, ['success' => true, 'credits_remaining' => 42]);
        $psr17 = new Psr17Factory;
        $baseUrl = config('fraudnova.base_url');
        $this->assertIsString($baseUrl);

        $this->app?->singleton(Client::class, fn (): Client => new Client('fn_test', $fake, $psr17, $psr17, $baseUrl));

        $this->assertSame(42, FraudNova::balance());
        $this->assertStringStartsWith('https://example.test/api/v1/balance', (string) $fake->lastRequest()->getUri());
    }

    public function test_missing_api_key_fails_clearly(): void
    {
        config(['fraudnova.api_key' => '']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('FRAUDNOVA_API_KEY');

        $this->app?->make(Client::class);
    }

    public function test_config_is_publishable(): void
    {
        $paths = ServiceProvider::pathsToPublish(FraudNovaServiceProvider::class, 'fraudnova-config');

        $this->assertCount(1, $paths);
        $this->assertStringEndsWith('config/fraudnova.php', (string) array_key_first($paths));
    }

    private static function property(object $object, string $name): mixed
    {
        return (new \ReflectionProperty($object, $name))->getValue($object);
    }

    private function transport(): Transport
    {
        $client = $this->app?->make(Client::class);
        $this->assertInstanceOf(Client::class, $client);
        $transport = self::property($client, 'transport');
        $this->assertInstanceOf(Transport::class, $transport);

        return $transport;
    }

    public function test_config_values_reach_the_client(): void
    {
        config([
            'fraudnova.base_url' => 'https://example.test/v9/',
            'fraudnova.retry_max_attempts' => 3,
            'fraudnova.timeout' => 7,
        ]);

        $transport = $this->transport();

        $this->assertSame('https://example.test/v9', self::property($transport, 'baseUrl'));

        $retry = self::property($transport, 'retry');
        $this->assertInstanceOf(RetryPolicy::class, $retry);
        $this->assertSame(3, $retry->maxAttempts);

        $guzzle = self::property($transport, 'httpClient');
        $this->assertInstanceOf(GuzzleClient::class, $guzzle);
        $this->assertSame(7.0, $guzzle->getConfig('timeout'));
    }

    public function test_empty_base_url_falls_back_to_the_default(): void
    {
        config(['fraudnova.base_url' => '']);

        $this->assertSame(Client::DEFAULT_BASE_URL, self::property($this->transport(), 'baseUrl'));
    }

    public function test_non_string_base_url_falls_back_to_the_default(): void
    {
        config(['fraudnova.base_url' => ['nope']]);

        $this->assertSame(Client::DEFAULT_BASE_URL, self::property($this->transport(), 'baseUrl'));
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function unusableTimeouts(): array
    {
        return ['empty string' => [''], 'zero' => [0], 'negative' => [-5], 'non-numeric' => ['soon']];
    }

    #[DataProvider('unusableTimeouts')]
    public function test_unusable_timeout_falls_back_to_30_seconds(mixed $timeout): void
    {
        config(['fraudnova.timeout' => $timeout]);

        $guzzle = self::property($this->transport(), 'httpClient');
        $this->assertInstanceOf(GuzzleClient::class, $guzzle);
        $this->assertSame(30.0, $guzzle->getConfig('timeout'));
    }

    public function test_non_numeric_retry_attempts_means_no_retry(): void
    {
        config(['fraudnova.retry_max_attempts' => 'lots']);

        $this->assertNull(self::property($this->transport(), 'retry'));
    }
}
