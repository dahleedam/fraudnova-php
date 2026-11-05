<?php

declare(strict_types=1);

namespace FraudNova\Result;

/**
 * @implements \IteratorAggregate<int, BatchItem>
 */
final readonly class BatchResult implements \Countable, \IteratorAggregate
{
    /**
     * @param  list<BatchItem>  $items
     */
    public function __construct(
        public array $items,
        public int $creditsRemaining,
    ) {}

    /**
     * @param  array<string, mixed>  $envelope
     * @param  'ip'|'email'|'phone'  $type
     */
    public static function fromArray(array $envelope, string $type): self
    {
        $rawItems = $envelope['results'] ?? [];
        $items = [];

        if (is_array($rawItems)) {
            foreach ($rawItems as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $success = Coerce::bool($item['success'] ?? null) ?? false;
                $data = $item['data'] ?? null;
                $result = null;

                if ($success && is_array($data)) {
                    /** @var array<string, mixed> $data */
                    $result = match ($type) {
                        'ip' => IpResult::fromArray($data),
                        'email' => EmailResult::fromArray($data),
                        'phone' => PhoneResult::fromArray($data),
                    };
                }

                $items[] = new BatchItem(
                    Coerce::string($item[$type] ?? null) ?? '',
                    $success,
                    $result,
                    Coerce::string($item['error'] ?? null),
                );
            }
        }

        return new self($items, Coerce::int($envelope['credits_remaining'] ?? null) ?? 0);
    }

    /**
     * @return list<BatchItem>
     */
    public function successful(): array
    {
        return array_values(array_filter($this->items, static fn (BatchItem $i): bool => $i->success));
    }

    /**
     * @return list<BatchItem>
     */
    public function failed(): array
    {
        return array_values(array_filter($this->items, static fn (BatchItem $i): bool => ! $i->success));
    }

    public function hasFailures(): bool
    {
        return $this->failed() !== [];
    }

    public function count(): int
    {
        return count($this->items);
    }

    /**
     * @return \ArrayIterator<int, BatchItem>
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->items);
    }
}
