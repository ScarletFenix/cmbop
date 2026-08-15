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

    public const AUDIENCE_ADVERTISERS_NO_ORDERS = 'advertisers_no_orders';

    public const AUDIENCE_PUBLISHERS_NO_SITES = 'publishers_no_sites';

    public const AUDIENCE_ADVERTISERS_NEVER_DEPOSITED = 'advertisers_never_deposited';

    /**
     * @return list<string>
     */
    public static function audienceKeys(): array
    {
        return [
            self::AUDIENCE_ADVERTISERS,
            self::AUDIENCE_PUBLISHERS,
            self::AUDIENCE_BOTH,
            self::AUDIENCE_SELECTED,
            self::AUDIENCE_ADVERTISERS_NO_ORDERS,
            self::AUDIENCE_PUBLISHERS_NO_SITES,
            self::AUDIENCE_ADVERTISERS_NEVER_DEPOSITED,
        ];
    }

    public function advertiserCount(): int
    {
        return $this->queryForRole('advertiser')->count();
    }

    public function publisherCount(): int
    {
        return $this->queryForRole('publisher')->count();
    }

    public function advertisersNoOrdersCount(): int
    {
        return $this->queryAdvertisersNoOrders()->count();
    }

    public function publishersNoSitesCount(): int
    {
        return $this->queryPublishersNoSites()->count();
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
     * Advertisers who have never placed an order.
     */
    public function queryAdvertisersNoOrders(): Builder
    {
        return $this->queryForRole('advertiser')->whereDoesntHave('orders');
    }

    /**
     * Publishers who have not listed any site.
     */
    public function queryPublishersNoSites(): Builder
    {
        return $this->queryForRole('publisher')->whereDoesntHave('sites');
    }

    /**
     * Advertisers who have actually bought something.
     *
     * An abandoned unpaid checkout is not a customer, so the paid gate matters:
     * the new-sites digest is aimed at people who already know what the catalog
     * is for.
     */
    public function queryAdvertisersWithPaidOrders(): Builder
    {
        return $this->queryForRole('advertiser')
            ->whereHas('orders', function (Builder $q) {
                $q->where('payment_status', 'paid');
            });
    }

    /**
     * Advertisers who have never funded their wallet.
     *
     * Only a credited deposit counts: one still awaiting confirmation or since
     * rejected brought no money in, and the signup bonus is not a deposit.
     */
    public function queryAdvertisersNeverDeposited(): Builder
    {
        return $this->queryForRole('advertiser')
            ->whereDoesntHave('depositRequests', function (Builder $q) {
                $q->whereIn('status', ['approved', 'completed']);
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
            self::AUDIENCE_ADVERTISERS_NO_ORDERS => $this->queryAdvertisersNoOrders(),
            self::AUDIENCE_PUBLISHERS_NO_SITES => $this->queryPublishersNoSites(),
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
            self::AUDIENCE_ADVERTISERS_NO_ORDERS => $this->queryAdvertisersNoOrders()->get(),
            self::AUDIENCE_PUBLISHERS_NO_SITES => $this->queryPublishersNoSites()->get(),
            self::AUDIENCE_ADVERTISERS_NEVER_DEPOSITED => $this->queryAdvertisersNeverDeposited()->get(),
            self::AUDIENCE_SELECTED => User::query()
                ->whereIn('id', $selectedIds ?: [])
                ->whereNotNull('email')
                ->orderBy('name')
                ->get(),
            default => collect(),
        };
    }

    /**
     * Recipient count without hydrating User models.
     *
     * @param  array<int, int|string>|null  $selectedIds
     */
    public function count(string $audience, ?array $selectedIds = null): int
    {
        return match ($audience) {
            self::AUDIENCE_ADVERTISERS => $this->advertiserCount(),
            self::AUDIENCE_PUBLISHERS => $this->publisherCount(),
            self::AUDIENCE_BOTH => $this->bothUniqueCount(),
            self::AUDIENCE_ADVERTISERS_NO_ORDERS => $this->advertisersNoOrdersCount(),
            self::AUDIENCE_PUBLISHERS_NO_SITES => $this->publishersNoSitesCount(),
            self::AUDIENCE_ADVERTISERS_NEVER_DEPOSITED => $this->advertisersNeverDepositedCount(),
            self::AUDIENCE_SELECTED => $this->selectedCount($selectedIds),
            default => 0,
        };
    }

    public function bothUniqueCount(): int
    {
        $roleIds = Role::query()
            ->whereIn('name', ['advertiser', 'publisher'])
            ->pluck('id');

        if ($roleIds->isEmpty()) {
            return 0;
        }

        return User::query()
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->whereHas('roles', fn (Builder $q) => $q->whereIn('roles.id', $roleIds))
            ->count();
    }

    /**
     * @param  array<int, int|string>|null  $selectedIds
     */
    protected function selectedCount(?array $selectedIds): int
    {
        $ids = array_values(array_filter(array_map('intval', $selectedIds ?: [])));

        if ($ids === []) {
            return 0;
        }

        return User::query()
            ->whereIn('id', $ids)
            ->whereNotNull('email')
            ->count();
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
            'both_unique' => $this->bothUniqueCount(),
            'advertisers_no_orders' => $this->advertisersNoOrdersCount(),
            'publishers_no_sites' => $this->publishersNoSitesCount(),
            'advertisers_never_deposited' => $this->advertisersNeverDepositedCount(),
        ];
    }
}
