<?php

namespace App\Models;

use App\Notifications\VerifyEmail;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'google_id',
        'google_token',
        'google_refresh_token',
        'avatar',
        'active_role_id',
        'email_verified_at',
        'stripe_customer_id',
        'stripe_default_payment_method_id',
        'payout_business_name',
        'payout_paypal_email',
        'payout_wise_email',
        'payout_bank_holder_name',
        'payout_bank_name',
        'payout_bank_account',
        'payout_bank_swift',
        'payout_crypto_trx_wallet',
        'payout_crypto_type',
        'payout_crypto_trx_verified_at',
        'payout_profile_locked_at',
        'payout_preferred_method',
        'can_activate_sites',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'google_token',
        'google_refresh_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'payout_crypto_trx_verified_at' => 'datetime',
        'payout_profile_locked_at' => 'datetime',
        'can_activate_sites' => 'boolean',
    ];

    public function payoutProfileLocked(): bool
    {
        return $this->payout_profile_locked_at !== null;
    }

    /**
     * Snapshot of locked payout destinations for withdrawal forms.
     *
     * @return array<string, mixed>
     */
    public function payoutProfile(): array
    {
        return [
            'business_name' => $this->payout_business_name,
            'paypal_email' => $this->payout_paypal_email,
            'wise_email' => $this->payout_wise_email,
            'bank_holder_name' => $this->payout_bank_holder_name,
            'bank_name' => $this->payout_bank_name,
            'bank_account' => $this->payout_bank_account,
            'bank_swift' => $this->payout_bank_swift,
            'crypto_wallet' => $this->payout_crypto_trx_wallet,
            'crypto_trx_wallet' => $this->payout_crypto_trx_wallet,
            'crypto_type' => $this->payout_crypto_type,
            'crypto_trx_verified' => $this->payout_crypto_trx_verified_at !== null,
            'preferred_method' => $this->payout_preferred_method,
            'locked' => $this->payoutProfileLocked(),
            'locked_at' => optional($this->payout_profile_locked_at)?->toIso8601String(),
        ];
    }

    /**
     * Override: Google users are automatically verified
     */
    public function hasVerifiedEmail()
    {
        if ($this->google_id) {
            return true;
        }

        return ! is_null($this->email_verified_at);
    }

    /**
     * Send the email verification notification (branded, sync — not queued).
     */
    public function sendEmailVerificationNotification(): void
    {
        if ($this->hasVerifiedEmail()) {
            return;
        }

        try {
            $this->notify(new VerifyEmail);
            Log::info('Email verification notification sent', [
                'user_id' => $this->id,
                'email' => $this->email,
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to send email verification notification', [
                'user_id' => $this->id,
                'email' => $this->email,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /** ------------------ Roles ------------------ */
    public function roles()
    {
        return $this->belongsToMany(Role::class, 'role_user')->withTimestamps();
    }

    public function assignRole(string $role): void
    {
        $roleModel = Role::where('name', $role)->firstOrFail();
        $this->roles()->syncWithoutDetaching([$roleModel->id]);
    }

    public function hasRole(string $role): bool
    {
        return $this->roles()->where('name', $role)->exists();
    }

    public function activeRoleRelation()
    {
        return $this->belongsTo(Role::class, 'active_role_id');
    }

    public function activeRoleModel(): ?Role
    {
        return $this->activeRoleRelation()->first() ?? $this->roles()->first();
    }

    public function activeRole(): ?string
    {
        return $this->activeRoleModel()?->name;
    }

    public function isActiveRole(string $role): bool
    {
        return $this->activeRole() === $role;
    }

    public function isAdmin(): bool
    {
        return $this->isActiveRole('admin');
    }

    public function isMarketing(): bool
    {
        return $this->isActiveRole('marketing');
    }

    /**
     * Admin and marketing can activate/deactivate sites (shared Sites Management UI).
     */
    public function canActivateSites(): bool
    {
        return $this->isAdmin() || $this->isMarketing();
    }

    /**
     * Hostinger sometimes misses the can_activate_sites migration.
     * Best-effort ADD COLUMN so Marketing role grants do not 500 on save.
     */
    public static function ensureCanActivateSitesColumn(): bool
    {
        static $ensured = false;
        if ($ensured) {
            return Schema::hasColumn('users', 'can_activate_sites');
        }
        $ensured = true;

        try {
            if (! Schema::hasTable('users')) {
                return false;
            }
            if (Schema::hasColumn('users', 'can_activate_sites')) {
                return true;
            }

            $driver = Schema::getConnection()->getDriverName();
            if (! in_array($driver, ['mysql', 'mariadb'], true)) {
                Schema::table('users', function ($table) {
                    $table->boolean('can_activate_sites')->default(false);
                });

                return Schema::hasColumn('users', 'can_activate_sites');
            }

            DB::statement('ALTER TABLE `users` ADD COLUMN `can_activate_sites` TINYINT(1) NOT NULL DEFAULT 0 AFTER `active_role_id`');
        } catch (\Throwable $e) {
            Log::warning('Could not add users.can_activate_sites', [
                'error' => $e->getMessage(),
                'hint' => 'Run database/sql/add_users_can_activate_sites.sql in phpMyAdmin',
            ]);
        }

        return Schema::hasColumn('users', 'can_activate_sites');
    }

    public function hasCanActivateSitesColumn(): bool
    {
        return self::ensureCanActivateSitesColumn();
    }

    /** Staff roles that share the admin panel (with different permissions). */
    public function isStaff(): bool
    {
        return in_array($this->activeRole(), ['admin', 'marketing'], true);
    }

    public function sites()
    {
        return $this->hasMany(Site::class, 'publisher_id');
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    public function depositRequests()
    {
        return $this->hasMany(DepositRequest::class);
    }

    /** ------------------ Wallets ------------------ */
    public function wallets()
    {
        return $this->hasMany(Wallet::class);
    }

    public function activeWallet(): ?Wallet
    {
        return $this->wallets()->where('role_id', $this->active_role_id)->first();
    }

    /** ------------------ Other Relations ------------------ */
    public function consent()
    {
        return $this->hasOne(UserConsent::class);
    }

    /** ------------------ Helper ------------------ */
    public function getDashboardRoute(): string
    {
        // Relative paths so post-login redirects stay on the current host
        // even when APP_URL is misconfigured as localhost.
        return match ($this->activeRole()) {
            'admin' => route('admin.dashboard', absolute: false),
            'marketing' => route('marketing.dashboard', absolute: false),
            'advertiser' => route('advertiser.dashboard', absolute: false),
            'publisher' => route('publisher.dashboard', absolute: false),
            default => '/',
        };
    }
}
