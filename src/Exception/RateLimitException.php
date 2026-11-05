<?php

declare(strict_types=1);

namespace FraudNova\Exception;

final class RateLimitException extends ApiException
{
    /**
     * @param  array<string, mixed>  $body
     */
    public function __construct(
        int $statusCode,
        string $apiMessage,
        array $body = [],
        private readonly ?int $retryAfter = null,
        ?string $message = null,
    ) {
        parent::__construct($statusCode, $apiMessage, $body, $message);
    }

    public function retryAfter(): ?int
    {
        return $this->retryAfter;
    }

    public function isConcurrencyLimit(): bool
    {
        return str_contains($this->apiMessage(), 'Too many concurrent requests');
    }
}
