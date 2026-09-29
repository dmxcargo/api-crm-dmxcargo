<?php

namespace App\Shared\Infrastructure;

use App\Shared\Domain\UnitOfWork;
use Illuminate\Support\Facades\DB;

final class PostgresUnitOfWork implements UnitOfWork
{
    public function run(callable $operation): mixed
    {
        return DB::transaction($operation, 3);
    }
}
