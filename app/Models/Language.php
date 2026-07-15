<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Language extends Model
{
    protected $fillable = ['code', 'name', 'native_name'];

    public function countries()
    {
        return $this->belongsToMany(Country::class, 'country_language');
    }

    public function sites()
    {
        return $this->hasMany(Site::class, 'language', 'code');
    }

    /**
     * Only European marketplace languages.
     */
    public function scopeEuropean(Builder $query): Builder
    {
        return $query->whereIn('code', config('markets.european_language_codes', []));
    }
}
