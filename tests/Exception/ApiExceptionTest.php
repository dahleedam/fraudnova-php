<?php

declare(strict_types=1);

namespace FraudNova\Tests\Exception;

use FraudNova\Exception\AccountSuspendedException;
use FraudNova\Exception\ApiException;
use FraudNova\Exception\AuthenticationException;
use FraudNova\Exception\FraudNovaException;
use FraudNova\Exception\InsufficientCreditsException;
use FraudNova\Exception\InvalidArgumentException;
use FraudNova\Exception\MissingHttpClientException;
use FraudNova\Exception\RateLimitException;
use FraudNova\Exception\TransportException;
use FraudNova\Exception\UnexpectedResponseException;
use FraudNova\Exception\UpstreamException;
use FraudNova\Exception\ValidationException;
use FraudNova\Tests\Support\FakeNetworkException;
use Nyholm\Psr7\Request;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ApiExceptionTest extends TestCase
{
    /**
     * @return array<int, array{int, class-string<ApiException>}>
     */
    public static function statusMap(): array
    {
        return [
            '401' => [401, AuthenticationException::class],
            '402' => [402, InsufficientCreditsException::class],
            '403' => [403, AccountSuspendedException::class],
            '422' => [422, ValidationException::class],
            '429' => [429, RateLimitException::class],
            '502' => [502, UpstreamException::class],
            '503' => [503, UpstreamException::class],
            '504' => [504, UpstreamException::class],
            '500' => [500, ApiException::class],
            '418' => [418, ApiException::class],
        ];
    }

    /**
     * @param  class-string<ApiException>  $class
     */
    #[DataProvider('statusMap')]
    public function test_maps_each_status_to_its_exception_class(int $status, string $class): void
    {
        $e = ApiException::fromResponse(new Response($status, [], '{"success":false,"message":"x"}'));

        self::assertSame($class, $e::class);
        self::assertSame($status, $e->statusCode());
    }

    public function test_message_and_accessors_come_from_the_envelope(): void
    {
        $e = ApiException::fromResponse(new Response(429, [], '{"success":false,"message":"Rate limit exceeded. Try again shortly."}'));

        self::assertSame('FraudNova API error (HTTP 429): Rate limit exceeded. Try again shortly.', $e->getMessage());
        self::assertSame('Rate limit exceeded. Try again shortly.', $e->apiMessage());
        self::assertSame(429, $e->statusCode());
        self::assertSame('Rate limit exceeded. Try again shortly.', $e->body()['message']);
    }

    public function test_concurrency_429_is_detected(): void
    {
        $concurrent = ApiException::fromResponse(new Response(429, [], '{"success":false,"message":"Too many concurrent requests for this account. Please retry."}'));
        $rate = ApiException::fromResponse(new Response(429, [], '{"success":false,"message":"Rate limit exceeded. Try again shortly."}'));

        self::assertInstanceOf(RateLimitException::class, $concurrent);
        self::assertInstanceOf(RateLimitException::class, $rate);
        self::assertTrue($concurrent->isConcurrencyLimit());
        self::assertFalse($rate->isConcurrencyLimit());
    }

    public function test_retry_after_header_is_parsed_into_the_exception(): void
    {
        $with = ApiException::fromResponse(new Response(429, ['Retry-After' => '7'], '{"message":"slow down"}'));
        $without = ApiException::fromResponse(new Response(429, [], '{"message":"slow down"}'));

        self::assertInstanceOf(RateLimitException::class, $with);
        self::assertInstanceOf(RateLimitException::class, $without);
        self::assertSame(7, $with->retryAfter());
        self::assertNull($without->retryAfter());
    }

    public function test_503_with_envelope_reports_the_refund(): void
    {
        $e = ApiException::fromResponse(new Response(503, [], '{"success":false,"message":"Upstream lookup failed."}'));

        self::assertInstanceOf(UpstreamException::class, $e);
        self::assertTrue($e->creditRefunded());
        self::assertStringEndsWith('The credit for this check was refunded.', $e->getMessage());
    }

    public function test_503_html_body_does_not_claim_a_refund(): void
    {
        $e = ApiException::fromResponse(new Response(503, [], '<html>503</html>'));

        self::assertInstanceOf(UpstreamException::class, $e);
        self::assertFalse($e->creditRefunded());
        self::assertSame('<html>503</html>', $e->apiMessage());
        self::assertStringNotContainsString('refunded', $e->getMessage());
    }

    public function test_429_html_body_is_still_a_rate_limit(): void
    {
        $e = ApiException::fromResponse(new Response(429, [], '<html>Too many requests</html>'));

        self::assertInstanceOf(RateLimitException::class, $e);
        self::assertFalse($e->isConcurrencyLimit());
        self::assertSame([], $e->body());
    }

    public function test_long_raw_body_is_truncated_to_200_chars(): void
    {
        $e = ApiException::fromResponse(new Response(500, [], str_repeat('a', 500)));

        self::assertSame(str_repeat('a', 200), $e->apiMessage());
    }

    public function test_empty_body_message_is_http_status(): void
    {
        $e = ApiException::fromResponse(new Response(500, [], ''));

        self::assertSame('HTTP 500', $e->apiMessage());
    }

    public function test_unexpected_response_message(): void
    {
        $e = new UnexpectedResponseException(200, 'bad');

        self::assertSame('FraudNova returned an unexpected response (HTTP 200).', $e->getMessage());
    }

    public function test_every_exception_implements_the_package_interface(): void
    {
        $all = [
            new InvalidArgumentException('x'),
            new MissingHttpClientException('x'),
            new TransportException('x', new FakeNetworkException(new Request('GET', 'https://example.test'), 'down')),
            new ApiException(500, 'x'),
            new AuthenticationException(401, 'x'),
            new InsufficientCreditsException(402, 'x'),
            new AccountSuspendedException(403, 'x'),
            new ValidationException(422, 'x'),
            new RateLimitException(429, 'x'),
            new UpstreamException(503, 'x'),
            new UnexpectedResponseException(200, 'x'),
        ];

        foreach ($all as $e) {
            self::assertInstanceOf(FraudNovaException::class, $e);
        }
    }
}
