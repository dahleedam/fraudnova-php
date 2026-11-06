<?php

declare(strict_types=1);

namespace FraudNova\Tests\Laravel;

use FraudNova\Client;
use FraudNova\Exception\InvalidArgumentException;
use FraudNova\Laravel\Facades\FraudNova;
use FraudNova\Laravel\FraudNovaServiceProvider;
use FraudNova\Tests\Support\FakeHttpClient;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Nyholm\Psr7\Factory\Psr17Factory;
use Orchestra\Testbench\TestCase;

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
}
