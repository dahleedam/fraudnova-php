<?php

declare(strict_types=1);

namespace FraudNova\Result;

final readonly class EmailResult
{
    use ComputesFlags;

    /** @var list<string> */
    public const DEFAULT_FLAG_ON = ['disposable'];

    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public int $fraudScore,
        public ?bool $valid,
        public ?bool $disposable,
        public ?bool $catchAll,
        public ?bool $generic,
        public ?bool $common,
        public ?bool $dnsValid,
        public ?bool $honeypot,
        public ?bool $frequentComplainer,
        public ?bool $suspect,
        public ?bool $recentAbuse,
        public ?bool $leaked,
        public ?bool $riskyTld,
        public ?bool $spfRecord,
        public ?bool $dmarcRecord,
        public ?int $smtpScore,
        public ?int $overallScore,
        public ?string $deliverability,
        public ?string $spamTrapScore,
        public ?string $resultType,
        public ?string $sanitizedEmail,
        public ?string $firstSeen,
        public ?string $domainAge,
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
            Coerce::bool($data['disposable'] ?? null),
            Coerce::bool($data['catch_all'] ?? null),
            Coerce::bool($data['generic'] ?? null),
            Coerce::bool($data['common'] ?? null),
            Coerce::bool($data['dns_valid'] ?? null),
            Coerce::bool($data['honeypot'] ?? null),
            Coerce::bool($data['frequent_complainer'] ?? null),
            Coerce::bool($data['suspect'] ?? null),
            Coerce::bool($data['recent_abuse'] ?? null),
            Coerce::bool($data['leaked'] ?? null),
            Coerce::bool($data['risky_tld'] ?? null),
            Coerce::bool($data['spf_record'] ?? null),
            Coerce::bool($data['dmarc_record'] ?? null),
            Coerce::int($data['smtp_score'] ?? null),
            Coerce::int($data['overall_score'] ?? null),
            Coerce::string($data['deliverability'] ?? null),
            Coerce::string($data['spam_trap_score'] ?? null),
            Coerce::string($data['result_type'] ?? null),
            Coerce::string($data['sanitized_email'] ?? null),
            Coerce::dateString($data['first_seen'] ?? null, 'iso'),
            Coerce::dateString($data['domain_age'] ?? null, 'human'),
            $creditsRemaining,
            $data,
        );
    }
}
