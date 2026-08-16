<?php

// app/Models/Blog.php

namespace App\Models;

use App\Models\Concerns\ToleratesUnparseableDates;
use App\Support\PublicI18n;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class Blog extends Model
{
    use HasFactory;
    use ToleratesUnparseableDates;

    protected $fillable = [
        'title',
        'slug',
        'curated_key',
        'primary_locale',
        'excerpt',
        'content',
        'featured_image',
        'author',
        'tags',
        'status',
        'published_at',
        'manually_edited_at',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'tags' => 'array',
        'published_at' => 'datetime',
        'manually_edited_at' => 'datetime',
    ];

    /**
     * Always hand back a flat list of tag strings.
     *
     * The column is a JSON array, and every view echoes its elements straight
     * into markup. One row whose JSON nests an array — from an import, a seeder,
     * or a hand-edited row — used to take down the whole blog index, every post
     * page and the sitemap with a htmlspecialchars() type error, because Blade
     * cannot echo an array. Normalising on read means bad data degrades to a
     * missing tag instead of a 500, and heals itself the next time the row is
     * saved.
     */
    public function getTagsAttribute($value): array
    {
        $decoded = is_array($value) ? $value : json_decode((string) $value, true);

        if (! is_array($decoded)) {
            return [];
        }

        $tags = [];

        array_walk_recursive($decoded, function ($tag) use (&$tags) {
            if (is_scalar($tag) && trim((string) $tag) !== '') {
                $tags[] = trim((string) $tag);
            }
        });

        return array_values(array_unique($tags));
    }

    /**
     * Store tags in the shape the accessor promises to return.
     *
     * Accepts the comma-separated string the admin form posts as well as an
     * array, so callers cannot reintroduce the nesting above.
     */
    public function setTagsAttribute($value): void
    {
        if ($value === null || $value === '') {
            $this->attributes['tags'] = null;

            return;
        }

        $incoming = is_array($value) ? $value : explode(',', (string) $value);
        $tags = [];

        array_walk_recursive($incoming, function ($tag) use (&$tags) {
            if (is_scalar($tag) && trim((string) $tag) !== '') {
                $tags[] = trim((string) $tag);
            }
        });

        $this->attributes['tags'] = json_encode(array_values(array_unique($tags)));
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($blog) {
            if (empty($blog->slug)) {
                $blog->slug = Str::slug($blog->title);
            }
        });
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function translations(): HasMany
    {
        return $this->hasMany(BlogTranslation::class);
    }

    public function translationFor(string $locale, ?string $fallback = 'en'): ?BlogTranslation
    {
        $translations = $this->relationLoaded('translations')
            ? $this->getRelation('translations')
            : $this->translations()->get();

        $primary = $translations
            ->first(fn (BlogTranslation $translation) => $translation->locale === $locale && $translation->is_published);
        if ($primary) {
            return $primary;
        }

        if ($fallback === null || $fallback === $locale) {
            return null;
        }

        return $translations
            ->first(fn (BlogTranslation $translation) => $translation->locale === $fallback && $translation->is_published);
    }

    /**
     * Locale to render on public pages: requested, English, then primary.
     * Index/footer used to stop at English and keep blogs.slug, which 404s
     * or shows the wrong language after a DE-primary rename.
     */
    public function displayTranslation(?string $locale = null, ?string $fallback = 'en'): ?BlogTranslation
    {
        $locale = $locale ?: 'en';

        return $this->translationFor($locale, $fallback)
            ?: ($this->primary_locale
                ? $this->translationFor($this->primary_locale, null)
                : null);
    }

    public function availableLocales(): array
    {
        $translations = $this->relationLoaded('translations')
            ? $this->getRelation('translations')
            : $this->translations()->get();

        return $translations
            ->filter(fn (BlogTranslation $translation) => $translation->is_published)
            ->pluck('locale')
            ->unique()
            ->values()
            ->all();
    }

    public function scopePublished($query)
    {
        return $query->where('status', 'published')
            ->whereNotNull('published_at')
            ->where('published_at', '>=', static::PLAUSIBLE_SQL_DATETIME_FLOOR)
            ->where('published_at', '<=', now())
            ->where('published_at', '<=', static::PLAUSIBLE_SQL_DATETIME_CEIL);
    }

    public function getFormattedTagsAttribute()
    {
        if ($this->tags && is_array($this->tags)) {
            return implode(', ', $this->tags);
        }

        return '';
    }

    /**
     * Preferred public URL for canonical / share tags when primary_locale is set.
     */
    public function canonicalUrl(?string $locale = null, ?string $fallbackLocale = 'en'): string
    {
        $preferredLocale = $locale ?: $this->primary_locale;
        if (! PublicI18n::isSupported($preferredLocale)) {
            $preferredLocale = $fallbackLocale;
        }

        $translation = $preferredLocale ? $this->displayTranslation($preferredLocale, $fallbackLocale) : null;
        $slug = $translation?->slug ?: $this->slug;
        $canonicalLocale = $translation?->locale ?: $preferredLocale;

        return PublicI18n::urlForLocale('blog/'.$slug, $canonicalLocale);
    }

    /**
     * Root-relative /media URL for the featured image (Hostinger-safe).
     */
    public function featuredImageUrl(): ?string
    {
        $path = $this->featured_image;
        if (! is_string($path) || trim($path) === '') {
            return null;
        }

        $path = trim($path);
        $urlPath = $path;
        if (preg_match('#^(https?:)?//#i', $path) === 1) {
            $parsed = parse_url($path, PHP_URL_PATH);
            $urlPath = is_string($parsed) && $parsed !== '' ? $parsed : $path;
        }

        $normalized = ltrim(str_replace('\\', '/', $urlPath), '/');
        if (preg_match('#(?:^|/)assets/img/blog/([^/]+)$#i', $normalized, $matches)) {
            $filename = $matches[1];
            $featured = 'blogs/featured/'.$filename;
            $content = 'blogs/content/'.$filename;
            $disk = Storage::disk('public');
            // Leftover featured_image values are hero files; prefer featured/,
            // then the content/ copy heal also writes, so a missing featured
            // publish does not 404 the card image.
            $path = $disk->exists($featured) ? $featured : ($disk->exists($content) ? $content : $featured);
        } elseif (preg_match('#(?:storage|media)/(blogs/(?:content|featured)/.+)$#i', $normalized, $matches)) {
            $path = $matches[1];
        }

        return Site::publicDiskUrl($path);
    }

    /**
     * Absolute featured-image URL for Open Graph / JSON-LD.
     */
    public function featuredImageAbsoluteUrl(): ?string
    {
        $relative = $this->featuredImageUrl();

        return $relative ? url($relative) : null;
    }
}
