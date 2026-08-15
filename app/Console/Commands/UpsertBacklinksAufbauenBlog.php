<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\CuratedBlogWriter;
use App\Support\BacklinksAufbauenBlogPost;
use Illuminate\Console\Command;

class UpsertBacklinksAufbauenBlog extends Command
{
    protected $signature = 'blog:upsert-backlinks-aufbauen';

    protected $description = 'Publish/update the German "Backlinks aufbauen" pillar post (same body on all locale URLs)';

    public function handle(): int
    {
        $payload = BacklinksAufbauenBlogPost::payload();
        $authorUser = User::query()->orderBy('id')->first();
        $blog = CuratedBlogWriter::upsert(BacklinksAufbauenBlogPost::SLUG, $payload, $authorUser?->id);

        if (! $blog) {
            $this->warn('Skipped deleted curated slug '.BacklinksAufbauenBlogPost::SLUG);

            return self::SUCCESS;
        }

        $this->info('Upserted blog #'.$blog->id.' ('.$blog->slug.') primary_locale='.($blog->primary_locale ?: 'null'));

        return self::SUCCESS;
    }
}
