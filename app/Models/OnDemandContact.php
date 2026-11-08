<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;

class OnDemandContact extends Model
{
    public const PREVIEW_LIMIT = 2;

    public const MAX_LIST_ITEMS = 15;

    protected $fillable = [
        'site_url',
        'email',
        'whatsapp',
        'contacted_via_email',
        'notes',
        'created_by',
    ];

    public static function tableAvailable(): bool
    {
        try {
            return Schema::hasTable((new static)->getTable());
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return list<string>
     */
    public static function decodeList(mixed $value): array
    {
        if (is_array($value)) {
            $items = $value;
        } else {
            $raw = trim((string) $value);
            if ($raw === '') {
                return [];
            }
            $decoded = json_decode($raw, true);
            $items = is_array($decoded) ? $decoded : (preg_split('/[\r\n,;]+/', $raw) ?: []);
        }

        $out = [];
        foreach ($items as $item) {
            $item = trim((string) $item);
            if ($item !== '') {
                $out[] = $item;
            }
        }

        return array_values(array_unique($out));
    }

    public static function normalizeSiteName(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }

        $raw = preg_replace('#^(https?://)+#i', '', $raw) ?? $raw;
        $raw = preg_replace('#^//+#', '', $raw) ?? $raw;
        $raw = explode('/', $raw, 2)[0];
        $raw = explode('?', $raw, 2)[0];
        $raw = explode('#', $raw, 2)[0];

        return Site::normalizeMarketplaceDomain($raw);
    }

    /**
     * @param  list<string>|string|null  $value
     * @return list<string>
     */
    public static function normalizeSiteNames(mixed $value): array
    {
        $out = [];
        foreach (self::decodeList($value) as $item) {
            $host = self::normalizeSiteName($item);
            if ($host !== '') {
                $out[] = $host;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @return list<string>
     */
    public function listFor(string $field): array
    {
        if ($field === 'site_url') {
            return self::normalizeSiteNames($this->getRawOriginal('site_url') ?? $this->attributes['site_url'] ?? []);
        }

        return self::decodeList($this->getRawOriginal($field) ?? $this->attributes[$field] ?? []);
    }

    /**
     * @return array{items: list<string>, more: int, all: list<string>}
     */
    public function previewFor(string $field, int $limit = self::PREVIEW_LIMIT): array
    {
        $all = $this->listFor($field);

        return [
            'items' => array_slice($all, 0, $limit),
            'more' => max(0, count($all) - $limit),
            'all' => $all,
        ];
    }

    /**
     * @return list<string>
     */
    public static function takenSiteNames(?int $ignoreId = null): array
    {
        if (! self::tableAvailable()) {
            return [];
        }

        $taken = [];
        $query = self::query()->select(['id', 'site_url']);
        if ($ignoreId) {
            $query->where('id', '!=', $ignoreId);
        }

        foreach ($query->get() as $row) {
            foreach ($row->listFor('site_url') as $host) {
                $taken[] = $host;
            }
        }

        return $taken;
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    protected function siteUrl(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => self::normalizeSiteNames($value),
            set: fn ($value) => json_encode(self::normalizeSiteNames($value), JSON_UNESCAPED_SLASHES),
        );
    }

    protected function email(): Attribute
    {
        return $this->listAttribute();
    }

    protected function whatsapp(): Attribute
    {
        return $this->listAttribute();
    }

    protected function contactedViaEmail(): Attribute
    {
        return $this->listAttribute();
    }

    private function listAttribute(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => self::decodeList($value),
            set: fn ($value) => json_encode(array_slice(self::decodeList($value), 0, self::MAX_LIST_ITEMS), JSON_UNESCAPED_SLASHES),
        );
    }
}
