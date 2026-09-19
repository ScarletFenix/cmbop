<?php

namespace Tests\Feature;

use App\Models\OnDemandContact;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminOnDemandContactsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $marketer;

    protected function setUp(): void
    {
        parent::setUp();

        $adminRole = Role::firstOrCreate(['name' => 'admin']);
        $this->admin = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $adminRole->id,
        ]);
        $this->admin->roles()->attach($adminRole->id);

        $marketingRole = Role::firstOrCreate(['name' => 'marketing']);
        $this->marketer = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $marketingRole->id,
        ]);
        $this->marketer->roles()->attach($marketingRole->id);
    }

    public function test_admin_sites_index_shows_on_demand_next_to_add_site(): void
    {
        $html = $this->actingAs($this->admin)
            ->get(route('admin.sites.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Add site for publisher', $html);
        $this->assertStringContainsString('On-demand', $html);
        $this->assertStringContainsString(route('admin.sites.on-demand.index', [], false), $html);
    }

    public function test_marketing_sites_index_does_not_show_on_demand(): void
    {
        $html = $this->actingAs($this->marketer)
            ->get(route('marketing.sites.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Add site for publisher', $html);
        $this->assertStringNotContainsString(route('admin.sites.on-demand.index', [], false), $html);
    }

    public function test_marketing_cannot_open_or_write_on_demand(): void
    {
        $this->actingAs($this->marketer)
            ->get(route('admin.sites.on-demand.index'))
            ->assertRedirect();

        $this->actingAs($this->marketer)
            ->post(route('admin.sites.on-demand.store'), $this->payload())
            ->assertRedirect();

        $this->assertSame(0, OnDemandContact::query()->count());
    }

    public function test_admin_can_create_update_and_delete_on_demand_contact(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.sites.on-demand.store'), $this->payload())
            ->assertRedirect(route('admin.sites.on-demand.index'))
            ->assertSessionHas('success', 'On-demand contact saved.');

        $contact = OnDemandContact::query()->first();
        $this->assertNotNull($contact);
        $this->assertSame(['ondemand.example'], $contact->site_url);
        $this->assertSame(['owner@example.com', 'press@example.com', 'ads@example.com'], $contact->email);
        $this->assertSame(['+491234', '+49999'], $contact->whatsapp);
        $this->assertSame(['inbox@seolinkbuildings.com'], $contact->contacted_via_email);
        $this->assertSame('Pay via Wise', $contact->notes);
        $this->assertSame((int) $this->admin->id, (int) $contact->created_by);

        $html = $this->actingAs($this->admin)
            ->get(route('admin.sites.on-demand.index'))
            ->assertOk()
            ->assertSee('ondemand.example', false)
            ->assertSee('owner@example.com', false)
            ->assertSee('press@example.com', false)
            ->assertSee('+more', false)
            ->assertSee('fa-edit', false)
            ->assertSee('fa-trash', false)
            ->assertSee('adminOnDemandSearch', false)
            ->getContent();

        $this->assertStringNotContainsString('>Edit<', $html);
        $this->assertStringNotContainsString('>Delete<', $html);

        $this->actingAs($this->admin)
            ->get(route('admin.sites.on-demand.index', ['q' => 'ondemand.example']))
            ->assertOk()
            ->assertSee('ondemand.example', false);

        $this->actingAs($this->admin)
            ->get(route('admin.sites.on-demand.index', ['q' => 'nomatch-xyz']))
            ->assertOk()
            ->assertSee('No contacts match that search.', false)
            ->assertDontSee('ondemand.example', false);

        $this->actingAs($this->admin)
            ->put(route('admin.sites.on-demand.update', $contact), $this->payload([
                'whatsapp' => ['+49888'],
                'notes' => 'Updated',
            ]))
            ->assertRedirect(route('admin.sites.on-demand.index'));

        $this->assertSame(['+49888'], $contact->fresh()->whatsapp);
        $this->assertSame('Updated', $contact->fresh()->notes);

        $this->actingAs($this->admin)
            ->delete(route('admin.sites.on-demand.destroy', $contact))
            ->assertRedirect(route('admin.sites.on-demand.index'));

        $this->assertSame(0, OnDemandContact::query()->count());
    }

    public function test_site_name_must_be_unique_without_scheme(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.sites.on-demand.store'), $this->payload())
            ->assertRedirect(route('admin.sites.on-demand.index'));

        $this->actingAs($this->admin)
            ->from(route('admin.sites.on-demand.index'))
            ->post(route('admin.sites.on-demand.store'), $this->payload([
                'site_url' => ['https://www.ondemand.example/path'],
                'email' => ['other@example.com'],
            ]))
            ->assertRedirect(route('admin.sites.on-demand.index'))
            ->assertSessionHasErrors('site_url');

        $this->assertSame(1, OnDemandContact::query()->count());
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'site_url' => ['https://ondemand.example'],
            'email' => ['owner@example.com', 'press@example.com', 'ads@example.com'],
            'whatsapp_code' => ['49', '49'],
            'whatsapp' => ['1234', '999'],
            'contacted_via_email' => ['inbox@seolinkbuildings.com'],
            'notes' => 'Pay via Wise',
        ], $overrides);
    }
}
