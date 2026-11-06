<?php

declare(strict_types=1);

namespace FraudNova\Result;

/**
 * Lenient value coercion: a mistyped value becomes null, never a TypeError.
 *
 * @internal
 */
final class Coerce
{
    public static function bool(mixed $v): ?bool
    {
        return is_bool($v) ? $v : null;
    }

    /**
     * Integers and whole-number strings as-is; floats and decimal strings rounded half up.
     */
    public static function int(mixed $v): ?int
    {
        if (is_int($v)) {
            return $v;
        }

        if (is_string($v)) {
            if (preg_match('/^-?\d+\z/', $v) === 1) {
                return (int) $v;
            }

            if (preg_match('/^-?\d+\.\d+\z/', $v) !== 1) {
                return null;
            }

            $v = (float) $v;
        }

        // A fractional number rounds half up (87.5 becomes 88).
        if (is_float($v) && is_finite($v) && abs($v) < 9.2e18) {
            return (int) round($v);
        }

        return null;
    }

    public static function string(mixed $v): ?string
    {
        return is_string($v) ? $v : null;
    }

    public static function dateString(mixed $v, string $preferredKey): ?string
    {
        if (is_string($v)) {
            return $v;
        }

        if (! is_array($v)) {
            return null;
        }

        foreach (array_unique([$preferredKey, 'iso', 'human']) as $key) {
            $candidate = $v[$key] ?? null;
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }
}
