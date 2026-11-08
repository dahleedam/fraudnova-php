<?php

declare(strict_types=1);

namespace FraudNova;

use FraudNova\Exception\InvalidArgumentException;
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
    public const VERSION = '1.0.1';

    public const DEFAULT_BASE_URL = 'https://fraudnova.com/api/v1';

    public const BATCH_MAX = 100;

    private readonly Transport $transport;

    public function __construct(
        #[\SensitiveParameter] string $apiKey,
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        string $baseUrl = self::DEFAULT_BASE_URL,
        ?RetryPolicy $retry = null,
    ) {
        $apiKey = trim($apiKey);

        if ($apiKey === '') {
            throw new InvalidArgumentException('API key must not be empty');
        }

        $baseUrl = trim($baseUrl);

        if (preg_match('#^https?://#i', $baseUrl) !== 1) {
            throw new InvalidArgumentException('baseUrl must be an absolute http:// or https:// URL');
        }

        $this->transport = new Transport($apiKey, $baseUrl, $httpClient, $requestFactory, $streamFactory, $retry);
    }

    /**
     * Keeps the API key out of var_dump() and print_r() output.
     *
     * @return array{transport: Transport}
     */
    public function __debugInfo(): array
    {
        return ['transport' => $this->transport];
    }

    public function ip(string $ip, ?IpOptions $options = null): IpResult
    {
        $query = ['ip' => $this->requireSubject($ip, 'ip')] + ($options?->toQuery() ?? []);
        $envelope = $this->transport->get('/check', $query, true, self::requireData(...));

        return IpResult::fromArray(self::data($envelope), Coerce::int($envelope['credits_remaining'] ?? null));
    }

    public function email(string $email): EmailResult
    {
        $envelope = $this->transport->get('/check/email', ['email' => $this->requireSubject($email, 'email')], true, self::requireData(...));

        return EmailResult::fromArray(self::data($envelope), Coerce::int($envelope['credits_remaining'] ?? null));
    }

    public function phone(string $phone, ?string $country = null): PhoneResult
    {
        $query = ['phone' => $this->requireSubject($phone, 'phone')];
        $country = $this->normalizeCountry($country);
        if ($country !== null) {
            $query['country'] = $country;
        }

        $envelope = $this->transport->get('/check/phone', $query, true, self::requireData(...));

        return PhoneResult::fromArray(self::data($envelope), Coerce::int($envelope['credits_remaining'] ?? null));
    }

    /**
     * @param  list<string|null>  $ips
     */
    public function batchIp(array $ips, ?IpOptions $options = null): BatchResult
    {
        $body = ['ips' => $this->requireBatch($ips)] + ($options?->toJson() ?? []);

        return BatchResult::fromArray($this->transport->post('/check/batch', $body, self::requireResults(...)), 'ip');
    }

    /**
     * @param  list<string|null>  $emails
     */
    public function batchEmail(array $emails): BatchResult
    {
        $body = ['emails' => $this->requireBatch($emails)];

        return BatchResult::fromArray($this->transport->post('/check/email/batch', $body, self::requireResults(...)), 'email');
    }

    /**
     * @param  list<string|null>  $phones
     */
    public function batchPhone(array $phones, ?string $country = null): BatchResult
    {
        $body = ['phones' => $this->requireBatch($phones)];
        $country = $this->normalizeCountry($country);
        if ($country !== null) {
            $body['country'] = $country;
        }

        return BatchResult::fromArray($this->transport->post('/check/phone/batch', $body, self::requireResults(...)), 'phone');
    }

    public function balance(): int
    {
        $envelope = $this->transport->get('/balance', [], true, static fn (array $e): ?string => Coerce::int($e['credits_remaining'] ?? null) === null ? 'Missing credits_remaining' : null);

        return Coerce::int($envelope['credits_remaining'] ?? null) ?? 0;
    }

    public function myIp(): string
    {
        $envelope = $this->transport->get('/my-ip', [], false, static fn (array $e): ?string => is_string($e['ip'] ?? null) && $e['ip'] !== '' ? null : 'Missing ip');

        return Coerce::string($envelope['ip'] ?? null) ?? '';
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
     * @return list<string|null>
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
            if ($item !== null && ! is_string($item)) {
                throw new InvalidArgumentException(sprintf('Batch item #%d must be a string or null', $index));
            }

            // Empty and null items are sent as-is; the API answers them with a per-item invalid_* error.
            $clean[] = $item === null ? null : trim($item);
        }

        return $clean;
    }

    /**
     * @param  array<string, mixed>  $envelope
     */
    private static function requireData(array $envelope): ?string
    {
        return is_array($envelope['data'] ?? null) ? null : 'Missing data';
    }

    /**
     * @param  array<string, mixed>  $envelope
     */
    private static function requireResults(array $envelope): ?string
    {
        $results = $envelope['results'] ?? null;

        return is_array($results) && array_is_list($results) ? null : 'Missing results';
    }

    /**
     * @param  array<string, mixed>  $envelope  already checked by requireData()
     * @return array<string, mixed>
     */
    private static function data(array $envelope): array
    {
        /** @var array<string, mixed> $data */
        $data = $envelope['data'];

        return $data;
    }
}
