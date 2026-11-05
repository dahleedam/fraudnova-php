<?php

declare(strict_types=1);

namespace FraudNova\Tests\Http;

use DateTimeImmutable;
use DateTimeZone;
use FraudNova\Http\RetryAfter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RetryAfterTest extends TestCase
{
    /**
     * @return array<string, array{?string, ?int}>
     */
    public static function plainValues(): array
    {
        return [
            'seven' => ['7', 7],
            'zero' => ['0', 0],
            'padded' => [' 12 ', 12],
            'negative' => ['-3', null],
            'garbage' => ['abc', null],
            'null' => [null, null],
        ];
    }

    #[DataProvider('plainValues')]
    public function test_parses_plain_values(?string $header, ?int $expected): void
    {
        self::assertSame($expected, RetryAfter::parse($header));
    }

    public function test_http_date_in_the_future_is_a_delta(): void
    {
        $now = new DateTimeImmutable('2026-11-06 12:00:00', new DateTimeZone('UTC'));

        self::assertSame(30, RetryAfter::parse('Fri, 06 Nov 2026 12:00:30 GMT', $now));
    }

    public function test_http_date_in_the_past_is_zero(): void
    {
        $now = new DateTimeImmutable('2026-11-06 12:00:00', new DateTimeZone('UTC'));

        self::assertSame(0, RetryAfter::parse('Fri, 06 Nov 2026 11:00:00 GMT', $now));
    }
}
