<?php

declare(strict_types=1);

namespace FraudNova\Result;

/**
 * Shared flag helpers. The using class provides `private array $raw` and
 * `public const DEFAULT_FLAG_ON`.
 */
trait ComputesFlags
{
    /**
     * @param  list<string>|null  $flagOn
     * @return list<string>
     */
    public function flagReasons(int $riskThreshold = 75, ?array $flagOn = null): array
    {
        $reasons = [];

        if ((Coerce::int($this->raw['fraud_score'] ?? null) ?? 0) >= $riskThreshold) {
            $reasons[] = 'fraud_score';
        }

        foreach ($flagOn ?? static::DEFAULT_FLAG_ON as $name) {
            if (($this->raw[$name] ?? null) === true) {
                $reasons[] = $name;
            }
        }

        return $reasons;
    }

    /**
     * @param  list<string>|null  $flagOn
     */
    public function flagged(int $riskThreshold = 75, ?array $flagOn = null): bool
    {
        return $this->flagReasons($riskThreshold, $flagOn) !== [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->raw;
    }
}
