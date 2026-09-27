<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /** Pengaman: RefreshDatabase mengosongkan database, jadi tes hanya boleh jalan di database berakhiran _test. */
    protected function setUpTraits()
    {
        $database = (string) config('database.connections.'.config('database.default').'.database');
        if (! str_ends_with($database, '_test')) {
            throw new RuntimeException("Tes dihentikan: database [{$database}] bukan database tes. Periksa DB_DATABASE di phpunit.xml.");
        }

        return parent::setUpTraits();
    }
}
