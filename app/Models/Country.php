<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Country extends Model
{
    protected $fillable = ['code', 'name', 'region'];

    public function languages()
    {
        return $this->belongsToMany(Language::class, 'country_language')
                    ->withPivot('is_primary')
                    ->withTimestamps();
    }

    public function primaryLanguages()
    {
        return $this->belongsToMany(Language::class, 'country_language')
                    ->wherePivot('is_primary', true);
    }

    public function sites()
    {
        return $this->hasMany(Site::class, 'country', 'code');
    }

    /**
     * Marketplace countries: Europe + major North America.
     */
    public function scopeMarketplace(Builder $query): Builder
    {
        $regions = config('markets.allowed_country_regions', ['Europe']);

        return $query->whereIn('region', $regions);
    }

    /**
     * @deprecated Use marketplace()
     */
    public function scopeEuropean(Builder $query): Builder
    {
        return $query->marketplace();
    }
}
