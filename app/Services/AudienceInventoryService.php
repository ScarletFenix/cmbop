<?php

namespace App\Services;

use App\Models\Role;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AudienceInventoryService
{
    public const AUDIENCE_ADVERTISERS = 'advertisers';

    public const AUDIENCE_PUBLISHERS = 'publishers';

    public const AUDIENCE_BOTH = 'both';

    public const AUDIENCE_SELECTED = 'selected';

    public const AUDIENCE_ADVERTISERS_NEVER_DEPOSITED = 'advertisers_never_deposited';

    /** Statuses that mean the wallet was (or is being) credited from a deposit. */
    public const CREDITED_DEPOSIT_STATUSES = ['approved', 'completed'];

    public function advertiserCount(): int
    {
        return $this->queryForRole('advertiser')->count();
    }

    public function publisherCount(): int
    {
        return $this->queryForRole('publisher')->count();
    }

    public function advertisersNeverDepositedCount(): int
    {
        return $this->queryAdvertisersNeverDeposited()->count();
    }

    public function queryForRole(string $roleName): Builder
    {
        $role = Role::query()->where('name', $roleName)->first();

        $query = User::query()
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->with(['roles', 'activeRoleRelation'])
            ->orderBy('name');

        if (! $role) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas('roles', fn (Builder $q) => $q->where('roles.id', $role->id));
    }

    /**
     * Advertisers with email who have never had an approved/completed deposit.
     * Welcome-bonus wallet balance alone does not exclude them.
     */
    public function queryAdvertisersNeverDeposited(): Builder
    {
        return $this->queryForRole('advertiser')
            ->whereDoesntHave('depositRequests', function (Builder $q) {
                $q->whereIn('status', self::CREDITED_DEPOSIT_STATUSES);
            });
    }

    /**
     * Resolve a list/export/paginate key to a user query.
     */
    public function queryForAudienceKey(string $audienceKey): Builder
    {
        return match ($audienceKey) {
            self::AUDIENCE_ADVERTISERS, 'advertiser' => $this->queryForRole('advertiser'),
            self::AUDIENCE_PUBLISHERS, 'publisher' => $this->queryForRole('publisher'),
            self::AUDIENCE_ADVERTISERS_NEVER_DEPOSITED => $this->queryAdvertisersNeverDeposited(),
            default => User::query()->whereRaw('1 = 0'),
        };
    }

    public function paginate(string $audienceKey, ?string $search = null, int $perPage = 25): LengthAwarePaginator
    {
        $query = $this->queryForAudienceKey($audienceKey);

        if (filled($search)) {
            $term = '%'.trim($search).'%';
            $query->where(function (Builder $q) use ($term) {
                $q->where('name', 'like', $term)
                    ->orWhere('email', 'like', $term);
            });
        }

        return $query->paginate($perPage)->withQueryString();
    }

    /**
     * @return Collection<int, User>
     */
    public function collect(string $audience, ?array $selectedIds = null): Collection
    {
        return match ($audience) {
            self::AUDIENCE_ADVERTISERS => $this->queryForRole('advertiser')->get(),
            self::AUDIENCE_PUBLISHERS => $this->queryForRole('publisher')->get(),
            self::AUDIENCE_BOTH => $this->queryForRole('advertiser')
                ->get()
                ->merge($this->queryForRole('publisher')->get())
                ->unique('id')
                ->values(),
            self::AUDIENCE_ADVERTISERS_NEVER_DEPOSITED => $this->queryAdvertisersNeverDeposited()->get(),
            self::AUDIENCE_SELECTED => User::query()
                ->whereIn('id', $selectedIds ?: [])
                ->whereNotNull('email')
                ->orderBy('name')
                ->get(),
            default => collect(),
        };
    }

    public function exportCsv(string $audienceKey): StreamedResponse
    {
        $filename = $audienceKey.'-audience-'.now()->format('Y-m-d-His').'.csv';
        $users = $this->queryForAudienceKey($audienceKey)->get();

        return response()->streamDownload(function () use ($users, $audienceKey) {
            $out = fopen('php://output', 'w');
            fputcsv($out, [
                'id',
                'name',
                'email',
                'audience_role',
                'all_roles',
                'active_role',
                'email_verified',
                'registered_at',
            ]);

            foreach ($users as $user) {
                fputcsv($out, [
                    $user->id,
                    $user->name,
                    $user->email,
                    $audienceKey,
                    $user->roles->pluck('name')->implode('|'),
                    $user->activeRole(),
                    $user->hasVerifiedEmail() ? 'yes' : 'no',
                    optional($user->created_at)?->toDateTimeString(),
                ]);
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function stats(): array
    {
        return [
            'advertisers' => $this->advertiserCount(),
            'publishers' => $this->publisherCount(),
            'both_unique' => $this->collect(self::AUDIENCE_BOTH)->count(),
            'advertisers_never_deposited' => $this->advertisersNeverDepositedCount(),
        ];
    }
}
