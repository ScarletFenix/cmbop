<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\ContentModeration\ContentModerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Restricted-niche checks fail quietly by design: a category that is switched
 * off simply never flags anything, which looks exactly like clean content. So
 * the coverage itself is worth pinning, and the admin screen has to say out loud
 * when something is not being checked.
 */
class RestrictedCategoryCoverageTest extends TestCase
{
    use RefreshDatabase;

    private function scan(string $text): array
    {
        return app(ContentModerationService::class)->scanExtractedContent(
            text: $text,
            html: '<p>'.e($text).'</p>',
            sourceLabel: 'test',
            user: null,
            title: 'Test article',
            links: [],
        );
    }

    public function test_every_restricted_niche_is_flagged(): void
    {
        $samples = [
            'casino' => 'Play at the best online casino and claim your no deposit bonus for slots and roulette today.',
            'adult' => 'Watch free porn videos and adult webcam shows on our 18+ escort directory tonight.',
            'cbd' => 'Buy cbd oil and hemp flower from our cannabis dispensary, thc products shipped fast.',
            'alcohol' => 'Our online liquor store lets you buy vodka and cheap whiskey delivered to your door.',
            'tobacco' => 'Buy cigarettes online and get vape juice wholesale from our tobacco shop online.',
            'weapons' => 'Buy firearms and ammunition for sale, including ghost gun kits, shipped discreetly.',
            'crypto_promo' => 'Guaranteed crypto profits from our pump and dump group — get rich with bitcoin now.',
        ];

        $slippedThrough = [];

        foreach ($samples as $niche => $text) {
            if ((bool) ($this->scan($text)['passed'] ?? true)) {
                $slippedThrough[] = $niche;
            }
        }

        // Reported together: knowing only the first gap hides the rest.
        $this->assertSame([], $slippedThrough, 'Not flagged: '.implode(', ', $slippedThrough));
    }

    public function test_ordinary_marketing_copy_still_passes(): void
    {
        $result = $this->scan(
            'This guide explains how to build a content calendar for a B2B SaaS blog, '
            .'covering keyword research, internal linking and measuring organic growth '
            .'with a simple reporting dashboard your team will actually read.'
        );

        $this->assertTrue((bool) ($result['passed'] ?? false), 'Clean content was rejected.');
    }

    public function test_every_restricted_category_ships_switched_on(): void
    {
        $off = collect(config('content_moderation.categories'))
            ->reject(fn ($cat) => (bool) ($cat['enabled'] ?? false))
            ->keys()
            ->all();

        // A category shipped off is one nobody knows is off.
        $this->assertSame([], $off, 'Categories default to disabled: '.implode(', ', $off));
    }

    public function test_the_admin_screen_warns_when_moderation_is_off(): void
    {
        $role = Role::firstOrCreate(['name' => 'admin']);
        $admin = User::factory()->create(['email_verified_at' => now(), 'active_role_id' => $role->id]);
        $admin->roles()->attach($role->id);

        config(['content_moderation.enabled' => false]);

        $this->actingAs($admin->fresh())
            ->get(route('admin.moderation.index'))
            ->assertOk()
            ->assertSee('Content moderation is switched off');
    }
}
