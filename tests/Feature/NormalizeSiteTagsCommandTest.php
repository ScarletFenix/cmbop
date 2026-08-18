<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Support\SiteTag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class NormalizeSiteTagsCommandTest extends TestCase
{
    use RefreshDatabase;

    private function publisher(): User
    {
        $role = Role::query()->firstOrCreate(
            ['name' => 'publisher'],
            ['guard_name' => 'web']
        );
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->sync([$role->id]);

        return $user;
    }

    private function site(User $publisher, array $extra = []): Site
    {
        return Site::create(array_merge([
            'publisher_id' => $publisher->id,
            'site_name' => 'Normalize Tag Site',
            'site_url' => 'https://normalize-tag-'.uniqid().'.test',
            'domain' => 'normalize-tag-'.uniqid().'.test',
            'da' => 40,
            'dr' => 45,
            'traffic' => 10000,
            'country' => 'de',
            'countries' => ['de'],
            'language' => 'de',
            'languages' => ['de'],
            'category' => 'marketing',
            'price' => 100,
            'turnaround_time' => '3days',
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => 'Normalize tags fixture.',
            'verified' => true,
            'active' => true,
            'sponsored' => false,
            'partner_material' => false,
            'as_you_prefer' => false,
        ], $extra));
    }

    public function test_normalizes_conflicting_flags_by_priority(): void
    {
        $publisher = $this->publisher();
        $sponsoredWins = $this->site($publisher, [
            'domain' => 'sponsored-wins.test',
            'sponsored' => true,
            'partner_material' => true,
            'as_you_prefer' => true,
        ]);
        $partnerWins = $this->site($publisher, [
            'domain' => 'partner-wins.test',
            'sponsored' => false,
            'partner_material' => true,
            'as_you_prefer' => true,
        ]);
        $exclusive = $this->site($publisher, [
            'domain' => 'already-exclusive.test',
            'sponsored' => true,
        ]);

        Log::spy();

        $this->assertSame(0, Artisan::call('sites:normalize-tags'));
        $output = Artisan::output();
        $this->assertStringContainsString('Normalized 2 site(s)', $output);
        $this->assertStringContainsString('sponsored-wins.test', $output);
        $this->assertStringContainsString('partner-wins.test', $output);
        $this->assertStringNotContainsString('already-exclusive.test', $output);

        $sponsoredWins->refresh();
        $this->assertTrue((bool) $sponsoredWins->sponsored);
        $this->assertFalse((bool) $sponsoredWins->partner_material);
        $this->assertFalse((bool) $sponsoredWins->as_you_prefer);
        $this->assertSame(SiteTag::SPONSORED, $sponsoredWins->tagValue());

        $partnerWins->refresh();
        $this->assertFalse((bool) $partnerWins->sponsored);
        $this->assertTrue((bool) $partnerWins->partner_material);
        $this->assertFalse((bool) $partnerWins->as_you_prefer);

        $exclusive->refresh();
        $this->assertTrue((bool) $exclusive->sponsored);
        $this->assertFalse((bool) $exclusive->partner_material);

        Log::shouldHaveReceived('info')->withArgs(function (string $message, array $context) use ($sponsoredWins) {
            return $message === 'sites.normalize-tags'
                && ($context['site_id'] ?? null) === $sponsoredWins->id
                && ($context['to'] ?? null) === SiteTag::SPONSORED
                && ($context['dry_run'] ?? null) === false;
        });
    }

    public function test_dry_run_logs_but_does_not_write(): void
    {
        $publisher = $this->publisher();
        $site = $this->site($publisher, [
            'domain' => 'dry-run-conflict.test',
            'sponsored' => true,
            'as_you_prefer' => true,
        ]);

        $this->assertSame(0, Artisan::call('sites:normalize-tags', ['--dry-run' => true]));
        $output = Artisan::output();
        $this->assertStringContainsString('[dry run]', $output);
        $this->assertStringContainsString('dry-run-conflict.test', $output);
        $this->assertStringContainsString('No rows written', $output);

        $site->refresh();
        $this->assertTrue((bool) $site->sponsored);
        $this->assertTrue((bool) $site->as_you_prefer);
    }

    public function test_limit_processes_only_n_conflicts(): void
    {
        $publisher = $this->publisher();
        $first = $this->site($publisher, [
            'domain' => 'limit-first.test',
            'sponsored' => true,
            'partner_material' => true,
        ]);
        $second = $this->site($publisher, [
            'domain' => 'limit-second.test',
            'partner_material' => true,
            'as_you_prefer' => true,
        ]);

        $this->assertSame(0, Artisan::call('sites:normalize-tags', ['--limit' => 1]));
        $this->assertStringContainsString('Normalized 1 site(s)', Artisan::output());

        $first->refresh();
        $second->refresh();
        $this->assertFalse((bool) $first->partner_material);
        $this->assertTrue((bool) $second->partner_material);
        $this->assertTrue((bool) $second->as_you_prefer);
    }

    public function test_reports_when_nothing_conflicts(): void
    {
        $publisher = $this->publisher();
        $this->site($publisher, ['sponsored' => true]);

        $this->assertSame(0, Artisan::call('sites:normalize-tags'));
        $this->assertStringContainsString('No sites have more than one listing tag', Artisan::output());
    }
}
