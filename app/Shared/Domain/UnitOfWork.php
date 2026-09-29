<?php

namespace App\Shared\Domain;

interface UnitOfWork
{
    public function run(callable $operation): mixed;
}
