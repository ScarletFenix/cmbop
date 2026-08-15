<?php

namespace Tests\Feature;

use App\Models\DepositRequest;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AdminDepositsCrashHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function makeUser(string $roleName): User
    {
        $role = Role::firstOrCreate(['name' => $roleName]);
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    private function depositFor(User $advertiser, array $overrides = []): DepositRequest
    {
        return DepositRequest::create(array_merge([
            'user_id' => $advertiser->id,
            'reference_code' => 'DEP-'.uniqid(),
            'amount' => 40,
            'payment_method' => 'bank',
            'status' => 'pending',
        ], $overrides));
    }

    public function test_index_survives_a_missing_user(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');
        $deposit = $this->depositFor($advertiser);

        Schema::disableForeignKeyConstraints();
        DepositRequest::query()->whereKey($deposit->id)->update(['user_id' => 999999]);
        Schema::enableForeignKeyConstraints();

        $this->actingAs($admin)
            ->get(route('admin.deposits'))
            ->assertOk()
            ->assertSee('Unknown', false)
            ->assertDontSee('htmlspecialchars', false);
    }

    public function test_show_json_survives_a_missing_user(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');
        $deposit = $this->depositFor($advertiser);

        Schema::disableForeignKeyConstraints();
        DepositRequest::query()->whereKey($deposit->id)->update(['user_id' => 999999]);
        Schema::enableForeignKeyConstraints();

        $this->actingAs($admin)
            ->getJson(route('admin.deposits.show', $deposit->id))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('deposit.user', null);
    }

    public function test_array_admin_notes_do_not_approve_or_reject(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');
        $approve = $this->depositFor($advertiser, ['reference_code' => 'DEP-NOTE-A']);
        $reject = $this->depositFor($advertiser, ['reference_code' => 'DEP-NOTE-R']);

        $this->actingAs($admin)
            ->postJson(route('admin.deposits.approve', $approve->id), [
                'admin_notes' => ['injected'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('admin_notes');

        $this->actingAs($admin)
            ->postJson(route('admin.deposits.reject', $reject->id), [
                'admin_notes' => ['injected'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('admin_notes');

        $this->assertSame('pending', $approve->fresh()->status);
        $this->assertSame('pending', $reject->fresh()->status);
    }

    public function test_oversized_admin_notes_are_rejected(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');
        $deposit = $this->depositFor($advertiser);

        $this->actingAs($admin)
            ->postJson(route('admin.deposits.reject', $deposit->id), [
                'admin_notes' => str_repeat('x', 1001),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('admin_notes');

        $this->assertSame('pending', $deposit->fresh()->status);
    }

    public function test_index_uses_named_deposit_action_routes(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');
        $deposit = $this->depositFor($advertiser);

        $html = $this->actingAs($admin)
            ->get(route('admin.deposits'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(route('admin.deposits.show', $deposit->id), $html);
        $this->assertStringContainsString('data-show-url', $html);
        $this->assertStringContainsString('readJsonResponse', $html);
        $this->assertStringNotContainsString("fetch('/admin/deposits/'", $html);
        $this->assertStringNotContainsString('fetch(`/admin/deposits/${id}/approve`', $html);
        $this->assertStringNotContainsString('fetch(`/admin/deposits/${id}/reject`', $html);
    }
}
