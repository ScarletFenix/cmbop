<?php

// app/Models/Blog.php

namespace App\Models;

use App\Support\PublicI18n;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Blog extends Model
{
    use HasFactory;

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
            ->where('published_at', '<=', now());
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

        $translation = $preferredLocale ? $this->translationFor($preferredLocale, $fallbackLocale) : null;
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
        if (is_string($path) && preg_match('#^(https?:)?//#i', $path) === 1) {
            $urlPath = parse_url($path, PHP_URL_PATH);
            if (is_string($urlPath) && preg_match('#/(?:storage|media)/(blogs/(?:content|featured)/.+)$#i', $urlPath, $matches)) {
                $path = $matches[1];
            }
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
