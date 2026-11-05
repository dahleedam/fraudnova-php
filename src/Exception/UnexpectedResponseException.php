<?php

declare(strict_types=1);

namespace FraudNova\Exception;

final class UnexpectedResponseException extends ApiException
{
    /**
     * @param  array<string, mixed>  $body
     */
    public function __construct(int $statusCode, string $apiMessage, array $body = [], ?string $message = null)
    {
        parent::__construct(
            $statusCode,
            $apiMessage,
            $body,
            $message ?? sprintf('FraudNova returned an unexpected response (HTTP %d).', $statusCode),
        );
    }
}
