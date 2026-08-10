<?php

/**
 * Standalone Hostinger/bootstrap probe — does NOT boot Laravel first.
 *
 * Visit: /server-check.php?k=slb-recover-2026
 * Optional: &clear=1 to delete bootstrap/cache/*.php (except .gitignore)
 *
 * Delete this file after the outage is resolved.
 */

declare(strict_types=1);
use Illuminate\Contracts\Console\Kernel;

$key = (string) ($_GET['k'] ?? '');
if (! hash_equals('slb-recover-2026', $key)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Not found\n";
    exit;
}

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');

$root = dirname(__DIR__);
$lines = [];
$lines[] = 'time='.gmdate('c');
$lines[] = 'php='.PHP_VERSION;
$lines[] = 'sapi='.PHP_SAPI;
$lines[] = 'root='.$root;
$lines[] = 'cwd='.getcwd();

$paths = [
    'vendor/autoload.php',
    'bootstrap/app.php',
    '.env',
    'storage/logs',
    'storage/framework',
    'bootstrap/cache',
    'bootstrap/cache/config.php',
    'bootstrap/cache/routes-v7.php',
    'bootstrap/cache/packages.php',
    'bootstrap/cache/services.php',
    'storage/framework/maintenance.php',
];

foreach ($paths as $rel) {
    $full = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $rel);
    $lines[] = $rel.'='.(is_file($full) ? 'file' : (is_dir($full) ? 'dir' : 'MISSING'))
        .(file_exists($full) ? ' mode='.substr(sprintf('%o', @fileperms($full)), -4) : '');
}

$lines[] = 'storage_writable='.(is_writable($root.'/storage') ? 'yes' : 'no');
$lines[] = 'cache_writable='.(is_writable($root.'/bootstrap/cache') ? 'yes' : 'no');
$lines[] = 'logs_writable='.(is_writable($root.'/storage/logs') ? 'yes' : 'no');

if (isset($_GET['clear']) && $_GET['clear'] === '1') {
    $cacheDir = $root.'/bootstrap/cache';
    $cleared = [];
    foreach (glob($cacheDir.'/*.php') ?: [] as $file) {
        if (@unlink($file)) {
            $cleared[] = basename($file);
        }
    }
    $lines[] = 'cleared='.($cleared ? implode(',', $cleared) : '(none)');
}

$autoload = $root.'/vendor/autoload.php';
if (! is_file($autoload)) {
    $lines[] = 'BOOT=FAIL missing vendor/autoload.php — run composer install on Hostinger';
    echo implode("\n", $lines)."\n";
    exit;
}

try {
    require $autoload;
    $lines[] = 'autoload=ok';
} catch (Throwable $e) {
    $lines[] = 'autoload=FAIL '.$e::class.': '.$e->getMessage();
    $lines[] = 'at '.$e->getFile().':'.$e->getLine();
    echo implode("\n", $lines)."\n";
    exit;
}

try {
    $app = require $root.'/bootstrap/app.php';
    $lines[] = 'bootstrap=ok';
} catch (Throwable $e) {
    $lines[] = 'bootstrap=FAIL '.$e::class.': '.$e->getMessage();
    $lines[] = 'at '.$e->getFile().':'.$e->getLine();
    echo implode("\n", $lines)."\n";
    exit;
}

try {
    $app->make(Kernel::class)->bootstrap();
    $lines[] = 'kernel_bootstrap=ok';
    $lines[] = 'app_env='.(string) $app->environment();
} catch (Throwable $e) {
    $lines[] = 'kernel_bootstrap=FAIL '.$e::class.': '.$e->getMessage();
    $lines[] = 'at '.$e->getFile().':'.$e->getLine();
    echo implode("\n", $lines)."\n";
    exit;
}

try {
    $pdo = $app->make('db')->connection()->getPdo();
    $lines[] = 'db=ok driver='.$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
} catch (Throwable $e) {
    $lines[] = 'db=FAIL '.$e::class.': '.$e->getMessage();
}

$lines[] = 'BOOT=OK';
echo implode("\n", $lines)."\n";
