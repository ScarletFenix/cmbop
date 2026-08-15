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

    public const AUDIENCE_ADVERTISERS_NEVER_CHECKED_OUT = 'advertisers_never_checked_out';

    public const AUDIENCE_ADVERTISERS_NO_PAID_ORDERS = 'advertisers_no_paid_orders';

    public const AUDIENCE_PUBLISHERS_NO_SITES = 'publishers_no_sites';

    public const AUDIENCE_ADVERTISERS_NEVER_DEPOSITED = 'advertisers_never_deposited';

    public const PICKER_LIMIT = 200;

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
            self::AUDIENCE_ADVERTISERS_NEVER_CHECKED_OUT,
            self::AUDIENCE_ADVERTISERS_NO_PAID_ORDERS,
            self::AUDIENCE_PUBLISHERS_NO_SITES,
            self::AUDIENCE_ADVERTISERS_NEVER_DEPOSITED,
        ];
    }

    public function advertiserCount(bool $includeUnverified = true): int
    {
        return $this->applyRecipientScope($this->queryForRole('advertiser'), $includeUnverified)->count();
    }

    public function publisherCount(bool $includeUnverified = true): int
    {
        return $this->applyRecipientScope($this->queryForRole('publisher'), $includeUnverified)->count();
    }

    public function advertisersNoOrdersCount(bool $includeUnverified = true): int
    {
        return $this->applyRecipientScope($this->queryAdvertisersNoOrders(), $includeUnverified)->count();
    }

    public function advertisersNoPaidOrdersCount(bool $includeUnverified = true): int
    {
        return $this->applyRecipientScope($this->queryAdvertisersNoPaidOrders(), $includeUnverified)->count();
    }

    public function publishersNoSitesCount(bool $includeUnverified = true): int
    {
        return $this->applyRecipientScope($this->queryPublishersNoSites(), $includeUnverified)->count();
    }

    public function advertisersNeverDepositedCount(bool $includeUnverified = true): int
    {
        return $this->applyRecipientScope($this->queryAdvertisersNeverDeposited(), $includeUnverified)->count();
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
     * Advertisers who have never placed an order row (including abandoned checkout).
     */
    public function queryAdvertisersNoOrders(): Builder
    {
        return $this->queryForRole('advertiser')->whereDoesntHave('orders');
    }

    /**
     * Alias of queryAdvertisersNoOrders() — never started checkout.
     */
    public function queryAdvertisersNeverCheckedOut(): Builder
    {
        return $this->queryAdvertisersNoOrders();
    }

    /**
     * Advertisers who have never completed a paid order.
     */
    public function queryAdvertisersNoPaidOrders(): Builder
    {
        return $this->queryForRole('advertiser')
            ->whereDoesntHave('orders', function (Builder $q) {
                $q->where('payment_status', 'paid');
            });
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
            self::AUDIENCE_ADVERTISERS_NO_ORDERS, self::AUDIENCE_ADVERTISERS_NEVER_CHECKED_OUT => $this->queryAdvertisersNoOrders(),
            self::AUDIENCE_ADVERTISERS_NO_PAID_ORDERS => $this->queryAdvertisersNoPaidOrders(),
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
    public function collect(string $audience, ?array $selectedIds = null, bool $includeUnverified = false): Collection
    {
        return match ($audience) {
            self::AUDIENCE_ADVERTISERS => $this->applyRecipientScope($this->queryForRole('advertiser'), $includeUnverified)->get(),
            self::AUDIENCE_PUBLISHERS => $this->applyRecipientScope($this->queryForRole('publisher'), $includeUnverified)->get(),
            self::AUDIENCE_BOTH => $this->applyRecipientScope($this->queryForRole('advertiser'), $includeUnverified)
                ->get()
                ->merge($this->applyRecipientScope($this->queryForRole('publisher'), $includeUnverified)->get())
                ->unique('id')
                ->values(),
            self::AUDIENCE_ADVERTISERS_NO_ORDERS, self::AUDIENCE_ADVERTISERS_NEVER_CHECKED_OUT => $this->applyRecipientScope($this->queryAdvertisersNoOrders(), $includeUnverified)->get(),
            self::AUDIENCE_ADVERTISERS_NO_PAID_ORDERS => $this->applyRecipientScope($this->queryAdvertisersNoPaidOrders(), $includeUnverified)->get(),
            self::AUDIENCE_PUBLISHERS_NO_SITES => $this->applyRecipientScope($this->queryPublishersNoSites(), $includeUnverified)->get(),
            self::AUDIENCE_ADVERTISERS_NEVER_DEPOSITED => $this->applyRecipientScope($this->queryAdvertisersNeverDeposited(), $includeUnverified)->get(),
            self::AUDIENCE_SELECTED => $this->querySelected($selectedIds, $includeUnverified)->get(),
            default => collect(),
        };
    }

    /**
     * Recipient count without hydrating User models.
     *
     * @param  array<int, int|string>|null  $selectedIds
     */
    public function count(string $audience, ?array $selectedIds = null, bool $includeUnverified = false): int
    {
        return match ($audience) {
            self::AUDIENCE_ADVERTISERS => $this->advertiserCount($includeUnverified),
            self::AUDIENCE_PUBLISHERS => $this->publisherCount($includeUnverified),
            self::AUDIENCE_BOTH => $this->bothUniqueCount($includeUnverified),
            self::AUDIENCE_ADVERTISERS_NO_ORDERS, self::AUDIENCE_ADVERTISERS_NEVER_CHECKED_OUT => $this->advertisersNoOrdersCount($includeUnverified),
            self::AUDIENCE_ADVERTISERS_NO_PAID_ORDERS => $this->advertisersNoPaidOrdersCount($includeUnverified),
            self::AUDIENCE_PUBLISHERS_NO_SITES => $this->publishersNoSitesCount($includeUnverified),
            self::AUDIENCE_ADVERTISERS_NEVER_DEPOSITED => $this->advertisersNeverDepositedCount($includeUnverified),
            self::AUDIENCE_SELECTED => $this->querySelected($selectedIds, $includeUnverified)->count(),
            default => 0,
        };
    }

    public function bothUniqueCount(bool $includeUnverified = true): int
    {
        $roleIds = $this->marketplaceRoleIds();

        if ($roleIds->isEmpty()) {
            return 0;
        }

        $query = User::query()
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->whereHas('roles', fn (Builder $q) => $q->whereIn('roles.id', $roleIds));

        return $this->applyRecipientScope($query, $includeUnverified)->count();
    }

    /**
     * @return Collection<int, User>
     */
    public function pickerUsers(string $roleName, int $limit = self::PICKER_LIMIT): Collection
    {
        return $this->queryForRole($roleName)
            ->setEagerLoads([])
            ->limit($limit)
            ->get(['id', 'name', 'email']);
    }

    /**
     * @param  array<int, int|string>|null  $selectedIds
     */
    protected function querySelected(?array $selectedIds, bool $includeUnverified): Builder
    {
        $ids = array_values(array_filter(array_map('intval', $selectedIds ?: [])));
        $roleIds = $this->marketplaceRoleIds();

        if ($ids === []) {
            return User::query()->whereRaw('1 = 0');
        }

        $query = User::query()
            ->whereIn('id', $ids)
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->orderBy('name');

        if ($roleIds->isEmpty()) {
            return $query->whereRaw('1 = 0');
        }

        $query->whereHas('roles', fn (Builder $q) => $q->whereIn('roles.id', $roleIds));

        return $this->applyRecipientScope($query, $includeUnverified);
    }

    protected function applyRecipientScope(Builder $query, bool $includeUnverified): Builder
    {
        if (! $includeUnverified) {
            $query->whereNotNull('email_verified_at');
        }

        return $query;
    }

    /**
     * @return Collection<int, int>
     */
    protected function marketplaceRoleIds(): Collection
    {
        return Role::query()
            ->whereIn('name', ['advertiser', 'publisher'])
            ->pluck('id');
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

    public function stats(bool $includeUnverified = true): array
    {
        $neverCheckedOut = $this->advertisersNoOrdersCount($includeUnverified);

        return [
            'advertisers' => $this->advertiserCount($includeUnverified),
            'publishers' => $this->publisherCount($includeUnverified),
            'both_unique' => $this->bothUniqueCount($includeUnverified),
            'advertisers_no_orders' => $neverCheckedOut,
            'advertisers_never_checked_out' => $neverCheckedOut,
            'advertisers_no_paid_orders' => $this->advertisersNoPaidOrdersCount($includeUnverified),
            'publishers_no_sites' => $this->publishersNoSitesCount($includeUnverified),
            'advertisers_never_deposited' => $this->advertisersNeverDepositedCount($includeUnverified),
        ];
    }
}
