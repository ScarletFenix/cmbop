<?php

namespace App\Models;

use App\Support\AdvertiserOrderStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class Project extends Model
{
    use HasFactory;

    /**
     * Guest Posting badge buckets shown on the advertiser projects page.
     *
     * @var list<string>
     */
    public const STAGE_KEYS = [
        'not_started',
        'in_progress',
        'waiting_approval',
        'needs_improvements',
        'completed',
        'rejected',
    ];

    protected $fillable = [
        'user_id',
        'project_name',
        'project_url',
        'slug', // ✅ REQUIRED
    ];

    /**
     * Auto-generate slug on create/update
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($project) {
            $project->slug = self::generateSlug($project->project_name);
        });

        static::updating(function ($project) {
            if ($project->isDirty('project_name')) {
                $project->slug = self::generateSlug($project->project_name);
            }
        });
    }

    public static function generateSlug($name)
    {
        return Str::slug($name);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Registrable host used to match a project URL to placement target URLs.
     */
    public static function hostFromUrl(?string $url): string
    {
        $raw = trim((string) $url);
        if ($raw === '') {
            return '';
        }

        $candidate = preg_match('#^https?://#i', $raw) === 1 ? $raw : 'https://'.$raw;
        $host = strtolower((string) parse_url($candidate, PHP_URL_HOST));
        $host = rtrim($host, '.');

        if ($host === '') {
            return '';
        }

        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }

    /**
     * @return array{not_started: int, in_progress: int, waiting_approval: int, needs_improvements: int, completed: int, rejected: int}
     */
    public static function emptyStageCounts(): array
    {
        return [
            'not_started' => 0,
            'in_progress' => 0,
            'waiting_approval' => 0,
            'needs_improvements' => 0,
            'completed' => 0,
            'rejected' => 0,
        ];
    }

    public static function stageBucket(string $stage): ?string
    {
        return match ($stage) {
            'awaiting_payment', 'scheduled', 'paid' => 'not_started',
            'processing' => 'in_progress',
            'url_delivered' => 'waiting_approval',
            'revision', 'content_revision' => 'needs_improvements',
            'completed' => 'completed',
            'cancelled', 'refunded', 'payment_failed' => 'rejected',
            default => null,
        };
    }

    /**
     * Count the advertiser's placements per project host and Guest Posting stage.
     *
     * A line matches a project when its target_url host equals the project's
     * project_url host (www. stripped). Lines without a target URL are skipped.
     *
     * @param  Collection<int, Order>  $orders  Orders with items eager-loaded.
     * @return array<string, array{not_started: int, in_progress: int, waiting_approval: int, needs_improvements: int, completed: int, rejected: int}>
     */
    public static function stageCountsByHost(Collection $orders): array
    {
        $byHost = [];

        foreach ($orders as $order) {
            foreach ($order->items as $item) {
                $host = self::hostFromUrl($item->target_url ?? null);
                if ($host === '') {
                    continue;
                }

                $bucket = self::stageBucket((string) (AdvertiserOrderStatus::meta($order, $item)['stage'] ?? ''));
                if ($bucket === null) {
                    continue;
                }

                if (! isset($byHost[$host])) {
                    $byHost[$host] = self::emptyStageCounts();
                }

                $byHost[$host][$bucket]++;
            }
        }

        return $byHost;
    }
}
