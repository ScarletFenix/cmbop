<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Support\ProductionReadiness;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ProductionReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_local_environment_passes_with_sqlite_and_empty_media_path(): void
    {
        $this->seed(RolesTableSeeder::class);

        $this->assertSame(0, Artisan::call('ops:production-ready'));
        $output = Artisan::output();
        $this->assertStringContainsString('Production readiness: OK', $output);
        $this->assertStringContainsString('sqlite', strtolower($output));
    }

    public function test_production_fails_on_sqlite_and_empty_media_path(): void
    {
        $this->seed(RolesTableSeeder::class);
        $this->forceProduction();

        $readiness = app(ProductionReadiness::class);
        $ids = array_column($readiness->failures(), 'id');

        $this->assertContains('database', $ids);
        $this->assertContains('media_path', $ids);
        $this->assertFalse($readiness->isHealthy());
        $this->assertSame(1, Artisan::call('ops:production-ready'));
        $this->assertStringContainsString('Database is not MySQL', Artisan::output());
    }

    public function test_repair_seeds_missing_roles(): void
    {
        Role::query()->whereIn('name', ['advertiser', 'publisher', 'admin', 'marketing'])->delete();
        $this->assertFalse(Role::query()->where('name', 'advertiser')->exists());

        $this->assertSame(0, Artisan::call('ops:production-ready', ['--repair' => true]));

        foreach (['advertiser', 'publisher', 'admin', 'marketing'] as $name) {
            $this->assertTrue(Role::query()->where('name', $name)->exists(), $name.' should exist');
        }
    }

    public function test_dashboard_stays_quiet_outside_production(): void
    {
        $this->seed(RolesTableSeeder::class);
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertDontSee('Production is misconfigured');
    }

    public function test_dashboard_names_production_faults(): void
    {
        $this->seed(RolesTableSeeder::class);
        $this->forceProduction();
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Production is misconfigured')
            ->assertSee('Database is not MySQL')
            ->assertSee('MEDIA_PATH is empty')
            ->assertSee('ops:production-ready');
    }

    public function test_env_example_and_docs_pin_the_production_checklist(): void
    {
        $example = (string) file_get_contents(base_path('.env.example'));
        $this->assertStringContainsString('DB_CONNECTION=mysql', $example);
        $this->assertStringContainsString('MAIL_QUEUE_AUTO_DRAIN=true', $example);
        $this->assertStringContainsString('MEDIA_PATH=', $example);
        $this->assertStringContainsString('HOSTINGER_WEB_HEAL=true', $example);

        $agents = (string) file_get_contents(base_path('AGENTS.md'));
        $this->assertStringContainsString('ops:production-ready', $agents);
        $this->assertStringContainsString('RolesTableSeeder', $agents);
        $this->assertStringNotContainsString('roles are NOT in DatabaseSeeder', $agents);
    }

    private function forceProduction(): void
    {
        app()['env'] = 'production';
        config(['app.env' => 'production']);
    }

    private function makeAdmin(): User
    {
        $role = Role::where('name', 'admin')->firstOrFail();
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        return $user;
    }
}
