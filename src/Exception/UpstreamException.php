<?php

declare(strict_types=1);

namespace FraudNova\Exception;

final class UpstreamException extends ApiException
{
    /**
     * @param  array<string, mixed>  $body
     */
    public function __construct(
        int $statusCode,
        string $apiMessage,
        array $body = [],
        private readonly bool $creditRefunded = false,
        ?string $message = null,
    ) {
        $message ??= sprintf('FraudNova API error (HTTP %d): %s', $statusCode, $apiMessage)
            .($creditRefunded ? ' The credit for this check was refunded.' : '');

        parent::__construct($statusCode, $apiMessage, $body, $message);
    }

    public function creditRefunded(): bool
    {
        return $this->creditRefunded;
    }
}
