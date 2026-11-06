<?php

declare(strict_types=1);

namespace FraudNova\Laravel;

use FraudNova\Client;
use FraudNova\Exception\InvalidArgumentException;
use FraudNova\Http\HttpClientDiscovery;
use FraudNova\RetryPolicy;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

final class FraudNovaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/fraudnova.php', 'fraudnova');

        $this->app->singleton(Client::class, function (Application $app): Client {
            $config = $app->make(Repository::class);

            $key = trim($this->stringValue($config->get('fraudnova.api_key')));

            if ($key === '') {
                throw new InvalidArgumentException('Set FRAUDNOVA_API_KEY (config fraudnova.api_key) to your FraudNova API key.');
            }

            $timeout = $config->get('fraudnova.timeout');
            $timeout = is_numeric($timeout) && (float) $timeout > 0 ? (float) $timeout : 30.0;

            $attempts = $config->get('fraudnova.retry_max_attempts');
            $n = is_numeric($attempts) ? (int) $attempts : 0;

            $baseUrl = $config->get('fraudnova.base_url');
            $baseUrl = is_string($baseUrl) && trim($baseUrl) !== '' ? $baseUrl : Client::DEFAULT_BASE_URL;

            $discovery = new HttpClientDiscovery;

            return new Client(
                $key,
                $discovery->client($timeout),
                $discovery->requestFactory(),
                $discovery->streamFactory(),
                $baseUrl,
                $n >= 2 ? new RetryPolicy(maxAttempts: $n) : null,
            );
        });

        $this->app->alias(Client::class, 'fraudnova');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../../config/fraudnova.php' => $this->app->configPath('fraudnova.php'),
            ], 'fraudnova-config');
        }
    }

    private function stringValue(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
