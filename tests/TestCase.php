<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        // Pengaman insiden 2026-09-12: RefreshDatabase pernah menghantam database
        // dev karena resolusi koneksi lolos. Suite HANYA boleh jalan di *testing.
        $default = config('database.default');
        $db = config("database.connections.{$default}.database");
        if (! is_string($db) || ! str_ends_with($db, '_testing')) {
            self::fail(
                "Suite pengujian menolak berjalan: database \"{$db}\" bukan database pengujian (*_testing). ".
                'Batalkan dan periksa konfigurasi DB pengujian.'
            );
        }
        parent::setUp();
    }

    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $this->app['auth']->forgetGuards();

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }
}
