<?php

namespace Tests\Feature;

use Tests\TestCase;

class DatabaseConfigurationTest extends TestCase
{
    public function test_mysql_is_the_default_application_driver(): void
    {
        $connections = config('database.connections');

        $this->assertSame('mysql', config('database.default'));
        $this->assertSame('mysql', $connections['mysql']['driver']);
        $this->assertSame('utf8mb4', $connections['mysql']['charset']);
        $this->assertSame('InnoDB', $connections['mysql']['engine']);
    }

    public function test_database_timestamps_use_utc_by_default(): void
    {
        $this->assertSame('UTC', config('app.timezone'));
    }
}
