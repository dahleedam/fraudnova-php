<?php

declare(strict_types=1);

namespace FraudNova\Tests;

use FraudNova\Client;
use PHPUnit\Framework\TestCase;

final class ReleaseConsistencyTest extends TestCase
{
    public function test_client_version_matches_the_latest_changelog_release(): void
    {
        $changelog = file_get_contents(dirname(__DIR__).'/CHANGELOG.md');
        self::assertIsString($changelog);

        if (preg_match('/^## \[(\d+\.\d+\.\d+)\]/m', $changelog, $match) !== 1) {
            self::fail('CHANGELOG.md has no released version heading');
        }

        self::assertSame($match[1], Client::VERSION);
    }
}
