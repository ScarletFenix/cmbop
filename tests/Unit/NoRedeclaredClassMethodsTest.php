<?php

namespace Tests\Unit;

use App\Http\Controllers\Publisher\SiteController;
use App\Models\OrderItem;
use App\Models\Site;
use Tests\TestCase;

class NoRedeclaredClassMethodsTest extends TestCase
{
    public function test_order_item_site_and_publisher_site_controller_autoload(): void
    {
        $this->assertTrue(class_exists(OrderItem::class));
        $this->assertTrue(class_exists(Site::class));
        $this->assertTrue(class_exists(SiteController::class));
    }

    public function test_app_php_classes_do_not_redeclare_methods(): void
    {
        $methodRe = '/^(\s*)(?:(?:public|protected|private|final|static)\s+)+function\s+(\w+)\s*\(/';
        $duplicates = [];

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $path = $file->getPathname();
            $lines = file($path) ?: [];
            $names = [];

            foreach ($lines as $i => $line) {
                if (! preg_match($methodRe, $line, $m)) {
                    continue;
                }

                $indent = str_replace("\t", '    ', $m[1]);
                if (strlen($indent) !== 4) {
                    continue;
                }

                $names[$m[2]][] = $i + 1;
            }

            $dup = array_filter($names, fn ($linesForName) => count($linesForName) > 1);
            if ($dup !== []) {
                $rel = str_replace(base_path().DIRECTORY_SEPARATOR, '', $path);
                $duplicates[$rel] = $dup;
            }
        }

        $this->assertSame([], $duplicates, 'Duplicate class methods remain after a bad merge.');
    }
}
