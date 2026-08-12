<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Project extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'project_name',
        'project_url',
        'slug',
    ];

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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Display name used in campaign-minded UI.
     */
    public function getCampaignNameAttribute(): string
    {
        return (string) $this->project_name;
    }
}
