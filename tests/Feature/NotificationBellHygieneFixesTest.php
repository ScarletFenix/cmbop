<?php

namespace Tests\Feature;

use App\Models\AdvertiserSpendBudget;
use App\Models\InAppNotification;
use App\Models\Role;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Advertiser\SpendBudgetService;
use App\Services\InAppNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class NotificationBellHygieneFixesTest extends TestCase
{
    use RefreshDatabase;

    private function advertiser(): User
    {
        $role = Role::firstOrCreate(['name' => 'advertiser']);
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    public function test_spend_budget_bell_uses_payments_category_and_relative_cta(): void
    {
        $user = $this->advertiser();
        Wallet::firstOrCreate(
            ['user_id' => $user->id, 'role_id' => Wallet::advertiserRoleId()],
            ['balance' => 5, 'reserved_balance' => 0, 'currency' => 'EUR']
        );

        AdvertiserSpendBudget::ensureTable();
        AdvertiserSpendBudget::create([
            'user_id' => $user->id,
            'monthly_limit' => 10,
            'warn_at_percent' => 50,
            'low_balance_threshold' => 100,
            'notify_email' => false,
            'notify_bell' => true,
        ]);

        // Force evaluate path by setting committed via a paid completed order is heavy;
        // call notify through evaluate after faking status thresholds via service upsert + evaluate.
        app(SpendBudgetService::class)->evaluate($user);

        // Low-balance should fire (spendable 5 < threshold 100).
        $bell = InAppNotification::query()
            ->where('user_id', $user->id)
            ->where('type', 'like', 'spend_budget_%')
            ->latest('id')
            ->first();

        $this->assertNotNull($bell);
        $this->assertSame(InAppNotificationService::CATEGORY_PAYMENTS, $bell->category);
        $this->assertSame(InAppNotification::AUDIENCE_ADVERTISER, $bell->audience);
        $this->assertStringStartsWith('/advertiser/', (string) $bell->action_url);
        $this->assertStringNotContainsString('http://', (string) $bell->action_url);
        $this->assertStringNotContainsString('https://', (string) $bell->action_url);
    }

    public function test_content_evaluation_bell_uses_relative_library_cta(): void
    {
        $user = $this->advertiser();
        $submission = new class
        {
            public int $id = 99;
        };

        app(InAppNotificationService::class)->notifyContentEvaluation($user, $submission, [
            'approved' => true,
            'message' => 'Looks good',
            'moderation_status' => 'approved',
        ]);

        $bell = InAppNotification::query()->where('user_id', $user->id)->latest('id')->first();
        $this->assertNotNull($bell);
        $this->assertSame('/advertiser/content-library', $bell->action_url);
    }

    public function test_notification_index_heals_missing_table(): void
    {
        Schema::dropIfExists('in_app_notifications');
        InAppNotification::forgetTableAvailabilityCache();
        $this->assertFalse(Schema::hasTable('in_app_notifications'));

        $user = $this->advertiser();

        $this->actingAs($user)
            ->getJson(route('notifications.index'))
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertTrue(Schema::hasTable('in_app_notifications'));
        $this->assertTrue(Schema::hasColumn('in_app_notifications', 'audience'));
    }

    public function test_payments_filter_includes_spend_budget_bells(): void
    {
        $user = $this->advertiser();

        InAppNotification::create([
            'user_id' => $user->id,
            'audience' => InAppNotification::AUDIENCE_ADVERTISER,
            'type' => 'spend_budget_warn',
            'category' => InAppNotificationService::CATEGORY_PAYMENTS,
            'title' => 'Spend budget',
            'message' => 'Near limit',
            'status' => InAppNotification::STATUS_UNREAD,
            'action_url' => '/advertiser/analytics',
        ]);

        $this->actingAs($user)
            ->getJson(route('notifications.index', ['category' => 'payments']))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonFragment(['type' => 'spend_budget_warn', 'category' => 'payments']);
    }
}
