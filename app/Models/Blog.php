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
        'primary_locale',
        'excerpt',
        'content',
        'featured_image',
        'author',
        'tags',
        'status',
        'published_at',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'tags' => 'array',
        'published_at' => 'datetime',
    ];

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
}
