<?php

declare(strict_types=1);

namespace FraudNova\Tests\Result;

use FraudNova\Result\PhoneResult;
use PHPUnit\Framework\TestCase;

final class PhoneResultTest extends TestCase
{
    public function test_maps_every_documented_field(): void
    {
        $data = [
            'fraud_score' => 15, 'valid' => true, 'recent_abuse' => false, 'VOIP' => true, 'prepaid' => false,
            'risky' => false, 'active' => true, 'do_not_call' => false, 'leaked' => false, 'spammer' => false,
            'dialing_code' => 1, 'carrier' => 'Example Carrier', 'line_type' => 'Wireless', 'country' => 'US',
            'region' => 'California', 'city' => 'Mountain View', 'timezone' => 'America/Los_Angeles',
            'zip_code' => '94043', 'active_status' => 'N/A', 'formatted' => '+16505550100', 'local_format' => '(650) 555-0100',
        ];
        $r = PhoneResult::fromArray($data, 7);

        $this->assertSame(15, $r->fraudScore);
        $this->assertTrue($r->valid);
        $this->assertFalse($r->recentAbuse);
        $this->assertTrue($r->voip);
        $this->assertFalse($r->prepaid);
        $this->assertFalse($r->risky);
        $this->assertTrue($r->active);
        $this->assertFalse($r->doNotCall);
        $this->assertFalse($r->leaked);
        $this->assertFalse($r->spammer);
        $this->assertSame(1, $r->dialingCode);
        $this->assertSame('Example Carrier', $r->carrier);
        $this->assertSame('Wireless', $r->lineType);
        $this->assertSame('US', $r->country);
        $this->assertSame('California', $r->region);
        $this->assertSame('Mountain View', $r->city);
        $this->assertSame('America/Los_Angeles', $r->timezone);
        $this->assertSame('94043', $r->zipCode);
        $this->assertSame('N/A', $r->activeStatus);
        $this->assertSame('+16505550100', $r->formatted);
        $this->assertSame('(650) 555-0100', $r->localFormat);
        $this->assertSame(7, $r->creditsRemaining);
        $this->assertSame($data, $r->toArray());
    }

    public function test_default_flag_on_is_voip_and_risky(): void
    {
        $this->assertSame(['VOIP', 'risky'], PhoneResult::DEFAULT_FLAG_ON);
        $r = PhoneResult::fromArray(['VOIP' => true, 'risky' => true]);
        $this->assertSame(['VOIP', 'risky'], $r->flagReasons());
        $this->assertTrue($r->flagged());
        $this->assertFalse(PhoneResult::fromArray(['fraud_score' => 10])->flagged());
    }
}
