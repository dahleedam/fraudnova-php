<?php

declare(strict_types=1);

namespace FraudNova\Tests\Result;

use FraudNova\Result\BatchItem;
use FraudNova\Result\BatchResult;
use FraudNova\Result\EmailResult;
use FraudNova\Result\IpResult;
use FraudNova\Result\PhoneResult;
use PHPUnit\Framework\TestCase;

final class BatchResultTest extends TestCase
{
    public function test_parses_ip_batch_in_order(): void
    {
        $r = BatchResult::fromArray([
            'success' => true,
            'results' => [
                ['ip' => '8.8.8.8', 'success' => true, 'data' => ['fraud_score' => 10], 'error' => null],
                ['ip' => 'bad', 'success' => false, 'data' => null, 'error' => 'invalid_ip'],
                ['ip' => '1.1.1.1', 'success' => true, 'data' => ['fraud_score' => 20], 'error' => null],
                ['ip' => '9.9.9.9', 'success' => false, 'data' => null, 'error' => 'insufficient_credits'],
                ['ip' => '4.4.4.4', 'success' => false, 'data' => null, 'error' => 'upstream_failed'],
            ],
            'credits_remaining' => 97,
        ], 'ip');

        $this->assertSame(97, $r->creditsRemaining);
        $this->assertCount(5, $r);
        $this->assertSame(5, count($r));
        $this->assertSame(['8.8.8.8', 'bad', '1.1.1.1', '9.9.9.9', '4.4.4.4'], array_map(fn (BatchItem $i) => $i->subject, $r->items));
        $this->assertInstanceOf(IpResult::class, $r->items[0]->result);
        $this->assertSame(10, $r->items[0]->result->fraudScore);
        $this->assertInstanceOf(IpResult::class, $r->items[2]->result);
        foreach ([1, 3, 4] as $i) {
            $this->assertNull($r->items[$i]->result);
            $this->assertFalse($r->items[$i]->success);
        }
        $this->assertCount(2, $r->successful());
        $this->assertCount(3, $r->failed());
        $this->assertTrue($r->hasFailures());
    }

    public function test_email_and_phone_batches_build_their_own_result_types(): void
    {
        $email = BatchResult::fromArray([
            'results' => [['email' => 'a@example.com', 'success' => true, 'data' => ['fraud_score' => 5], 'error' => null]],
            'credits_remaining' => 9,
        ], 'email');
        $this->assertSame('a@example.com', $email->items[0]->subject);
        $this->assertInstanceOf(EmailResult::class, $email->items[0]->result);

        $phone = BatchResult::fromArray([
            'results' => [['phone' => '15550100', 'success' => true, 'data' => ['fraud_score' => 5], 'error' => null]],
            'credits_remaining' => 8,
        ], 'phone');
        $this->assertSame('15550100', $phone->items[0]->subject);
        $this->assertInstanceOf(PhoneResult::class, $phone->items[0]->result);
        $this->assertFalse($phone->hasFailures());
    }

    public function test_item_error_helpers(): void
    {
        $mk = fn (string $e) => new BatchItem('x', false, null, $e);

        foreach (['invalid_ip', 'invalid_email', 'invalid_phone'] as $code) {
            $i = $mk($code);
            $this->assertTrue($i->isInvalid());
            $this->assertFalse($i->isInsufficientCredits());
            $this->assertFalse($i->isUpstreamFailed());
        }
        $i = $mk('insufficient_credits');
        $this->assertTrue($i->isInsufficientCredits());
        $this->assertFalse($i->isInvalid());
        $this->assertFalse($i->isUpstreamFailed());
        $i = $mk('upstream_failed');
        $this->assertTrue($i->isUpstreamFailed());
        $this->assertFalse($i->isInvalid());
        $this->assertFalse($i->isInsufficientCredits());
        $i = $mk('weird');
        $this->assertFalse($i->isInvalid());
        $this->assertFalse($i->isInsufficientCredits());
        $this->assertFalse($i->isUpstreamFailed());
        $this->assertSame('weird', $i->error);
    }

    public function test_iterates_items(): void
    {
        $r = BatchResult::fromArray([
            'results' => [
                ['ip' => 'a', 'success' => false, 'data' => null, 'error' => 'invalid_ip'],
                'junk',
                ['ip' => 'b', 'success' => false, 'data' => null, 'error' => 'invalid_ip'],
            ],
            'credits_remaining' => 1,
        ], 'ip');

        $seen = [];
        foreach ($r as $item) {
            $this->assertInstanceOf(BatchItem::class, $item);
            $seen[] = $item->subject;
        }
        $this->assertSame(['a', 'b'], $seen);
    }

    public function test_missing_credits_remaining_defaults_to_zero(): void
    {
        $r = BatchResult::fromArray(['results' => []], 'ip');
        $this->assertSame(0, $r->creditsRemaining);
        $this->assertCount(0, $r);
        $this->assertFalse($r->hasFailures());
    }
}
