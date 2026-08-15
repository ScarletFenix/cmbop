<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\ActivityLogger;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class AdminActivityLogTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);

        $role = Role::where('name', 'admin')->firstOrFail();
        $this->admin = User::factory()->create([
            'name' => 'Ada Admin',
            'email' => 'ada@example.com',
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $this->admin->roles()->attach($role->id);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_admin_can_open_activity_history(): void
    {
        $html = $this->actingAs($this->admin)
            ->get(route('admin.activity-logs.index'))
            ->assertOk()
            ->assertSee('Activity History', false)
            ->assertDontSee('Every dashboard action', false)
            ->assertSee('Append-only log of actions recorded by ActivityLogger', false)
            ->getContent();

        foreach (['logUser', 'logAction', 'logFrom', 'logTo'] as $id) {
            $this->assertStringContainsString('for="'.$id.'"', $html);
            $this->assertStringContainsString('id="'.$id.'"', $html);
        }
    }

    public function test_user_filter_is_literal_and_escapes_like_wildcards(): void
    {
        $this->makeLog(['user_name' => 'Alice 100%', 'user_email' => 'alice-percent@example.com', 'description' => 'Percent user']);
        $this->makeLog(['user_name' => 'Alice 1000', 'user_email' => 'alice-thousand@example.com', 'description' => 'Thousand user']);

        $this->actingAs($this->admin)
            ->get(route('admin.activity-logs.index', ['user' => '100%']))
            ->assertOk()
            ->assertSee('Percent user', false)
            ->assertDontSee('Thousand user', false);
    }

    public function test_invalid_and_inverted_dates_are_ignored_with_a_warning(): void
    {
        $this->makeLog(['description' => 'Kept when dates are bad']);

        $this->actingAs($this->admin)
            ->get(route('admin.activity-logs.index', [
                'user' => ['injected'],
                'action' => ['login'],
                'from' => ['2026-01-01'],
                'to' => ['2026-12-31'],
            ]))
            ->assertOk()
            ->assertSee('Use a valid From date.', false);

        $this->actingAs($this->admin)
            ->get(route('admin.activity-logs.index', ['from' => 'not-a-date', 'to' => 'also-bad']))
            ->assertOk()
            ->assertSee('Use a valid From date.', false)
            ->assertSee('Use a valid To date.', false)
            ->assertSee('Kept when dates are bad', false);

        $this->actingAs($this->admin)
            ->get(route('admin.activity-logs.index', ['from' => '2026-08-16', 'to' => '2026-08-15']))
            ->assertOk()
            ->assertSee('From date must be on or before To date.', false)
            ->assertSee('Kept when dates are bad', false);
    }

    public function test_date_filter_uses_app_timezone_window(): void
    {
        config(['app.timezone' => 'Europe/Berlin']);
        Carbon::setTestNow(Carbon::parse('2026-08-15 00:30:00', 'Europe/Berlin'));

        $today = $this->makeLog(['description' => 'Today in Berlin', 'action' => 'site.activated']);
        $today->forceFill([
            'created_at' => Carbon::parse('2026-08-14 22:30:00', 'UTC'),
            'updated_at' => Carbon::parse('2026-08-14 22:30:00', 'UTC'),
        ])->save();

        $yesterday = $this->makeLog(['description' => 'Yesterday in Berlin', 'action' => 'site.updated']);
        $yesterday->forceFill([
            'created_at' => Carbon::parse('2026-08-14 10:00:00', 'UTC'),
            'updated_at' => Carbon::parse('2026-08-14 10:00:00', 'UTC'),
        ])->save();

        $this->actingAs($this->admin)
            ->get(route('admin.activity-logs.index', ['from' => '2026-08-15', 'to' => '2026-08-15']))
            ->assertOk()
            ->assertSee('Today in Berlin', false)
            ->assertDontSee('Yesterday in Berlin', false);
    }

    public function test_empty_states_distinguish_filters_from_first_run(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.activity-logs.index'))
            ->assertOk()
            ->assertSee('No activity recorded yet.', false)
            ->assertDontSee('No events match these filters.', false);

        $this->makeLog(['description' => 'Only an edit', 'action' => 'site.updated']);

        $this->actingAs($this->admin)
            ->get(route('admin.activity-logs.index', ['action' => 'site.activated']))
            ->assertOk()
            ->assertSee('No events match these filters.', false)
            ->assertSee('Reset filters', false)
            ->assertDontSee('No activity recorded yet.', false)
            ->assertDontSee('Only an edit', false);
    }

    public function test_details_show_reason_changes_and_subject_link(): void
    {
        $publisher = User::factory()->create(['email_verified_at' => now()]);
        $site = Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Audit Link Site',
            'site_url' => 'https://audit-link.example',
            'domain' => 'audit-link.example',
            'da' => 20,
            'dr' => 20,
            'traffic' => 1000,
            'country' => 'us',
            'language' => 'en',
            'category' => 'News',
            'price' => 40,
            'publication_time' => 'permanent',
            'description' => 'Audit site',
            'link_type' => 'dofollow',
            'verified' => false,
            'active' => false,
        ]);

        $this->makeLog([
            'action' => 'site.updated',
            'description' => 'Staff modified site',
            'subject_type' => Site::class,
            'subject_id' => $site->id,
            'subject_label' => 'Audit Link Site',
            'properties' => [
                'reason' => 'Price correction',
                'changes' => ['price' => ['from' => 40, 'to' => 55]],
            ],
        ]);

        $this->makeLog([
            'action' => 'site.deleted',
            'description' => 'Staff deleted a gone site',
            'subject_type' => Site::class,
            'subject_id' => 999999,
            'subject_label' => 'Gone Site',
        ]);

        $html = $this->actingAs($this->admin)
            ->get(route('admin.activity-logs.index'))
            ->assertOk()
            ->assertSee('Edited site', false)
            ->assertSee('Reason: Price correction', false)
            ->assertSee('Changed: Price', false)
            ->assertSee('Gone Site', false)
            ->assertSee('Removed', false)
            ->getContent();

        $this->assertStringContainsString(route('admin.sites.edit', $site->id), $html);
        $this->assertStringNotContainsString(route('admin.sites.edit', 999999), $html);
    }

    public function test_role_filter_and_past_page_redirect(): void
    {
        $this->makeLog(['role' => 'admin', 'description' => 'Admin row']);
        $this->makeLog(['role' => 'marketing', 'description' => 'Marketer row']);

        $this->actingAs($this->admin)
            ->get(route('admin.activity-logs.index', ['role' => 'marketing']))
            ->assertOk()
            ->assertSee('Marketer row', false)
            ->assertDontSee('Admin row', false);

        for ($i = 0; $i < 26; $i++) {
            $this->makeLog(['description' => 'Page filler '.$i]);
        }

        $this->actingAs($this->admin)
            ->get(route('admin.activity-logs.index', ['page' => 99]))
            ->assertRedirect();
    }

    public function test_export_streams_csv_for_admin_and_blocks_marketers(): void
    {
        $this->makeLog([
            'action' => 'site.approved',
            'description' => 'Approved for export',
            'subject_label' => 'Export Site',
        ]);

        $csv = $this->actingAs($this->admin)
            ->get(route('admin.activity-logs.export'))
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8')
            ->streamedContent();

        $this->assertStringContainsString('when,user_name,user_email,role,action,subject,description,properties,ip_address', $csv);
        $this->assertStringContainsString('site.approved', $csv);
        $this->assertStringContainsString('Approved for export', $csv);

        $marketerRole = Role::where('name', 'marketing')->firstOrFail();
        $marketer = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $marketerRole->id,
        ]);
        $marketer->roles()->attach($marketerRole->id);

        $this->actingAs($marketer)
            ->get(route('admin.activity-logs.export'))
            ->assertRedirect(route('marketing.dashboard'));
    }

    public function test_company_update_is_logged(): void
    {
        $user = User::factory()->create([
            'company_name' => 'Old Co',
            'email_verified_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->postJson(route('admin.users.updateCompany', $user->id), [
                'company_name' => 'New Co',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'user.company_updated',
            'user_id' => $this->admin->id,
            'subject_id' => $user->id,
        ]);
    }

    public function test_logger_accepts_explicit_actor_and_try_log_swallows_failures(): void
    {
        Auth::logout();
        $actor = $this->admin;

        $log = ActivityLogger::log(
            'site.approved',
            'Job approved a site',
            null,
            ['job' => true],
            'Queued Site',
            $actor
        );

        $this->assertSame($actor->id, $log->user_id);
        $this->assertSame('admin', $log->role);

        Log::shouldReceive('warning')->once()->withArgs(function (string $message, array $context) {
            return $message === 'Activity log failed' && ($context['action'] ?? null) === 'broken.action';
        });

        ActivityLog::creating(function () {
            throw new \RuntimeException('table missing');
        });

        $this->assertNull(ActivityLogger::tryLog('broken.action', 'Should not throw'));
        ActivityLog::flushEventListeners();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeLog(array $overrides = []): ActivityLog
    {
        return ActivityLog::create(array_merge([
            'user_id' => $this->admin->id,
            'user_name' => $this->admin->name,
            'user_email' => $this->admin->email,
            'role' => 'admin',
            'action' => 'site.updated',
            'description' => 'Staff edited a site',
            'subject_label' => 'Example Site',
        ], $overrides));
    }
}
