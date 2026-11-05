<?php

declare(strict_types=1);

namespace FraudNova;

use FraudNova\Exception\InvalidArgumentException;

final readonly class IpOptions
{
    public function __construct(
        public ?int $strictness = null,
        public ?bool $mobile = null,
        public ?string $userAgent = null,
        public ?string $userLanguage = null,
    ) {
        if ($strictness !== null && ($strictness < 0 || $strictness > 3)) {
            throw new InvalidArgumentException('strictness must be between 0 and 3');
        }
    }

    /**
     * @return array<string, string|int>
     */
    public function toQuery(): array
    {
        $query = $this->toJson();

        if (isset($query['mobile'])) {
            $query['mobile'] = $query['mobile'] ? 'true' : 'false';
        }

        /** @var array<string, string|int> $query */
        return $query;
    }

    /**
     * @return array<string, string|int|bool>
     */
    public function toJson(): array
    {
        return array_filter([
            'strictness' => $this->strictness,
            'mobile' => $this->mobile,
            'user_agent' => $this->userAgent,
            'user_language' => $this->userLanguage,
        ], static fn (string|int|bool|null $v): bool => $v !== null);
    }
}
