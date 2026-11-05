<?php

declare(strict_types=1);

namespace FraudNova\Result;

final readonly class BatchItem
{
    public function __construct(
        public string $subject,
        public bool $success,
        public IpResult|EmailResult|PhoneResult|null $result,
        public ?string $error,
    ) {}

    public function isInvalid(): bool
    {
        return in_array($this->error, ['invalid_ip', 'invalid_email', 'invalid_phone'], true);
    }

    public function isInsufficientCredits(): bool
    {
        return $this->error === 'insufficient_credits';
    }

    public function isUpstreamFailed(): bool
    {
        return $this->error === 'upstream_failed';
    }
}
