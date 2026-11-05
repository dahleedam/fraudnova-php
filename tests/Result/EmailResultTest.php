<?php

declare(strict_types=1);

namespace FraudNova\Tests\Result;

use FraudNova\Result\EmailResult;
use PHPUnit\Framework\TestCase;

final class EmailResultTest extends TestCase
{
    public function test_maps_every_documented_field(): void
    {
        $data = [
            'fraud_score' => 30, 'valid' => true, 'disposable' => false, 'catch_all' => false, 'generic' => false,
            'common' => true, 'dns_valid' => true, 'honeypot' => false, 'frequent_complainer' => false,
            'suspect' => false, 'recent_abuse' => false, 'leaked' => true, 'risky_tld' => false,
            'spf_record' => true, 'dmarc_record' => false, 'smtp_score' => 3, 'overall_score' => 4,
            'deliverability' => 'high', 'spam_trap_score' => 'none', 'result_type' => 'valid',
            'sanitized_email' => 'user@example.com', 'first_seen' => '2017-01-01', 'domain_age' => '9 years',
        ];
        $r = EmailResult::fromArray($data, 12);

        $this->assertSame(30, $r->fraudScore);
        $this->assertTrue($r->valid);
        $this->assertFalse($r->disposable);
        $this->assertFalse($r->catchAll);
        $this->assertFalse($r->generic);
        $this->assertTrue($r->common);
        $this->assertTrue($r->dnsValid);
        $this->assertFalse($r->honeypot);
        $this->assertFalse($r->frequentComplainer);
        $this->assertFalse($r->suspect);
        $this->assertFalse($r->recentAbuse);
        $this->assertTrue($r->leaked);
        $this->assertFalse($r->riskyTld);
        $this->assertTrue($r->spfRecord);
        $this->assertFalse($r->dmarcRecord);
        $this->assertSame(3, $r->smtpScore);
        $this->assertSame(4, $r->overallScore);
        $this->assertSame('high', $r->deliverability);
        $this->assertSame('none', $r->spamTrapScore);
        $this->assertSame('valid', $r->resultType);
        $this->assertSame('user@example.com', $r->sanitizedEmail);
        $this->assertSame('2017-01-01', $r->firstSeen);
        $this->assertSame('9 years', $r->domainAge);
        $this->assertSame(12, $r->creditsRemaining);
        $this->assertSame($data, $r->toArray());
    }

    public function test_first_seen_and_domain_age_accept_strings_or_objects(): void
    {
        $obj = ['human' => '9 years ago', 'timestamp' => 1483228800, 'iso' => '2017-01-01T00:00:00-05:00'];
        $r = EmailResult::fromArray(['first_seen' => $obj, 'domain_age' => $obj]);
        $this->assertSame('2017-01-01T00:00:00-05:00', $r->firstSeen);
        $this->assertSame('9 years ago', $r->domainAge);

        $s = EmailResult::fromArray(['first_seen' => 'a', 'domain_age' => 'b']);
        $this->assertSame('a', $s->firstSeen);
        $this->assertSame('b', $s->domainAge);

        $this->assertNull(EmailResult::fromArray(['first_seen' => 5])->firstSeen);
    }

    public function test_default_flag_on_is_disposable(): void
    {
        $this->assertSame(['disposable'], EmailResult::DEFAULT_FLAG_ON);
        $r = EmailResult::fromArray(['fraud_score' => 0, 'disposable' => true]);
        $this->assertSame(['disposable'], $r->flagReasons());
        $this->assertFalse(EmailResult::fromArray(['honeypot' => true])->flagged());
    }
}
