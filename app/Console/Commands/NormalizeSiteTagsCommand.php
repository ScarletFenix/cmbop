<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Support\SiteTag;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class NormalizeSiteTagsCommand extends Command
{
    protected $signature = 'sites:normalize-tags
                            {--dry-run : List conflicting rows without writing}
                            {--limit= : Max conflicting sites to process}';

    protected $description = 'Make listing tags exclusive when a site has more than one flag set';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $query = Site::query();
        SiteTag::constrainConflicting($query);
        $query->orderBy('id');

        $limit = $this->option('limit');
        if ($limit !== null && $limit !== '') {
            $query->limit(max(1, (int) $limit));
        }

        $sites = $query->get();
        if ($sites->isEmpty()) {
            $this->info('No sites have more than one listing tag.');

            return self::SUCCESS;
        }

        $this->info(($dryRun ? '[dry run] ' : '').$sites->count().' site(s) have more than one listing tag.');

        foreach ($sites as $site) {
            $from = [
                'sponsored' => (bool) $site->sponsored,
                'partner_material' => (bool) $site->partner_material,
                'as_you_prefer' => (bool) $site->as_you_prefer,
            ];
            $winner = SiteTag::fromFlags(
                $from['sponsored'],
                $from['partner_material'],
                $from['as_you_prefer']
            );
            $winnerLabel = SiteTag::label($winner) ?? SiteTag::NONE_LABEL;

            $this->line(sprintf(
                '  #%d %s [%s] -> %s',
                $site->id,
                (string) ($site->domain ?: $site->site_url),
                implode(', ', array_keys(array_filter($from))),
                $winnerLabel
            ));

            Log::info('sites.normalize-tags', [
                'site_id' => $site->id,
                'domain' => $site->domain,
                'from' => $from,
                'to' => $winner,
                'dry_run' => $dryRun,
            ]);

            if ($dryRun) {
                continue;
            }

            SiteTag::applyExclusive($site, $winner);
            $site->save();
        }

        $this->info($dryRun
            ? 'Dry run complete. No rows written.'
            : 'Normalized '.$sites->count().' site(s).');

        return self::SUCCESS;
    }
}
