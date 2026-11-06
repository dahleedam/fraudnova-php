<?php

declare(strict_types=1);

namespace FraudNova\Tests\Result;

use FraudNova\Result\IpResult;
use PHPUnit\Framework\TestCase;

final class IpResultTest extends TestCase
{
    public function test_maps_every_documented_field(): void
    {
        $data = [
            'fraud_score' => 42, 'proxy' => true, 'vpn' => false, 'tor' => false, 'active_vpn' => true,
            'active_tor' => false, 'recent_abuse' => true, 'bot_status' => false,
            'country_code' => 'US', 'region' => 'California', 'city' => 'Mountain View',
            'ISP' => 'Example ISP', 'organization' => 'Example Org', 'ASN' => 64500,
            'operating_system' => 'Windows 10', 'browser' => 'Chrome', 'device_brand' => 'Acme', 'device_model' => 'X1',
        ];
        $r = IpResult::fromArray($data, 90);

        $this->assertSame(42, $r->fraudScore);
        $this->assertTrue($r->proxy);
        $this->assertFalse($r->vpn);
        $this->assertFalse($r->tor);
        $this->assertTrue($r->activeVpn);
        $this->assertFalse($r->activeTor);
        $this->assertTrue($r->recentAbuse);
        $this->assertFalse($r->botStatus);
        $this->assertSame('US', $r->countryCode);
        $this->assertSame('California', $r->region);
        $this->assertSame('Mountain View', $r->city);
        $this->assertSame('Example ISP', $r->isp);
        $this->assertSame('Example Org', $r->organization);
        $this->assertSame(64500, $r->asn);
        $this->assertSame('Windows 10', $r->operatingSystem);
        $this->assertSame('Chrome', $r->browser);
        $this->assertSame('Acme', $r->deviceBrand);
        $this->assertSame('X1', $r->deviceModel);
        $this->assertSame(90, $r->creditsRemaining);
        $this->assertSame($data, $r->toArray());
    }

    public function test_missing_fields_become_null_and_score_defaults_to_zero(): void
    {
        $r = IpResult::fromArray([]);
        $this->assertSame(0, $r->fraudScore);
        $this->assertNull($r->proxy);
        $this->assertNull($r->isp);
        $this->assertNull($r->creditsRemaining);
    }

    public function test_mistyped_fields_become_null_but_stay_in_to_array(): void
    {
        $data = ['proxy' => 'yes', 'ASN' => 'AS15169', 'fraud_score' => 'high'];
        $r = IpResult::fromArray($data);
        $this->assertNull($r->proxy);
        $this->assertNull($r->asn);
        $this->assertSame(0, $r->fraudScore);
        $this->assertSame($data, $r->toArray());
    }

    public function test_unknown_fields_survive_in_to_array(): void
    {
        $this->assertSame(['brand_new' => [1]], IpResult::fromArray(['brand_new' => [1]])->toArray());
    }

    public function test_flagged_defaults(): void
    {
        $a = IpResult::fromArray(['fraud_score' => 20, 'proxy' => true]);
        $this->assertSame(['proxy'], $a->flagReasons());
        $this->assertTrue($a->flagged());
        $this->assertSame(['fraud_score'], IpResult::fromArray(['fraud_score' => 80])->flagReasons());
        $clean = IpResult::fromArray(['fraud_score' => 10]);
        $this->assertSame([], $clean->flagReasons());
        $this->assertFalse($clean->flagged());
        $this->assertTrue(IpResult::fromArray(['fraud_score' => 75])->flagged());
        $this->assertFalse(IpResult::fromArray(['fraud_score' => 74])->flagged());
    }

    public function test_custom_threshold_and_flag_on(): void
    {
        $r = IpResult::fromArray(['fraud_score' => 80, 'bot_status' => true, 'proxy' => true]);
        $this->assertSame(['bot_status', 'proxy'], $r->flagReasons(90, ['bot_status', 'proxy']));
        $this->assertSame(['fraud_score', 'bot_status', 'proxy'], $r->flagReasons(50, ['bot_status', 'proxy']));
    }

    public function test_flag_on_ignores_non_boolean_true(): void
    {
        $this->assertSame([], IpResult::fromArray(['proxy' => 1])->flagReasons());
    }

    public function test_each_boolean_key_maps_to_its_own_property_only(): void
    {
        $map = ['proxy' => 'proxy', 'vpn' => 'vpn', 'tor' => 'tor', 'active_vpn' => 'activeVpn', 'active_tor' => 'activeTor', 'recent_abuse' => 'recentAbuse', 'bot_status' => 'botStatus'];
        foreach ($map as $key => $prop) {
            $r = IpResult::fromArray([$key => true]);
            foreach ($map as $other) {
                $this->assertSame($other === $prop ? true : null, $r->$other, "key $key vs property $other");
            }
        }
    }

    public function test_fraud_score_accepts_numeric_string_and_whole_float(): void
    {
        $this->assertSame(85, IpResult::fromArray(['fraud_score' => '85'])->fraudScore);
        $this->assertSame(85, IpResult::fromArray(['fraud_score' => 85.0])->fraudScore);
    }

    public function test_unknown_flag_on_name_yields_no_reason(): void
    {
        $this->assertSame([], IpResult::fromArray(['proxy' => true])->flagReasons(75, ['not_a_field']));
    }

    public function test_fractional_fraud_score_rounds_and_flags(): void
    {
        $r = IpResult::fromArray(['fraud_score' => 87.5]);

        $this->assertSame(88, $r->fraudScore);
        $this->assertTrue($r->flagged(88));
        $this->assertSame(['fraud_score'], $r->flagReasons(88));
    }
}
