<?php

declare(strict_types=1);

namespace FraudNova\Tests\Http;

use FraudNova\Exception\RateLimitException;
use FraudNova\Exception\UpstreamException;
use FraudNova\Http\Transport;
use FraudNova\RetryPolicy;
use FraudNova\Tests\Support\FakeHttpClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;

final class TransportRetryTest extends TestCase
{
    /** @var list<float> */
    private array $sleeps = [];

    private function transport(FakeHttpClient $http, ?RetryPolicy $retry): Transport
    {
        $factory = new Psr17Factory;

        return new Transport('fn_test', 'https://fraudnova.com/api/v1', $http, $factory, $factory, $retry);
    }

    private function policy(int $maxAttempts = 3): RetryPolicy
    {
        return new RetryPolicy(maxAttempts: $maxAttempts, sleeper: function (float $s): void {
            $this->sleeps[] = $s;
        });
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function concurrency429(FakeHttpClient $http, array $headers = []): FakeHttpClient
    {
        return $http->json(429, ['success' => false, 'message' => 'Too many concurrent requests for this account. Please retry.'], $headers);
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function rateLimit429(FakeHttpClient $http, array $headers = []): FakeHttpClient
    {
        return $http->json(429, ['success' => false, 'message' => 'Rate limit exceeded. Try again shortly.'], $headers);
    }

    public function test_retries_429_then_succeeds(): void
    {
        $http = new FakeHttpClient;
        $this->concurrency429($http);
        $this->concurrency429($http);
        $http->json(200, ['success' => true, 'credits_remaining' => 3]);

        $envelope = $this->transport($http, $this->policy())->get('/balance');

        $this->assertCount(3, $http->requests);
        $this->assertSame([1.0, 2.0], $this->sleeps);
        $this->assertSame(3, $envelope['credits_remaining']);
    }

    public function test_gives_up_after_max_attempts(): void
    {
        $http = new FakeHttpClient;
        $this->rateLimit429($http);
        $this->rateLimit429($http);
        $this->rateLimit429($http);

        try {
            $this->transport($http, $this->policy())->get('/balance');
            $this->fail('Expected RateLimitException');
        } catch (RateLimitException) {
            $this->assertCount(3, $http->requests);
            $this->assertCount(2, $this->sleeps);
        }
    }

    public function test_retry_after_header_overrides_schedule(): void
    {
        $http = new FakeHttpClient;
        $this->rateLimit429($http, ['Retry-After' => '2']);
        $http->json(200, ['success' => true]);

        $this->transport($http, $this->policy())->get('/balance');

        $this->assertSame([2.0], $this->sleeps);
    }

    public function test_no_policy_means_no_retry(): void
    {
        $http = new FakeHttpClient;
        $this->rateLimit429($http);

        try {
            $this->transport($http, null)->get('/balance');
            $this->fail('Expected RateLimitException');
        } catch (RateLimitException) {
            $this->assertCount(1, $http->requests);
            $this->assertSame([], $this->sleeps);
        }
    }

    public function test_other_statuses_are_never_retried(): void
    {
        $http = (new FakeHttpClient)->json(503, ['success' => false, 'message' => 'Upstream lookup failed.']);

        try {
            $this->transport($http, $this->policy())->get('/balance');
            $this->fail('Expected UpstreamException');
        } catch (UpstreamException) {
            $this->assertCount(1, $http->requests);
            $this->assertSame([], $this->sleeps);
        }
    }

    public function test_post_body_is_resent_on_retry(): void
    {
        $http = new FakeHttpClient;
        $this->concurrency429($http);
        $http->json(200, ['success' => true]);

        $this->transport($http, $this->policy())->post('/check/batch', ['ips' => ['8.8.8.8', '1.1.1.1']]);

        $this->assertCount(2, $http->requests);
        $expected = '{"ips":["8.8.8.8","1.1.1.1"]}';
        $this->assertSame($expected, (string) $http->requests[0]->getBody());
        $this->assertSame($expected, (string) $http->requests[1]->getBody());
    }

    public function test_retry_after_above_the_cap_is_rethrown_without_sleeping(): void
    {
        $http = new FakeHttpClient;
        $this->rateLimit429($http, ['Retry-After' => '86400']);
        $http->json(200, ['success' => true]);

        try {
            $this->transport($http, $this->policy())->get('/balance');
            $this->fail('Expected RateLimitException');
        } catch (RateLimitException $e) {
            $this->assertSame(86400, $e->retryAfter());
            $this->assertCount(1, $http->requests);
            $this->assertSame([], $this->sleeps);
        }
    }

    public function test_retry_after_within_the_cap_is_honoured(): void
    {
        $http = new FakeHttpClient;
        $this->rateLimit429($http, ['Retry-After' => '30']);
        $http->json(200, ['success' => true]);

        $this->transport($http, $this->policy())->get('/balance');

        $this->assertCount(2, $http->requests);
        $this->assertSame([30.0], $this->sleeps);
    }
}
