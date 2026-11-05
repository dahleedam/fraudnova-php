<?php

declare(strict_types=1);

namespace FraudNova\Result;

final readonly class PhoneResult
{
    use ComputesFlags;

    /** @var list<string> */
    public const DEFAULT_FLAG_ON = ['VOIP', 'risky'];

    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public int $fraudScore,
        public ?bool $valid,
        public ?bool $recentAbuse,
        public ?bool $voip,
        public ?bool $prepaid,
        public ?bool $risky,
        public ?bool $active,
        public ?bool $doNotCall,
        public ?bool $leaked,
        public ?bool $spammer,
        public ?int $dialingCode,
        public ?string $carrier,
        public ?string $lineType,
        public ?string $country,
        public ?string $region,
        public ?string $city,
        public ?string $timezone,
        public ?string $zipCode,
        public ?string $activeStatus,
        public ?string $formatted,
        public ?string $localFormat,
        public ?int $creditsRemaining,
        private array $raw,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data, ?int $creditsRemaining = null): self
    {
        return new self(
            Coerce::int($data['fraud_score'] ?? null) ?? 0,
            Coerce::bool($data['valid'] ?? null),
            Coerce::bool($data['recent_abuse'] ?? null),
            Coerce::bool($data['VOIP'] ?? null),
            Coerce::bool($data['prepaid'] ?? null),
            Coerce::bool($data['risky'] ?? null),
            Coerce::bool($data['active'] ?? null),
            Coerce::bool($data['do_not_call'] ?? null),
            Coerce::bool($data['leaked'] ?? null),
            Coerce::bool($data['spammer'] ?? null),
            Coerce::int($data['dialing_code'] ?? null),
            Coerce::string($data['carrier'] ?? null),
            Coerce::string($data['line_type'] ?? null),
            Coerce::string($data['country'] ?? null),
            Coerce::string($data['region'] ?? null),
            Coerce::string($data['city'] ?? null),
            Coerce::string($data['timezone'] ?? null),
            Coerce::string($data['zip_code'] ?? null),
            Coerce::string($data['active_status'] ?? null),
            Coerce::string($data['formatted'] ?? null),
            Coerce::string($data['local_format'] ?? null),
            $creditsRemaining,
            $data,
        );
    }
}
