<?php

declare(strict_types=1);

namespace FraudNova\Result;

final readonly class IpResult
{
    use ComputesFlags;

    /** @var list<string> */
    public const DEFAULT_FLAG_ON = ['proxy', 'vpn', 'tor'];

    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public int $fraudScore,
        public ?bool $proxy,
        public ?bool $vpn,
        public ?bool $tor,
        public ?bool $activeVpn,
        public ?bool $activeTor,
        public ?bool $recentAbuse,
        public ?bool $botStatus,
        public ?string $countryCode,
        public ?string $region,
        public ?string $city,
        public ?string $isp,
        public ?string $organization,
        public ?int $asn,
        public ?string $operatingSystem,
        public ?string $browser,
        public ?string $deviceBrand,
        public ?string $deviceModel,
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
            Coerce::bool($data['proxy'] ?? null),
            Coerce::bool($data['vpn'] ?? null),
            Coerce::bool($data['tor'] ?? null),
            Coerce::bool($data['active_vpn'] ?? null),
            Coerce::bool($data['active_tor'] ?? null),
            Coerce::bool($data['recent_abuse'] ?? null),
            Coerce::bool($data['bot_status'] ?? null),
            Coerce::string($data['country_code'] ?? null),
            Coerce::string($data['region'] ?? null),
            Coerce::string($data['city'] ?? null),
            Coerce::string($data['ISP'] ?? null),
            Coerce::string($data['organization'] ?? null),
            Coerce::int($data['ASN'] ?? null),
            Coerce::string($data['operating_system'] ?? null),
            Coerce::string($data['browser'] ?? null),
            Coerce::string($data['device_brand'] ?? null),
            Coerce::string($data['device_model'] ?? null),
            $creditsRemaining,
            $data,
        );
    }
}
