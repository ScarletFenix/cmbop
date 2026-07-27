<?php

// app/Models/Blog.php

namespace App\Models;

use App\Support\PublicI18n;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
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
    public function canonicalUrl(?string $fallbackLocale = null): string
    {
        $locale = $this->primary_locale;
        if (! PublicI18n::isSupported($locale)) {
            $locale = $fallbackLocale;
        }

        return PublicI18n::urlForLocale('blog/'.$this->slug, $locale);
    }
}
