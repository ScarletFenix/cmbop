<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SaasBestPracticesTest extends TestCase
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

    private function publisher(): User
    {
        $role = Role::firstOrCreate(['name' => 'publisher']);
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    public function test_advertiser_account_menu_exposes_billing_and_preferences(): void
    {
        $advertiser = $this->advertiser();

        $this->actingAs($advertiser)
            ->get(route('advertiser.dashboard'))
            ->assertOk()
            ->assertSee(route('profile.notifications'), false)
            ->assertSee('Email preferences', false)
            ->assertSee(route('advertiser.billing.index'), false)
            ->assertSee('Billing &amp; invoices', false)
            ->assertSee(route('advertiser.add-funds'), false)
            ->assertSee('Add funds', false)
            ->assertSee('showAppToast', false);
    }

    public function test_publisher_account_menu_exposes_balance_and_withdraw(): void
    {
        $publisher = $this->publisher();

        $this->actingAs($publisher)
            ->get(route('publisher.dashboard'))
            ->assertOk()
            ->assertSee(route('profile.notifications'), false)
            ->assertSee(route('publisher.balance'), false)
            ->assertSee('Balance', false)
            ->assertSee(route('publisher.withdraw'), false)
            ->assertSee('Withdraw', false)
            ->assertSee('showAppToast', false);
    }

    public function test_content_library_empty_uses_shared_empty_state(): void
    {
        $advertiser = $this->advertiser();

        $this->actingAs($advertiser)
            ->get(route('advertiser.content-library'))
            ->assertOk()
            ->assertSee('No articles yet', false)
            ->assertSee('ui-empty-state', false)
            ->assertSee('Upload article', false);
    }

    public function test_advertiser_orders_page_has_filter_fields_for_url_sync(): void
    {
        $advertiser = $this->advertiser();

        $html = $this->actingAs($advertiser)
            ->get(route('advertiser.orders'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('id="searchInput"', $html);
        $this->assertStringContainsString('id="statusFilter"', $html);
        $this->assertStringContainsString('assets/js/advertiser-orders.js', $html);

        $ordersJs = (string) file_get_contents(public_path('assets/js/advertiser-orders.js'));
        $this->assertStringContainsString('syncOrdersFiltersToUrl', $ordersJs);
        $this->assertStringContainsString('retryOrdersBtn', $ordersJs);
    }

    public function test_catalog_toast_helper_does_not_alert(): void
    {
        $advertiser = $this->advertiser();

        $html = $this->actingAs($advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('alert(message)', $html);
        $this->assertStringContainsString('showAppToast', $html);
        $this->assertStringContainsString('assets/js/catalog.js', $html);

        // Undo lives in the external catalog script (extracted from the blade).
        $catalogJs = file_get_contents(public_path('assets/js/catalog.js'));
        $this->assertStringContainsString("actionLabel: 'Undo'", $catalogJs);
        $this->assertStringContainsString('function catalogToast', $catalogJs);
    }
}
