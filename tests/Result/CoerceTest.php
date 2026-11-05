<?php

declare(strict_types=1);

namespace FraudNova\Tests\Result;

use FraudNova\Result\Coerce;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CoerceTest extends TestCase
{
    /** @return array<string, array{mixed, ?int}> */
    public static function ints(): array
    {
        return [
            'int' => [5, 5], 'negative string' => ['-7', -7], 'string' => ['12', 12],
            'whole float' => [3.0, 3], 'fraction string' => ['1.5', null], 'fraction float' => [1.5, null],
            'nan' => [NAN, null], 'inf' => [INF, null], 'bool' => [true, null], 'null' => [null, null],
            'array' => [[1], null], 'text' => ['abc', null], 'empty' => ['', null],
        ];
    }

    #[DataProvider('ints')]
    public function test_int(mixed $in, ?int $out): void
    {
        $this->assertSame($out, Coerce::int($in));
    }

    /** @return array<string, array{mixed, ?bool}> */
    public static function bools(): array
    {
        return ['true' => [true, true], 'false' => [false, false], 'one' => [1, null], 'string' => ['true', null], 'null' => [null, null]];
    }

    #[DataProvider('bools')]
    public function test_bool(mixed $in, ?bool $out): void
    {
        $this->assertSame($out, Coerce::bool($in));
    }

    public function test_string(): void
    {
        $this->assertSame('a', Coerce::string('a'));
        $this->assertNull(Coerce::string(1));
        $this->assertNull(Coerce::string(['a']));
    }

    public function test_date_string(): void
    {
        $obj = ['human' => '9 years', 'timestamp' => 1, 'iso' => '2017-01-01'];
        $this->assertSame('2017-01-01', Coerce::dateString($obj, 'iso'));
        $this->assertSame('9 years', Coerce::dateString($obj, 'human'));
        $this->assertSame('2017-01-01', Coerce::dateString(['iso' => '2017-01-01'], 'human'));
        $this->assertSame('x', Coerce::dateString('x', 'iso'));
        $this->assertNull(Coerce::dateString(['timestamp' => 1], 'iso'));
        $this->assertNull(Coerce::dateString(['iso' => ''], 'iso'));
        $this->assertNull(Coerce::dateString(5, 'iso'));
    }
}
