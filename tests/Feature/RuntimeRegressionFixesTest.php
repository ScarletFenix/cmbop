<?php

namespace Tests\Feature;

use App\Models\InAppNotification;
use App\Models\Role;
use App\Models\User;
use App\Services\InAppNotificationService;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RuntimeRegressionFixesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
    }

    private function makeUser(string $roleName): User
    {
        $role = Role::where('name', $roleName)->firstOrFail();
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        return $user;
    }

    public function test_publisher_tasks_and_websites_pages_render_with_intact_scripts(): void
    {
        $publisher = $this->makeUser('publisher');

        $tasks = $this->actingAs($publisher)->get(route('publisher.tasks'));
        $tasks->assertOk();
        $tasksHtml = $tasks->getContent();
        $this->assertStringContainsString('function escapeHtml', $tasksHtml);
        $this->assertMatchesRegularExpression('/escapeHtml\(str\)[\s\S]*?\n\}\);\n<\/script>/', $tasksHtml);

        $sites = $this->actingAs($publisher)->get(route('publisher.websites'));
        $sites->assertOk();
        $html = $sites->getContent();
        $this->assertStringContainsString('publisher-websites.js', $html);
        $this->assertStringContainsString('PublisherWebsitesConfig', $html);
        $this->assertTrue(
            str_contains($html, 'websites/ajax') || str_contains($html, 'websites\/ajax'),
            'Config should include the sites ajax route'
        );
        $this->assertStringContainsString(
            'function fetchSites',
            file_get_contents(public_path('assets/js/publisher-websites.js'))
        );
    }

    public function test_marketer_receives_and_reads_admin_bells(): void
    {
        $admin = $this->makeUser('admin');
        $marketer = $this->makeUser('marketing');

        app(InAppNotificationService::class)->notifyAdmins(
            InAppNotificationService::TYPE_SYSTEM,
            'Bulk sites ready for review',
            'Test digest for marketing fan-out.',
            [
                'category' => InAppNotificationService::CATEGORY_SYSTEM,
                'action_url' => '/admin/sites',
            ]
        );

        $this->assertDatabaseHas('in_app_notifications', [
            'user_id' => $admin->id,
            'audience' => InAppNotification::AUDIENCE_ADMIN,
            'title' => 'Bulk sites ready for review',
        ]);
        $this->assertDatabaseHas('in_app_notifications', [
            'user_id' => $marketer->id,
            'audience' => InAppNotification::AUDIENCE_ADMIN,
            'title' => 'Bulk sites ready for review',
        ]);

        $this->actingAs($marketer)
            ->getJson(route('notifications.index'))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('unread_count', 1);
    }

    public function test_marketer_cannot_load_admin_money_dashboard_queues(): void
    {
        $marketer = $this->makeUser('marketing');

        $this->actingAs($marketer)
            ->getJson(route('admin.dashboard.action-queue'))
            ->assertForbidden();

        $this->actingAs($marketer)
            ->getJson(route('admin.dashboard.queue-counts'))
            ->assertForbidden();
    }

    public function test_admin_withdrawals_page_keeps_pagination_helper(): void
    {
        $admin = $this->makeUser('admin');
        $html = $this->actingAs($admin)->get(route('admin.withdrawals'))->assertOk()->getContent();
        $this->assertStringContainsString('renderAdminPagination(', $html);
        $this->assertStringContainsString('admin-pagination.js', $html);
        $this->assertStringContainsString('function escapeHtml', $html);
    }
}
