<?php

declare(strict_types=1);

namespace FraudNova\Laravel\Facades;

use FraudNova\Client;
use Illuminate\Support\Facades\Facade;

/**
 * @method static \FraudNova\Result\IpResult ip(string $ip, ?\FraudNova\IpOptions $options = null)
 * @method static \FraudNova\Result\EmailResult email(string $email)
 * @method static \FraudNova\Result\PhoneResult phone(string $phone, ?string $country = null)
 * @method static \FraudNova\Result\BatchResult batchIp(list<string> $ips, ?\FraudNova\IpOptions $options = null)
 * @method static \FraudNova\Result\BatchResult batchEmail(list<string> $emails)
 * @method static \FraudNova\Result\BatchResult batchPhone(list<string> $phones, ?string $country = null)
 * @method static int balance()
 * @method static string myIp()
 *
 * @see Client
 */
final class FraudNova extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return Client::class;
    }
}
