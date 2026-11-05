<?php

declare(strict_types=1);

namespace FraudNova\Tests;

use FraudNova\Exception\InvalidArgumentException;
use FraudNova\IpOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IpOptionsTest extends TestCase
{
    public function test_defaults_produce_empty_arrays(): void
    {
        $options = new IpOptions;

        $this->assertSame([], $options->toQuery());
        $this->assertSame([], $options->toJson());
    }

    public function test_full_options_map_to_query_and_json(): void
    {
        $options = new IpOptions(strictness: 3, mobile: true, userAgent: 'UA', userLanguage: 'en-US');

        $this->assertSame(['strictness' => 3, 'mobile' => 'true', 'user_agent' => 'UA', 'user_language' => 'en-US'], $options->toQuery());
        $this->assertSame(['strictness' => 3, 'mobile' => true, 'user_agent' => 'UA', 'user_language' => 'en-US'], $options->toJson());
    }

    public function test_mobile_false_is_sent_as_false(): void
    {
        $options = new IpOptions(mobile: false);

        $this->assertSame(['mobile' => 'false'], $options->toQuery());
        $this->assertSame(['mobile' => false], $options->toJson());
    }

    #[DataProvider('outOfRange')]
    public function test_strictness_out_of_range_throws(int $strictness): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('strictness must be between 0 and 3');

        new IpOptions(strictness: $strictness);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function outOfRange(): iterable
    {
        yield 'above' => [4];
        yield 'below' => [-1];
    }
}
