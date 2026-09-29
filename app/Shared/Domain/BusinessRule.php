<?php

namespace App\Shared\Domain;

final class BusinessRule extends \RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message, public readonly int $status = 409)
    {
        parent::__construct($message);
    }
}
