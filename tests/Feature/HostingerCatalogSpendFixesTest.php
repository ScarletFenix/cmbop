<?php

namespace Tests\Feature;

use App\Models\AdvertiserSpendBudget;
use App\Models\Role;
use App\Models\User;
use App\Providers\AppServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use Tests\TestCase;

class HostingerCatalogSpendFixesTest extends TestCase
{
    use RefreshDatabase;

    public function test_site_description_excerpt_is_available_after_app_boot(): void
    {
        $this->assertTrue(function_exists('site_description_excerpt'));
        $this->assertSame(
            'Hello world',
            site_description_excerpt('<p>Hello <strong>world</strong></p>')
        );
    }

    public function test_appservice_provider_requires_site_description_helper(): void
    {
        $boot = file_get_contents((new ReflectionClass(AppServiceProvider::class))->getFileName());
        $this->assertStringContainsString("app_path('Helpers/SiteDescriptionHelper.php')", $boot);
    }

    public function test_spending_page_heals_missing_budget_table(): void
    {
        Schema::dropIfExists('advertiser_spend_budgets');
        AdvertiserSpendBudget::forgetTableAvailabilityCache();
        $this->assertFalse(Schema::hasTable('advertiser_spend_budgets'));

        $role = Role::firstOrCreate(['name' => 'advertiser']);
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        $this->actingAs($user)
            ->get(route('advertiser.analytics'))
            ->assertOk();

        $this->assertTrue(Schema::hasTable('advertiser_spend_budgets'));
    }

    public function test_ensure_table_recovers_when_table_appears_after_false_cache(): void
    {
        Schema::dropIfExists('advertiser_spend_budgets');
        AdvertiserSpendBudget::forgetTableAvailabilityCache();
        $this->assertFalse(AdvertiserSpendBudget::tableAvailable());

        // Simulate another worker creating the table while this process cached false.
        Schema::create('advertiser_spend_budgets', function ($table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->decimal('monthly_limit', 12, 2)->nullable();
            $table->unsignedTinyInteger('warn_at_percent')->default(80);
            $table->decimal('low_balance_threshold', 12, 2)->nullable();
            $table->boolean('notify_email')->default(true);
            $table->boolean('notify_bell')->default(true);
            $table->string('last_warn_period', 7)->nullable();
            $table->string('last_hit_period', 7)->nullable();
            $table->date('last_low_balance_on')->nullable();
            $table->timestamps();
        });

        AdvertiserSpendBudget::ensureTable();
        $this->assertTrue(AdvertiserSpendBudget::tableAvailable());
    }
}
