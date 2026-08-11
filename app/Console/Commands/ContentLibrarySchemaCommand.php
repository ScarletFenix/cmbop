<?php

namespace App\Console\Commands;

use App\Support\ContentLibrarySchema;
use Illuminate\Console\Command;

/**
 * Fail the deploy/smoke check when Content Library columns are missing
 * (Hostinger schema drift historically broke cart + library counts).
 */
class ContentLibrarySchemaCommand extends Command
{
    protected $signature = 'content:library-schema {--json : Machine-readable output}';

    protected $description = 'Verify Content Library DB columns required for uploads, archive, market, and cart assignment';

    public function handle(): int
    {
        $missing = ContentLibrarySchema::missing();

        if ($this->option('json')) {
            $this->line(json_encode([
                'ready' => $missing === [],
                'missing' => $missing,
                'required' => ContentLibrarySchema::requiredColumns(),
            ], JSON_PRETTY_PRINT));

            return $missing === [] ? self::SUCCESS : self::FAILURE;
        }

        $this->newLine();
        if ($missing === []) {
            $this->line('<bg=green;fg=black> CONTENT LIBRARY SCHEMA OK </>');
            $this->newLine();
            foreach (ContentLibrarySchema::requiredColumns() as $table => $columns) {
                $this->line("  <fg=green>✓</> {$table}: ".implode(', ', $columns));
            }
            $this->newLine();

            return self::SUCCESS;
        }

        $this->line('<bg=red;fg=white> CONTENT LIBRARY SCHEMA MISSING COLUMNS </>');
        $this->newLine();
        foreach ($missing as $row) {
            $this->line(sprintf('  <fg=red>✗</> %s.%s', $row['table'], $row['column']));
        }
        $this->newLine();
        $this->warn('Run migrations (or Hostinger SQL patches), then re-check.');
        $this->line('  <fg=cyan>php artisan migrate --force</>');
        $this->line('  <fg=cyan>php artisan content:library-schema</>');
        $this->newLine();

        return self::FAILURE;
    }
}
