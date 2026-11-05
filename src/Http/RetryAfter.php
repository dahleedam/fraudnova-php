<?php

declare(strict_types=1);

namespace FraudNova\Http;

use DateTimeImmutable;
use DateTimeZone;

final class RetryAfter
{
    /** Same pattern as DATE_RFC7231, which PHP 8.5 deprecates. */
    private const HTTP_DATE = 'D, d M Y H:i:s \\G\\M\\T';

    public static function parse(?string $header, ?DateTimeImmutable $now = null): ?int
    {
        if ($header === null) {
            return null;
        }

        $value = trim($header);
        if ($value === '') {
            return null;
        }

        if (preg_match('/^\d+$/', $value) === 1) {
            return (int) $value;
        }

        $utc = new DateTimeZone('UTC');
        $date = DateTimeImmutable::createFromFormat(self::HTTP_DATE, $value, $utc);
        if ($date === false) {
            return null;
        }

        $nowTs = ($now ?? new DateTimeImmutable('now', $utc))->getTimestamp();

        return max(0, $date->getTimestamp() - $nowTs);
    }
}
