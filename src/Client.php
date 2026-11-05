<?php

declare(strict_types=1);

namespace FraudNova;

use FraudNova\Exception\InvalidArgumentException;
use FraudNova\Exception\UnexpectedResponseException;
use FraudNova\Http\Transport;
use FraudNova\Result\BatchResult;
use FraudNova\Result\Coerce;
use FraudNova\Result\EmailResult;
use FraudNova\Result\IpResult;
use FraudNova\Result\PhoneResult;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

final class Client
{
    public const VERSION = '1.0.0';

    public const DEFAULT_BASE_URL = 'https://fraudnova.com/api/v1';

    public const BATCH_MAX = 100;

    private readonly Transport $transport;

    public function __construct(
        #[\SensitiveParameter] string $apiKey,
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        string $baseUrl = self::DEFAULT_BASE_URL,
        mixed $retry = null,
    ) {
        $apiKey = trim($apiKey);

        if ($apiKey === '') {
            throw new InvalidArgumentException('API key must not be empty');
        }

        $this->transport = new Transport($apiKey, $baseUrl, $httpClient, $requestFactory, $streamFactory, $retry);
    }

    public function ip(string $ip, ?IpOptions $options = null): IpResult
    {
        $query = ['ip' => $this->requireSubject($ip, 'ip')] + ($options?->toQuery() ?? []);
        $envelope = $this->transport->get('/check', $query);

        return IpResult::fromArray($this->dataFrom($envelope), Coerce::int($envelope['credits_remaining'] ?? null));
    }

    public function email(string $email): EmailResult
    {
        $envelope = $this->transport->get('/check/email', ['email' => $this->requireSubject($email, 'email')]);

        return EmailResult::fromArray($this->dataFrom($envelope), Coerce::int($envelope['credits_remaining'] ?? null));
    }

    public function phone(string $phone, ?string $country = null): PhoneResult
    {
        $query = ['phone' => $this->requireSubject($phone, 'phone')];
        $country = $this->normalizeCountry($country);
        if ($country !== null) {
            $query['country'] = $country;
        }

        $envelope = $this->transport->get('/check/phone', $query);

        return PhoneResult::fromArray($this->dataFrom($envelope), Coerce::int($envelope['credits_remaining'] ?? null));
    }

    /**
     * @param  list<string>  $ips
     */
    public function batchIp(array $ips, ?IpOptions $options = null): BatchResult
    {
        $body = ['ips' => $this->requireBatch($ips)] + ($options?->toJson() ?? []);

        return BatchResult::fromArray($this->resultsEnvelope($this->transport->post('/check/batch', $body)), 'ip');
    }

    /**
     * @param  list<string>  $emails
     */
    public function batchEmail(array $emails): BatchResult
    {
        $body = ['emails' => $this->requireBatch($emails)];

        return BatchResult::fromArray($this->resultsEnvelope($this->transport->post('/check/email/batch', $body)), 'email');
    }

    /**
     * @param  list<string>  $phones
     */
    public function batchPhone(array $phones, ?string $country = null): BatchResult
    {
        $body = ['phones' => $this->requireBatch($phones)];
        $country = $this->normalizeCountry($country);
        if ($country !== null) {
            $body['country'] = $country;
        }

        return BatchResult::fromArray($this->resultsEnvelope($this->transport->post('/check/phone/batch', $body)), 'phone');
    }

    public function balance(): int
    {
        $envelope = $this->transport->get('/balance');
        $credits = Coerce::int($envelope['credits_remaining'] ?? null);

        if ($credits === null) {
            throw new UnexpectedResponseException(200, 'Missing credits_remaining', $envelope);
        }

        return $credits;
    }

    public function myIp(): string
    {
        $envelope = $this->transport->get('/my-ip', [], false);
        $ip = $envelope['ip'] ?? null;

        if (! is_string($ip) || $ip === '') {
            throw new UnexpectedResponseException(200, 'Missing ip', $envelope);
        }

        return $ip;
    }

    private function requireSubject(string $value, string $name): string
    {
        $value = trim($value);

        if ($value === '') {
            throw new InvalidArgumentException($name.' must not be empty');
        }

        return $value;
    }

    private function normalizeCountry(?string $country): ?string
    {
        $country = strtoupper(trim($country ?? ''));

        return $country === '' ? null : $country;
    }

    /**
     * @param  array<mixed>  $items
     * @return list<string>
     */
    private function requireBatch(array $items): array
    {
        if ($items === []) {
            throw new InvalidArgumentException('Batch must contain at least one item');
        }

        if (count($items) > self::BATCH_MAX) {
            throw new InvalidArgumentException(sprintf('Batch may contain at most %d items', self::BATCH_MAX));
        }

        $clean = [];
        foreach ($items as $index => $item) {
            $value = is_string($item) ? trim($item) : '';

            if ($value === '') {
                throw new InvalidArgumentException(sprintf('Batch item #%d must be a non-empty string', $index));
            }

            $clean[] = $value;
        }

        return $clean;
    }

    /**
     * @param  array<string, mixed>  $envelope
     * @return array<string, mixed>
     */
    private function dataFrom(array $envelope): array
    {
        $data = $envelope['data'] ?? null;

        if (! is_array($data)) {
            throw new UnexpectedResponseException(200, 'Missing data', $envelope);
        }

        /** @var array<string, mixed> $data */
        return $data;
    }

    /**
     * @param  array<string, mixed>  $envelope
     * @return array<string, mixed>
     */
    private function resultsEnvelope(array $envelope): array
    {
        if (! is_array($envelope['results'] ?? null) || ! array_is_list($envelope['results'])) {
            throw new UnexpectedResponseException(200, 'Missing results', $envelope);
        }

        return $envelope;
    }
}
