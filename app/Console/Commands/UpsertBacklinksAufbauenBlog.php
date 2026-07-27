<?php

namespace App\Console\Commands;

use App\Models\Blog;
use App\Models\User;
use App\Support\BacklinksAufbauenBlogPost;
use Illuminate\Console\Command;

class UpsertBacklinksAufbauenBlog extends Command
{
    protected $signature = 'blog:upsert-backlinks-aufbauen';

    protected $description = 'Publish/update the German "Backlinks aufbauen" pillar post (same body on all locale URLs)';

    public function handle(): int
    {
        $payload = BacklinksAufbauenBlogPost::payload();
        unset($payload['faq']);

        $existing = Blog::query()->where('slug', BacklinksAufbauenBlogPost::SLUG)->first();
        $authorUser = User::query()->orderBy('id')->first();

        $data = array_merge($payload, [
            'updated_by' => $authorUser?->id,
            'featured_image' => $existing?->featured_image,
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
            ['slug' => BacklinksAufbauenBlogPost::SLUG],
            $data
        );

        $this->info('Upserted blog #'.$blog->id.' ('.$blog->slug.') primary_locale='.($blog->primary_locale ?: 'null'));

        return self::SUCCESS;
    }
}
