<?php

namespace Tests\Unit;

use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

class MediaPathFilesystemTest extends TestCase
{
    public function test_unset_media_path_uses_default_public_storage(): void
    {
        $this->assertNull(config('filesystems.media_path'));
        $this->assertSame(
            storage_path('app/public'),
            config('filesystems.disks.public.root')
        );
        $this->assertSame(
            storage_path('app/public'),
            config('filesystems.links')[public_path('storage')]
        );
    }

    public function test_filesystems_config_file_honors_media_path_env(): void
    {
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'cmbop-media-env-'.uniqid('', true);
        $this->assertTrue(mkdir($dir, 0777, true));

        $previous = getenv('MEDIA_PATH');
        try {
            putenv('MEDIA_PATH='.$dir);
            $_ENV['MEDIA_PATH'] = $dir;
            $_SERVER['MEDIA_PATH'] = $dir;

            $config = require base_path('config/filesystems.php');

            $this->assertSame($dir, $config['media_path']);
            $this->assertSame($dir, $config['disks']['public']['root']);
            $this->assertSame($dir, $config['links'][public_path('storage')]);
        } finally {
            if ($previous === false) {
                putenv('MEDIA_PATH');
                unset($_ENV['MEDIA_PATH'], $_SERVER['MEDIA_PATH']);
            } else {
                putenv('MEDIA_PATH='.$previous);
                $_ENV['MEDIA_PATH'] = $previous;
                $_SERVER['MEDIA_PATH'] = $previous;
            }
            @rmdir($dir);
        }
    }

    public function test_configured_media_path_disk_reads_and_writes(): void
    {
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'cmbop-media-'.uniqid('', true);
        $this->assertTrue(mkdir($dir, 0777, true));

        try {
            Config::set('filesystems.media_path', $dir);
            Config::set('filesystems.disks.public.root', $dir);
            Config::set('filesystems.links', [
                public_path('storage') => $dir,
            ]);

            Storage::forgetDisk('public');

            $relative = 'sites/media-path-probe.txt';
            Storage::disk('public')->put($relative, 'durable-ok');

            $this->assertTrue(Storage::disk('public')->exists($relative));
            $this->assertSame('durable-ok', Storage::disk('public')->get($relative));
            $this->assertFileExists($dir.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative));
            $this->assertSame($dir, config('filesystems.disks.public.root'));
            $this->assertSame($dir, config('filesystems.links')[public_path('storage')]);
        } finally {
            Storage::forgetDisk('public');
            $probe = $dir.DIRECTORY_SEPARATOR.'sites'.DIRECTORY_SEPARATOR.'media-path-probe.txt';
            if (is_file($probe)) {
                @unlink($probe);
            }
            @rmdir($dir.DIRECTORY_SEPARATOR.'sites');
            @rmdir($dir);
        }
    }

    public function test_invalid_media_path_fails_assert_loudly(): void
    {
        Config::set('filesystems.media_path', '/tmp/cmbop-media-missing-'.uniqid('', true));

        $provider = new AppServiceProvider($this->app);
        $method = new ReflectionMethod(AppServiceProvider::class, 'assertConfiguredMediaPath');
        $method->setAccessible(true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('MEDIA_PATH is set');
        $method->invoke($provider);
    }

    public function test_testing_env_file_exists_so_dotenv_does_not_warn(): void
    {
        $this->assertFileExists(base_path('.env.testing'));
        $testing = file_get_contents(base_path('.env.testing'));
        $this->assertIsString($testing);
        $this->assertStringContainsString('APP_ENV=testing', $testing);
        $this->assertStringContainsString('DB_CONNECTION=sqlite', $testing);

        $phpunit = file_get_contents(base_path('phpunit.xml'));
        $this->assertIsString($phpunit);
        $this->assertStringContainsString('bootstrap="tests/bootstrap.php"', $phpunit);
    }

    public function test_env_example_documents_media_path(): void
    {
        $example = file_get_contents(base_path('.env.example'));
        $this->assertIsString($example);
        $this->assertStringContainsString('MEDIA_PATH=', $example);
        $this->assertFileExists(base_path('docs/hostinger-media.md'));
        $this->assertFileExists(base_path('docs/deploy-hostinger.md'));

        $deploy = file_get_contents(base_path('docs/deploy-hostinger.md'));
        $this->assertIsString($deploy);
        $this->assertStringContainsString('persistent/media', $deploy);
        $this->assertStringContainsString('storage:link', $deploy);
    }
}
