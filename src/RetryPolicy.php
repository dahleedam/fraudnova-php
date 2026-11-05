<?php

declare(strict_types=1);

namespace FraudNova;

use FraudNova\Exception\InvalidArgumentException;
use FraudNova\Exception\RateLimitException;

/**
 * Opt-in retry behaviour for HTTP 429 responses. Only 429 is ever retried.
 */
final readonly class RetryPolicy
{
    /**
     * @param  list<int|float>  $concurrencyDelays  seconds to wait after each failed attempt on a concurrency-cap 429
     * @param  list<int|float>  $rateLimitDelays  seconds to wait after each failed attempt on a rate-limit 429
     * @param  ?\Closure(float): void  $sleeper  replaces usleep, mainly for tests
     */
    public function __construct(
        public int $maxAttempts = 3,
        public array $concurrencyDelays = [1, 2, 4],
        public array $rateLimitDelays = [5, 15, 40],
        public bool $honourRetryAfter = true,
        public ?\Closure $sleeper = null,
    ) {
        if ($maxAttempts < 1) {
            throw new InvalidArgumentException('maxAttempts must be at least 1');
        }
    }

    /**
     * @param  int  $attempt  1-based number of the attempt that just failed
     */
    public function delayFor(RateLimitException $e, int $attempt): ?float
    {
        if ($attempt >= $this->maxAttempts) {
            return null;
        }

        if ($this->honourRetryAfter && $e->retryAfter() !== null) {
            return (float) $e->retryAfter();
        }

        $schedule = $e->isConcurrencyLimit() ? $this->concurrencyDelays : $this->rateLimitDelays;
        $delay = $schedule[$attempt - 1] ?? null;

        return $delay === null ? null : (float) $delay;
    }

    public function sleep(float $seconds): void
    {
        if ($this->sleeper !== null) {
            ($this->sleeper)($seconds);

            return;
        }

        if ($seconds > 0) {
            usleep((int) round($seconds * 1_000_000));
        }
    }
}
