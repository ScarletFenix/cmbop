<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublisherBalanceHistoryUiTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_transfer_history_renders_valid_table_row_markup(): void
    {
        $role = Role::firstOrCreate(['name' => 'publisher']);
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        $html = $this->actingAs($user)
            ->get(route('publisher.balance'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('function renderTransferHistory', $html);
        $this->assertStringContainsString("<\/tr>", $html);
        $this->assertStringNotContainsString("</table>\\", $html);
        $this->assertStringNotContainsString("No transfers found</p>\\\n                <\/td>\\\n            </table>", $html);
    }

    public function test_favicon_partial_points_at_existing_public_assets(): void
    {
        $partial = file_get_contents(resource_path('views/components/favicon.blade.php'));
        $this->assertStringContainsString('assets/brand/web/favicon.svg', $partial);
        $this->assertStringContainsString('assets/img/favicon-32.png', $partial);
        $this->assertStringContainsString('assets/img/apple-touch-icon.png', $partial);
        $this->assertFileExists(public_path('assets/brand/web/favicon.svg'));
        $this->assertFileExists(public_path('assets/img/favicon-32.png'));
        $this->assertFileExists(public_path('assets/img/apple-touch-icon.png'));
        $this->assertFileExists(public_path('favicon.svg'));
        $this->assertFileExists(public_path('favicon.ico'));
        $this->assertFileExists(public_path('apple-touch-icon.png'));
    }
}
