<?php

namespace Tests\Feature;

use Tests\TestCase;

final class ProbeDbTest extends TestCase
{
    public function test_probe(): void
    {
        fwrite(STDERR, "\nPROBE-DB=".config('database.connections.pgsql.database')." ENV=".env('DB_DATABASE')
            ." GETENV=".var_export(getenv('DB_DATABASE'), true)
            ." _ENV=".var_export($_ENV['DB_DATABASE'] ?? null, true)
            ." _SERVER=".var_export($_SERVER['DB_DATABASE'] ?? null, true)."\n");
        $this->assertTrue(true);
    }
}
