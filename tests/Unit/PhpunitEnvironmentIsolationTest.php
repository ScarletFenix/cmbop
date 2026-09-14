<?php

namespace Tests\Unit;

use Tests\TestCase;

class PhpunitEnvironmentIsolationTest extends TestCase
{
    public function test_phpunit_overrides_shell_app_env_and_uses_memory_sqlite(): void
    {
        $this->assertTrue(app()->runningUnitTests());
        $this->assertSame('testing', app()->environment());
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
    }
}
