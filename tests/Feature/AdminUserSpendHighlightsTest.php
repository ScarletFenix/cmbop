<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminUserSpendHighlightsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
    }

    private function userWithRoles(array $roleNames, ?string $active = null): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $ids = [];
        foreach ($roleNames as $name) {
            $ids[$name] = Role::where('name', $name)->value('id');
            $user->roles()->attach($ids[$name]);
        }
        $activeName = $active ?? $roleNames[0];
        $user->active_role_id = $ids[$activeName];
        $user->save();

        return $user->fresh(['roles']);
    }

    private function paidOrder(User $user, float $amount, string $suffix): Order
    {
        return Order::create([
            'user_id' => $user->id,
            'order_number' => 'ORD-HL-'.$suffix,
            'subtotal' => $amount,
            'tax' => 0,
            'total_amount' => $amount,
            'payment_method' => 'card',
            'payment_status' => 'paid',
            'status' => 'completed',
            'paid_at' => now(),
        ]);
    }

    private function userRowHtml(string $pageHtml, int $userId): string
    {
        $pattern = '/<tr[^>]*id="user-'.$userId.'"[^>]*>[\s\S]*?<\/tr>/';
        $this->assertMatchesRegularExpression($pattern, $pageHtml);

        preg_match($pattern, $pageHtml, $matches);

        return $matches[0];
    }

    public function test_users_list_shows_repeat_and_high_spender_badges(): void
    {
        $admin = $this->userWithRoles(['admin'], 'admin');

        $both = $this->userWithRoles(['advertiser'], 'advertiser');
        $both->forceFill(['name' => 'Both Badges User', 'email' => 'both-badges@example.com'])->save();
        $this->paidOrder($both, 600, 'both-1');
        $this->paidOrder($both, 500, 'both-2');

        $repeatOnly = $this->userWithRoles(['advertiser'], 'advertiser');
        $repeatOnly->forceFill(['name' => 'Repeat Only User', 'email' => 'repeat-only@example.com'])->save();
        $this->paidOrder($repeatOnly, 100, 'rep-1');
        $this->paidOrder($repeatOnly, 150, 'rep-2');

        $spenderOnly = $this->userWithRoles(['advertiser'], 'advertiser');
        $spenderOnly->forceFill(['name' => 'Spender Only User', 'email' => 'spender-only@example.com'])->save();
        $this->paidOrder($spenderOnly, 1500, 'spend-1');

        $plain = $this->userWithRoles(['advertiser'], 'advertiser');
        $plain->forceFill(['name' => 'Plain Buyer User', 'email' => 'plain-buyer@example.com'])->save();
        $this->paidOrder($plain, 50, 'plain-1');

        $html = $this->actingAs($admin)
            ->get(route('admin.users.index'))
            ->assertOk()
            ->getContent();

        $bothRow = $this->userRowHtml($html, $both->id);
        $this->assertStringContainsString('user-highlight-row', $bothRow);
        $this->assertStringContainsString('user-value-badge--repeat', $bothRow);
        $this->assertStringContainsString('user-value-badge--spender', $bothRow);
        $this->assertStringContainsString('title="2 paid orders"', $bothRow);
        $this->assertStringContainsString('title="Paid GMV €1,100.00"', $bothRow);

        $repeatRow = $this->userRowHtml($html, $repeatOnly->id);
        $this->assertStringContainsString('user-highlight-row', $repeatRow);
        $this->assertStringContainsString('user-value-badge--repeat', $repeatRow);
        $this->assertStringNotContainsString('user-value-badge--spender', $repeatRow);

        $spenderRow = $this->userRowHtml($html, $spenderOnly->id);
        $this->assertStringContainsString('user-highlight-row', $spenderRow);
        $this->assertStringContainsString('user-value-badge--spender', $spenderRow);
        $this->assertStringNotContainsString('user-value-badge--repeat', $spenderRow);

        $plainRow = $this->userRowHtml($html, $plain->id);
        $this->assertStringNotContainsString('user-highlight-row', $plainRow);
        $this->assertStringNotContainsString('user-value-badge--repeat', $plainRow);
        $this->assertStringNotContainsString('user-value-badge--spender', $plainRow);
    }

    public function test_finance_dossier_shows_same_highlight_badges(): void
    {
        $admin = $this->userWithRoles(['admin'], 'admin');
        $user = $this->userWithRoles(['advertiser'], 'advertiser');
        $user->forceFill(['name' => 'Dossier Highlight User'])->save();
        $this->paidOrder($user, 700, 'dos-1');
        $this->paidOrder($user, 400, 'dos-2');

        $this->actingAs($admin)
            ->get(route('admin.finance.user', $user))
            ->assertOk()
            ->assertSee('Repeat', false)
            ->assertSee('€1k+', false)
            ->assertSee('2 paid orders', false)
            ->assertSee('Paid GMV €1,100.00', false);
    }
}
