<?php

declare(strict_types=1);

namespace FraudNova\Tests;

use FraudNova\Client;
use FraudNova\Exception\FraudNovaException;
use FraudNova\Exception\InvalidArgumentException;
use FraudNova\Exception\RateLimitException;
use FraudNova\Exception\UnexpectedResponseException;
use FraudNova\Exception\UpstreamException;
use FraudNova\IpOptions;
use FraudNova\Result\EmailResult;
use FraudNova\Result\IpResult;
use FraudNova\Result\PhoneResult;
use FraudNova\RetryPolicy;
use FraudNova\Tests\Support\FakeHttpClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;

final class ClientTest extends TestCase
{
    private function client(FakeHttpClient $http, string $key = 'fn_test'): Client
    {
        $factory = new Psr17Factory;

        return new Client($key, $http, $factory, $factory);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function envelope(array $data, int $credits = 4): array
    {
        return ['success' => true, 'data' => $data, 'credits_remaining' => $credits];
    }

    public function test_ip_check_builds_request_and_result(): void
    {
        $http = (new FakeHttpClient)->json(200, $this->envelope(['fraud_score' => 87, 'proxy' => true]));

        $result = $this->client($http)->ip(' 8.8.8.8 ', new IpOptions(strictness: 3, mobile: true));

        $request = $http->lastRequest();
        $this->assertSame('GET', $request->getMethod());
        $this->assertSame('https://fraudnova.com/api/v1/check?ip=8.8.8.8&strictness=3&mobile=true', (string) $request->getUri());
        $this->assertSame('Bearer fn_test', $request->getHeaderLine('Authorization'));
        $this->assertInstanceOf(IpResult::class, $result);
        $this->assertSame(87, $result->fraudScore);
        $this->assertTrue($result->proxy);
        $this->assertSame(4, $result->creditsRemaining);
    }

    public function test_email_and_phone_checks(): void
    {
        $http = (new FakeHttpClient)
            ->json(200, $this->envelope(['fraud_score' => 10, 'disposable' => true], 9))
            ->json(200, $this->envelope(['fraud_score' => 20], 8))
            ->json(200, $this->envelope(['fraud_score' => 30], 7));
        $client = $this->client($http);

        $email = $client->email('a@example.com');
        $this->assertSame('https://fraudnova.com/api/v1/check/email?email=a%40example.com', (string) $http->lastRequest()->getUri());
        $this->assertInstanceOf(EmailResult::class, $email);
        $this->assertSame(10, $email->fraudScore);
        $this->assertTrue($email->disposable);
        $this->assertSame(9, $email->creditsRemaining);

        $phone = $client->phone('18176791808', ' us ');
        $this->assertSame('https://fraudnova.com/api/v1/check/phone?phone=18176791808&country=US', (string) $http->lastRequest()->getUri());
        $this->assertInstanceOf(PhoneResult::class, $phone);
        $this->assertSame(20, $phone->fraudScore);
        $this->assertSame(8, $phone->creditsRemaining);

        $client->phone('18176791808');
        $this->assertSame('https://fraudnova.com/api/v1/check/phone?phone=18176791808', (string) $http->lastRequest()->getUri());
        $this->assertStringNotContainsString('country', (string) $http->lastRequest()->getUri());
    }

    public function test_subjects_are_percent_encoded(): void
    {
        $http = (new FakeHttpClient)
            ->json(200, $this->envelope(['fraud_score' => 1]))
            ->json(200, $this->envelope(['fraud_score' => 1]))
            ->json(200, $this->envelope(['fraud_score' => 1]));
        $client = $this->client($http);

        $client->email('user+tag@example.com');
        $this->assertSame('https://fraudnova.com/api/v1/check/email?email=user%2Btag%40example.com', (string) $http->lastRequest()->getUri());

        $client->ip('2001:db8::1');
        $this->assertSame('https://fraudnova.com/api/v1/check?ip=2001%3Adb8%3A%3A1', (string) $http->lastRequest()->getUri());

        $client->phone('+1 (817) 679-1808');
        $this->assertSame('https://fraudnova.com/api/v1/check/phone?phone=%2B1%20%28817%29%20679-1808', (string) $http->lastRequest()->getUri());
    }

    public function test_empty_subject_throws_before_sending(): void
    {
        $http = new FakeHttpClient;
        $client = $this->client($http);

        foreach ([
            'ip must not be empty' => static fn () => $client->ip('   '),
            'email must not be empty' => static fn () => $client->email(''),
            'phone must not be empty' => static fn () => $client->phone(" \n"),
        ] as $message => $call) {
            try {
                $call();
                $this->fail('Expected InvalidArgumentException for: '.$message);
            } catch (InvalidArgumentException $e) {
                $this->assertSame($message, $e->getMessage());
            }
        }

        $this->assertSame([], $http->requests);
    }

    public function test_empty_api_key_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('API key must not be empty');

        $this->client(new FakeHttpClient, "  \n");
    }

    public function test_api_key_is_trimmed(): void
    {
        $http = (new FakeHttpClient)->json(200, ['success' => true, 'credits_remaining' => 5]);

        $this->client($http, "  fn_abc \n")->balance();

        $this->assertSame('Bearer fn_abc', $http->lastRequest()->getHeaderLine('Authorization'));
    }

    public function test_batch_ip_posts_json_and_parses_results(): void
    {
        $http = (new FakeHttpClient)->json(200, [
            'success' => true,
            'results' => [
                ['ip' => '8.8.8.8', 'success' => true, 'data' => ['fraud_score' => 5]],
                ['ip' => '1.1.1.1', 'success' => false, 'error' => 'insufficient_credits'],
            ],
            'credits_remaining' => 0,
        ]);

        $batch = $this->client($http)->batchIp([' 8.8.8.8', '1.1.1.1 '], new IpOptions(strictness: 2));

        $request = $http->lastRequest();
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('https://fraudnova.com/api/v1/check/batch', (string) $request->getUri());
        $this->assertSame('{"ips":["8.8.8.8","1.1.1.1"],"strictness":2}', (string) $request->getBody());
        $this->assertCount(2, $batch);
        $this->assertSame('8.8.8.8', $batch->items[0]->subject);
        $this->assertInstanceOf(IpResult::class, $batch->items[0]->result);
        $this->assertSame(5, $batch->items[0]->result->fraudScore);
        $this->assertTrue($batch->items[1]->isInsufficientCredits());
        $this->assertSame(0, $batch->creditsRemaining);
    }

    public function test_batch_email_and_phone(): void
    {
        $results = static fn (string $key, string $subject): array => [
            'success' => true,
            'results' => [[$key => $subject, 'success' => true, 'data' => ['fraud_score' => 3]]],
            'credits_remaining' => 2,
        ];
        $http = (new FakeHttpClient)
            ->json(200, $results('email', 'a@example.com'))
            ->json(200, $results('phone', '18176791808'))
            ->json(200, $results('phone', '18176791808'));
        $client = $this->client($http);

        $emails = $client->batchEmail(['a@example.com']);
        $this->assertSame('https://fraudnova.com/api/v1/check/email/batch', (string) $http->lastRequest()->getUri());
        $this->assertSame('{"emails":["a@example.com"]}', (string) $http->lastRequest()->getBody());
        $this->assertInstanceOf(EmailResult::class, $emails->items[0]->result);

        $phones = $client->batchPhone(['18176791808'], ' us ');
        $this->assertSame('https://fraudnova.com/api/v1/check/phone/batch', (string) $http->lastRequest()->getUri());
        $this->assertSame('{"phones":["18176791808"],"country":"US"}', (string) $http->lastRequest()->getBody());
        $this->assertInstanceOf(PhoneResult::class, $phones->items[0]->result);

        $client->batchPhone(['18176791808']);
        $this->assertSame('{"phones":["18176791808"]}', (string) $http->lastRequest()->getBody());
    }

    public function test_batch_validation(): void
    {
        $http = new FakeHttpClient;
        $client = $this->client($http);

        $cases = [
            'Batch must contain at least one item' => [],
            'Batch may contain at most 100 items' => array_fill(0, 101, '8.8.8.8'),
            'Batch item #1 must be a string or null' => ['8.8.8.8', 5],
            'Batch item #0 must be a string or null' => [42],
            'Batch item #2 must be a string or null' => ['8.8.8.8', null, ['1.1.1.1']],
        ];

        foreach ($cases as $message => $items) {
            try {
                /** @phpstan-ignore argument.type */
                $client->batchIp($items);
                $this->fail('Expected InvalidArgumentException for: '.$message);
            } catch (InvalidArgumentException $e) {
                $this->assertSame($message, $e->getMessage());
            }
        }

        $this->assertSame([], $http->requests);
    }

    public function test_batch_without_results_is_unexpected(): void
    {
        $http = (new FakeHttpClient)->json(200, ['success' => true, 'credits_remaining' => 3]);

        try {
            $this->client($http)->batchEmail(['a@example.com']);
            $this->fail('Expected UnexpectedResponseException');
        } catch (UnexpectedResponseException $e) {
            $this->assertSame(200, $e->statusCode());
            $this->assertSame('Missing results', $e->apiMessage());
        }
    }

    public function test_single_check_without_data_is_unexpected(): void
    {
        $http = (new FakeHttpClient)->json(200, ['success' => true, 'data' => 'nope', 'credits_remaining' => 3]);

        try {
            $this->client($http)->ip('8.8.8.8');
            $this->fail('Expected UnexpectedResponseException');
        } catch (UnexpectedResponseException $e) {
            $this->assertSame(200, $e->statusCode());
            $this->assertSame('Missing data', $e->apiMessage());
        }
    }

    public function test_balance_returns_int(): void
    {
        $http = (new FakeHttpClient)
            ->json(200, ['success' => true, 'credits_remaining' => 1234])
            ->json(200, ['success' => true]);
        $client = $this->client($http);

        $this->assertSame(1234, $client->balance());
        $this->assertSame('https://fraudnova.com/api/v1/balance', (string) $http->lastRequest()->getUri());

        $this->expectException(UnexpectedResponseException::class);
        $client->balance();
    }

    public function test_my_ip_sends_no_auth_header_and_returns_ip(): void
    {
        $http = (new FakeHttpClient)
            ->json(200, ['success' => true, 'ip' => '203.0.113.7'])
            ->json(200, ['success' => true, 'ip' => '']);
        $client = $this->client($http);

        $this->assertSame('203.0.113.7', $client->myIp());
        $this->assertSame('https://fraudnova.com/api/v1/my-ip', (string) $http->lastRequest()->getUri());
        $this->assertFalse($http->lastRequest()->hasHeader('Authorization'));

        $this->expectException(UnexpectedResponseException::class);
        $client->myIp();
    }

    public function test_api_errors_propagate_as_typed_exceptions(): void
    {
        $http = (new FakeHttpClient)
            ->json(429, ['success' => false, 'message' => 'Too many requests'])
            ->json(503, ['success' => false, 'message' => 'Upstream lookup failed.']);
        $client = $this->client($http);

        try {
            $client->ip('8.8.8.8');
            $this->fail('Expected RateLimitException');
        } catch (RateLimitException) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(UpstreamException::class);
        $client->email('a@example.com');
    }

    public function test_user_agent_header_names_the_sdk_version(): void
    {
        $http = (new FakeHttpClient)->json(200, ['success' => true, 'credits_remaining' => 1]);

        $this->client($http)->balance();

        $this->assertSame('1.0.0', Client::VERSION);
        $this->assertStringStartsWith('fraudnova-php/1.0.0 php/', $http->lastRequest()->getHeaderLine('User-Agent'));
    }

    public function test_retry_policy_retries_a_429_through_the_public_api(): void
    {
        $http = (new FakeHttpClient)
            ->json(429, ['success' => false, 'message' => 'Rate limit exceeded. Try again shortly.'])
            ->json(200, $this->envelope(['fraud_score' => 5]));
        $factory = new Psr17Factory;
        $client = new Client('fn_x', $http, $factory, $factory, retry: new RetryPolicy(maxAttempts: 2, sleeper: fn () => null));

        $result = $client->ip('8.8.8.8');

        $this->assertCount(2, $http->requests);
        $this->assertSame(5, $result->fraudScore);
    }

    public function test_unencodable_batch_body_throws_invalid_argument(): void
    {
        $http = new FakeHttpClient;

        try {
            $this->client($http)->batchIp(['8.8.8.8'], new IpOptions(userAgent: "Mozilla \xC3\x28"));
            $this->fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            $this->assertInstanceOf(FraudNovaException::class, $e);
            $this->assertStringStartsWith('Request body could not be encoded as JSON: ', $e->getMessage());
        }

        $this->assertSame([], $http->requests);
    }

    public function test_unusable_base_url_is_rejected(): void
    {
        foreach (['', '   ', 'fraudnova.com/api/v1', 'ftp://fraudnova.com/api/v1'] as $baseUrl) {
            try {
                new Client('fn_x', baseUrl: $baseUrl);
                $this->fail('Expected InvalidArgumentException for: '.$baseUrl);
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('baseUrl', $e->getMessage());
            }
        }
    }

    public function test_base_url_is_trimmed(): void
    {
        $http = (new FakeHttpClient)->json(200, ['success' => true, 'credits_remaining' => 1]);
        $factory = new Psr17Factory;

        (new Client('fn_x', $http, $factory, $factory, ' https://example.test/api/v1/ '))->balance();

        $this->assertSame('https://example.test/api/v1/balance', (string) $http->lastRequest()->getUri());
    }

    public function test_batch_passes_empty_and_null_items_through(): void
    {
        $http = (new FakeHttpClient)->json(200, [
            'success' => true,
            'results' => [
                ['ip' => '8.8.8.8', 'success' => true, 'data' => ['fraud_score' => 5]],
                ['ip' => '', 'success' => false, 'error' => 'invalid_ip'],
                ['ip' => null, 'success' => false, 'error' => 'invalid_ip'],
            ],
            'credits_remaining' => 3,
        ]);

        $batch = $this->client($http)->batchIp(['8.8.8.8', '', null]);

        $this->assertSame('{"ips":["8.8.8.8","",null]}', (string) $http->lastRequest()->getBody());
        $this->assertCount(3, $batch);
        $this->assertCount(2, $batch->failed());
        $this->assertFalse($batch->items[1]->success);
        $this->assertTrue($batch->items[1]->isInvalid());
        $this->assertTrue($batch->items[2]->isInvalid());
        $this->assertSame('', $batch->items[2]->subject);
    }

    public function test_dumping_the_client_never_shows_the_api_key(): void
    {
        $key = 'fn_supersecretvalue9876';
        $client = $this->client(new FakeHttpClient, $key);

        ob_start();
        var_dump($client);
        $dumped = (string) ob_get_clean();
        $printed = print_r($client, true);

        foreach ([$dumped, $printed] as $output) {
            $this->assertStringNotContainsString($key, $output);
            $this->assertStringNotContainsString('supersecret', $output);
            $this->assertStringContainsString('fn_…9876', $output);
        }

        $this->assertSame(['transport'], array_keys($client->__debugInfo()));
    }

    public function test_short_api_key_is_fully_masked_in_dumps(): void
    {
        $printed = print_r($this->client(new FakeHttpClient, 'fn_abc12'), true);

        $this->assertStringNotContainsString('abc12', $printed);
        $this->assertStringContainsString('fn_…', $printed);
    }

    public function test_unexpected_response_carries_the_real_2xx_status(): void
    {
        $http = (new FakeHttpClient)
            ->json(201, ['success' => true])
            ->json(201, ['success' => true, 'ip' => ''])
            ->json(201, ['success' => true, 'credits_remaining' => 3])
            ->json(201, ['success' => true, 'data' => 'nope']);
        $client = $this->client($http);

        $calls = [
            'Missing credits_remaining' => static fn () => $client->balance(),
            'Missing ip' => static fn () => $client->myIp(),
            'Missing results' => static fn () => $client->batchIp(['8.8.8.8']),
            'Missing data' => static fn () => $client->ip('8.8.8.8'),
        ];

        foreach ($calls as $message => $call) {
            try {
                $call();
                $this->fail('Expected UnexpectedResponseException for: '.$message);
            } catch (UnexpectedResponseException $e) {
                $this->assertSame(201, $e->statusCode(), $message);
                $this->assertSame($message, $e->apiMessage());
            }
        }
    }
}
