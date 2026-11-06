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
        $isJson = is_array($decoded);
        /** @var array<string, mixed> $body */
        $body = $isJson ? $decoded : [];
        $envelope = $isJson && self::isEnvelope($body);

        $message = $body['message'] ?? null;
        if (is_string($message) && $message !== '') {
            $apiMessage = $message;
        } else {
            $trimmed = trim($raw);
            $apiMessage = $trimmed !== '' ? self::truncate($trimmed, 200) : 'HTTP '.$status;
        }

        $fullMessage = $isJson ? null : sprintf('FraudNova API error (HTTP %d): non-JSON response', $status);
        $retryHeader = $response->getHeaderLine('Retry-After');

        return match (true) {
            $envelope && $status === 401 => new AuthenticationException($status, $apiMessage, $body),
            $envelope && $status === 402 => new InsufficientCreditsException($status, $apiMessage, $body),
            $envelope && $status === 403 => new AccountSuspendedException($status, $apiMessage, $body),
            $envelope && $status === 422 => new ValidationException($status, $apiMessage, $body),
            $status === 429 => new RateLimitException(
                $status,
                $apiMessage,
                $body,
                RetryAfter::parse($retryHeader === '' ? null : $retryHeader),
                $fullMessage,
            ),
            $status >= 502 && $status <= 504 => new UpstreamException($status, $apiMessage, $body, $status === 503 && $envelope, $fullMessage),
            default => new self($status, $apiMessage, $body, $fullMessage),
        };
    }

    /**
     * True when the decoded body is the FraudNova API envelope rather than a proxy or gateway page.
     *
     * @param  array<mixed>|null  $body
     */
    private static function isEnvelope(?array $body): bool
    {
        return $body !== null && (array_key_exists('success', $body) || array_key_exists('message', $body));
    }

    private static function truncate(string $value, int $length): string
    {
        return function_exists('mb_substr') ? mb_substr($value, 0, $length) : substr($value, 0, $length);
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
