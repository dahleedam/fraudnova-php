<?php

declare(strict_types=1);

namespace FraudNova\Exception;

use Psr\Http\Client\ClientExceptionInterface;

final class TransportException extends \RuntimeException implements FraudNovaException
{
    public function __construct(string $message, ClientExceptionInterface $previous)
    {
        parent::__construct($message, 0, $previous);
    }
}
