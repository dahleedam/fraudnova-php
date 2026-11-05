<?php

declare(strict_types=1);

namespace FraudNova\Tests\Http;

use FraudNova\Exception\InsufficientCreditsException;
use FraudNova\Exception\TransportException;
use FraudNova\Exception\UnexpectedResponseException;
use FraudNova\Http\Transport;
use FraudNova\Tests\Support\FakeHttpClient;
use FraudNova\Tests\Support\FakeNetworkException;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;

final class TransportTest extends TestCase
{
    private function transport(FakeHttpClient $http, string $base = 'https://fraudnova.com/api/v1', string $key = 'fn_test'): Transport
    {
        $factory = new Psr17Factory;

        return new Transport($key, $base, $http, $factory, $factory);
    }

    public function test_get_builds_url_query_and_headers(): void
    {
        $http = (new FakeHttpClient)->json(200, ['success' => true]);

        $this->transport($http)->get('/check', ['ip' => '8.8.8.8', 'strictness' => 2]);

        $request = $http->lastRequest();
        $this->assertSame('GET', $request->getMethod());
        $this->assertSame('https://fraudnova.com/api/v1/check?ip=8.8.8.8&strictness=2', (string) $request->getUri());
        $this->assertSame('application/json', $request->getHeaderLine('Accept'));
        $this->assertSame('Bearer fn_test', $request->getHeaderLine('Authorization'));
        $this->assertStringStartsWith('fraudnova-php/1.0.0 php/', $request->getHeaderLine('User-Agent'));
    }

    public function test_trailing_slash_in_base_url_is_tolerated(): void
    {
        $http = (new FakeHttpClient)->json(200, ['success' => true]);

        $this->transport($http, 'https://fraudnova.com/api/v1/')->get('/balance');

        $this->assertSame('https://fraudnova.com/api/v1/balance', (string) $http->lastRequest()->getUri());
    }

    public function test_unauthenticated_get_sends_no_authorization_header(): void
    {
        $http = (new FakeHttpClient)->json(200, ['success' => true]);

        $this->transport($http)->get('/my-ip', [], false);

        $this->assertFalse($http->lastRequest()->hasHeader('Authorization'));
    }

    public function test_post_sends_json_body(): void
    {
        $http = (new FakeHttpClient)->json(200, ['success' => true]);

        $this->transport($http)->post('/check/batch', ['ips' => ['8.8.8.8']]);

        $request = $http->lastRequest();
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('application/json', $request->getHeaderLine('Content-Type'));
        $this->assertSame('{"ips":["8.8.8.8"]}', (string) $request->getBody());
    }

    public function test_success_envelope_is_returned_decoded(): void
    {
        $http = (new FakeHttpClient)->json(200, ['success' => true, 'fraud_score' => 12]);

        $this->assertSame(['success' => true, 'fraud_score' => 12], $this->transport($http)->get('/check'));
    }

    public function test_non_2xx_throws_the_mapped_exception(): void
    {
        $http = (new FakeHttpClient)->json(402, ['success' => false, 'message' => 'Insufficient credits']);

        $this->expectException(InsufficientCreditsException::class);

        $this->transport($http)->get('/check');
    }

    public function test_2xx_without_success_true_is_unexpected(): void
    {
        $http = (new FakeHttpClient)->json(200, ['success' => false]);

        $this->expectException(UnexpectedResponseException::class);

        $this->transport($http)->get('/check');
    }

    public function test_2xx_non_json_is_unexpected(): void
    {
        $http = (new FakeHttpClient)->raw(200, '<html>nope</html>');

        $this->expectException(UnexpectedResponseException::class);

        $this->transport($http)->get('/check');
    }

    public function test_network_error_is_wrapped(): void
    {
        $original = new FakeNetworkException((new Psr17Factory)->createRequest('GET', 'https://fraudnova.com'), 'connection reset');
        $http = (new FakeHttpClient)->queue($original);

        try {
            $this->transport($http)->get('/check');
            $this->fail('Expected TransportException');
        } catch (TransportException $e) {
            $this->assertSame($original, $e->getPrevious());
        }
    }

    public function test_api_key_never_appears_in_exception_messages(): void
    {
        $key = 'fn_secret_value';
        $cases = [
            (new FakeHttpClient)->json(402, ['success' => false, 'message' => 'Insufficient credits']),
            (new FakeHttpClient)->queue(new FakeNetworkException((new Psr17Factory)->createRequest('GET', 'https://fraudnova.com'), 'connection reset')),
            (new FakeHttpClient)->json(200, ['success' => false]),
        ];

        foreach ($cases as $http) {
            try {
                $this->transport($http, 'https://fraudnova.com/api/v1', $key)->get('/check');
                $this->fail('Expected an exception');
            } catch (\Throwable $e) {
                $this->assertFalse(str_contains($e->getMessage(), $key));
                $this->assertFalse(str_contains((string) $e, $key));
            }
        }
    }
}
