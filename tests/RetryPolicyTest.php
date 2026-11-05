<?php

declare(strict_types=1);

namespace FraudNova\Tests;

use FraudNova\Exception\InvalidArgumentException;
use FraudNova\Exception\RateLimitException;
use FraudNova\RetryPolicy;
use PHPUnit\Framework\TestCase;

final class RetryPolicyTest extends TestCase
{
    private function concurrency(?int $retryAfter = null): RateLimitException
    {
        return new RateLimitException(429, 'Too many concurrent requests for this account. Please retry.', [], $retryAfter);
    }

    private function rateLimit(?int $retryAfter = null): RateLimitException
    {
        return new RateLimitException(429, 'Rate limit exceeded. Try again shortly.', [], $retryAfter);
    }

    public function test_concurrency_schedule(): void
    {
        $policy = new RetryPolicy(maxAttempts: 4);

        $this->assertSame(1.0, $policy->delayFor($this->concurrency(), 1));
        $this->assertSame(2.0, $policy->delayFor($this->concurrency(), 2));
        $this->assertSame(4.0, $policy->delayFor($this->concurrency(), 3));
        $this->assertNull($policy->delayFor($this->concurrency(), 4));
    }

    public function test_rate_limit_schedule(): void
    {
        $policy = new RetryPolicy(maxAttempts: 4);

        $this->assertSame(5.0, $policy->delayFor($this->rateLimit(), 1));
        $this->assertSame(15.0, $policy->delayFor($this->rateLimit(), 2));
        $this->assertSame(40.0, $policy->delayFor($this->rateLimit(), 3));
    }

    public function test_retry_after_wins_when_honoured(): void
    {
        $this->assertSame(9.0, (new RetryPolicy)->delayFor($this->rateLimit(9), 1));
    }

    public function test_retry_after_ignored_when_not_honoured(): void
    {
        $this->assertSame(5.0, (new RetryPolicy(honourRetryAfter: false))->delayFor($this->rateLimit(9), 1));
    }

    public function test_single_attempt_never_retries(): void
    {
        $this->assertNull((new RetryPolicy(maxAttempts: 1))->delayFor($this->rateLimit(9), 1));
    }

    public function test_zero_attempts_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new RetryPolicy(maxAttempts: 0);
    }

    public function test_sleep_uses_injected_sleeper(): void
    {
        $slept = [];
        $policy = new RetryPolicy(sleeper: function (float $s) use (&$slept): void {
            $slept[] = $s;
        });

        $policy->sleep(2.5);

        $this->assertSame([2.5], $slept);
    }
}
