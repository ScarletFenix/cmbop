<?php

namespace App\Console\Commands;

use App\Models\Blog;
use App\Models\User;
use App\Support\GastbeitraegeEuropaBlogPost;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class UpsertGastbeitraegeEuropaBlog extends Command
{
    protected $signature = 'blog:upsert-gastbeitraege-europa';

    protected $description = 'Publish/update the German Europe guest-post buying guide (same body on all locale URLs)';

    public function handle(): int
    {
        $this->ensureFeaturedImage();

        $payload = GastbeitraegeEuropaBlogPost::payload();
        unset($payload['faq']);

        $existing = Blog::query()->where('slug', GastbeitraegeEuropaBlogPost::SLUG)->first();
        $authorUser = User::query()->orderBy('id')->first();

        $data = array_merge($payload, [
            'updated_by' => $authorUser?->id,
        ]);

        if (! $existing) {
            $data['published_at'] = now();
            $data['created_by'] = $authorUser?->id;
        } else {
            $data['published_at'] = $existing->published_at ?? now();
            $data['created_by'] = $existing->created_by ?? $authorUser?->id;
            if ($existing->author) {
                $data['author'] = $existing->author;
            }
        }

        $blog = Blog::updateOrCreate(
            ['slug' => GastbeitraegeEuropaBlogPost::SLUG],
            $data
        );

        $this->info('Upserted blog #'.$blog->id.' ('.$blog->slug.') primary_locale='.($blog->primary_locale ?: 'null'));

        return self::SUCCESS;
    }

    private function ensureFeaturedImage(): void
    {
        $source = public_path(GastbeitraegeEuropaBlogPost::FEATURED_ASSET);
        $destination = storage_path('app/public/'.GastbeitraegeEuropaBlogPost::FEATURED_STORAGE);

        if (! File::exists($source)) {
            $this->warn('Featured asset missing: '.$source);

            return;
        }

        File::ensureDirectoryExists(dirname($destination));
        File::copy($source, $destination);
    }
}
