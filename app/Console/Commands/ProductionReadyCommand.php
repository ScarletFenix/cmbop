<?php

namespace App\Console\Commands;

use App\Support\ProductionReadiness;
use App\Support\PublicStorageLink;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Console\Command;

/**
 * Confirm the Hostinger / production settings that make the launch path work:
 * MySQL, APP_URL, MEDIA_PATH, migrate, 64M uploads, scheduler/queue, then
 * register → verify email → catalog image → wallet order → chat mail.
 */
class ProductionReadyCommand extends Command
{
    protected $signature = 'ops:production-ready
                            {--strict : Fail on warnings as well as failures}
                            {--repair : Seed missing roles and recreate the public/storage symlink}';

    protected $description = 'Check MySQL, APP_URL, MEDIA_PATH, migrations, uploads, mail drain, and roles';

    public function handle(ProductionReadiness $readiness): int
    {
        if ($this->option('repair')) {
            $this->repair();
        }

        $rows = [];
        foreach ($readiness->checks() as $check) {
            $rows[] = [
                strtoupper($check['severity']),
                $check['title'],
                $check['detail'],
            ];
        }

        $this->table(['Status', 'Check', 'Detail'], $rows);

        foreach ($readiness->checks() as $check) {
            if ($check['fix'] !== '' && $check['severity'] !== ProductionReadiness::SEVERITY_OK) {
                $this->line('  <fg=cyan>'.$check['id'].'</>  '.$check['fix']);
            }
        }

        $strict = (bool) $this->option('strict');
        if ($readiness->isHealthy($strict)) {
            $this->newLine();
            $this->info('Production readiness: OK');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->error($strict
            ? 'Production readiness: FAIL (strict — warnings count)'
            : 'Production readiness: FAIL');

        return self::FAILURE;
    }

    private function repair(): void
    {
        $this->info('Repairing roles and public/storage…');

        try {
            (new RolesTableSeeder)->run();
            $this->line('  roles seeded (advertiser, publisher, admin, marketing)');
        } catch (\Throwable $e) {
            $this->error('  Could not seed roles: '.$e->getMessage());
        }

        $link = PublicStorageLink::ensure();
        if ($link['ok']) {
            $this->line($link['repaired']
                ? '  public/storage symlink repaired'
                : '  public/storage already correct');
        } else {
            $this->error('  '.$link['message']);
        }
    }
}
