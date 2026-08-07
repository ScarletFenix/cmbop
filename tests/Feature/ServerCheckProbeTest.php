<?php

namespace Tests\Feature;

use Tests\TestCase;

class ServerCheckProbeTest extends TestCase
{
    public function test_server_check_script_is_present(): void
    {
        $this->assertFileExists(public_path('server-check.php'));
    }

    public function test_server_check_reports_boot_status_with_key(): void
    {
        $script = public_path('server-check.php');
        $wrapper = sprintf(
            '$_GET=["k"=>"slb-recover-2026"]; include %s;',
            var_export($script, true)
        );
        $cmd = sprintf('php -d display_errors=0 -r %s', escapeshellarg($wrapper));
        $out = [];
        $code = 0;
        exec($cmd.' 2>&1', $out, $code);
        $text = implode("\n", $out);

        $this->assertSame(0, $code, $text);
        $this->assertStringContainsString('BOOT=OK', $text);
        $this->assertStringContainsString('autoload=ok', $text);
        $this->assertStringContainsString('db=ok', $text);
    }

    public function test_server_check_rejects_missing_key(): void
    {
        $script = public_path('server-check.php');
        $wrapper = sprintf('$_GET=[]; include %s;', var_export($script, true));
        $cmd = sprintf('php -d display_errors=0 -r %s', escapeshellarg($wrapper));
        $out = [];
        $code = 0;
        exec($cmd.' 2>&1', $out, $code);
        $text = implode("\n", $out);

        $this->assertStringContainsString('Not found', $text);
    }
}
