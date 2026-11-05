<?php

declare(strict_types=1);

namespace FraudNova\Exception;

use FraudNova\Http\RetryAfter;
use Psr\Http\Message\ResponseInterface;

class ApiException extends \RuntimeException implements FraudNovaException
{
    /**
     * @param  array<string, mixed>  $body
     */
    public function __construct(
        private readonly int $statusCode,
        private readonly string $apiMessage,
        private readonly array $body = [],
        ?string $message = null,
    ) {
        parent::__construct($message ?? sprintf('FraudNova API error (HTTP %d): %s', $statusCode, $apiMessage), $statusCode);
    }

    public static function fromResponse(ResponseInterface $response): self
    {
        $status = $response->getStatusCode();
        $raw = (string) $response->getBody();

        $decoded = json_decode($raw, true);
        /** @var array<string, mixed> $body */
        $body = is_array($decoded) ? $decoded : [];

        $message = $body['message'] ?? null;
        if (is_string($message) && $message !== '') {
            $apiMessage = $message;
        } else {
            $trimmed = trim($raw);
            $apiMessage = $trimmed !== '' ? mb_substr($trimmed, 0, 200) : 'HTTP '.$status;
        }

        return match (true) {
            $status === 401 => new AuthenticationException($status, $apiMessage, $body),
            $status === 402 => new InsufficientCreditsException($status, $apiMessage, $body),
            $status === 403 => new AccountSuspendedException($status, $apiMessage, $body),
            $status === 422 => new ValidationException($status, $apiMessage, $body),
            $status === 429 => new RateLimitException(
                $status,
                $apiMessage,
                $body,
                RetryAfter::parse($response->getHeaderLine('Retry-After') ?: null),
            ),
            $status >= 502 && $status <= 504 => new UpstreamException($status, $apiMessage, $body, $status === 503 && $body !== []),
            default => new self($status, $apiMessage, $body),
        };
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    public function apiMessage(): string
    {
        return $this->apiMessage;
    }

    /**
     * @return array<string, mixed>
     */
    public function body(): array
    {
        return $this->body;
    }
}
