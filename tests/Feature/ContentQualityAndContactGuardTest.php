<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\ContentUpload\ArticleEvaluationService;
use App\Services\OrderChatContactGuard;
use App\Support\SiteDescriptionRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesContentSubmissions;
use Tests\TestCase;

class ContentQualityAndContactGuardTest extends TestCase
{
    use CreatesContentSubmissions;
    use RefreshDatabase;

    private function advertiser(): User
    {
        $role = Role::firstOrCreate(['name' => 'advertiser']);
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    private function englishBody(): string
    {
        return str_repeat(
            'This article explains digital marketing strategies that help brands grow organic traffic with useful content. ',
            12
        ).'Readers will find clear tips about SEO, content, and conversion which are useful for their business.';
    }

    public function test_evaluation_rejects_more_than_fifteen_outbound_links(): void
    {
        config([
            'content_moderation.enabled' => true,
            'content_moderation.quality.block_on_quality_failure' => true,
        ]);

        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);
        $body = $this->englishBody();
        $links = '';
        for ($i = 1; $i <= 16; $i++) {
            $links .= '<p><a href="https://example.com/page-'.$i.'">source '.$i.'</a></p>';
        }
        $submission->update([
            'language' => 'en',
            'extracted_text' => $body,
            'preview_html' => '<p>'.$body.'</p>'.$links,
            'target_url' => null,
        ]);

        $result = app(ArticleEvaluationService::class)->evaluate($submission->fresh(), $advertiser);

        $this->assertFalse($result['approved']);
        $this->assertStringContainsString('outbound links', (string) $result['message']);
    }

    public function test_evaluation_rejects_url_shortener(): void
    {
        config([
            'content_moderation.enabled' => true,
            'content_moderation.quality.block_on_quality_failure' => true,
        ]);

        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);
        $body = $this->englishBody();
        $submission->update([
            'language' => 'en',
            'extracted_text' => $body,
            'preview_html' => '<p>'.$body.'</p><p><a href="https://bit.ly/guestpost">read more</a></p>',
            'target_url' => null,
        ]);

        $result = app(ArticleEvaluationService::class)->evaluate($submission->fresh(), $advertiser);

        $this->assertFalse($result['approved']);
        $this->assertStringContainsString('shortener', strtolower((string) $result['message']));
    }

    public function test_evaluation_rejects_off_platform_contact_in_article(): void
    {
        config(['content_moderation.enabled' => true]);

        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);
        $body = $this->englishBody().' Telegram me @brandhelp after you publish.';
        $submission->update([
            'language' => 'en',
            'extracted_text' => $body,
            'preview_html' => '<p>'.$body.'</p>',
            'target_url' => null,
        ]);

        $result = app(ArticleEvaluationService::class)->evaluate($submission->fresh(), $advertiser);

        $this->assertFalse($result['approved']);
        $this->assertSame(OrderChatContactGuard::messageFor('article'), $result['message']);
    }

    public function test_evaluation_approves_a_clean_article(): void
    {
        config([
            'content_moderation.enabled' => true,
            'content_moderation.quality.block_on_quality_failure' => true,
        ]);

        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);
        $body = $this->englishBody();
        $submission->update([
            'language' => 'en',
            'extracted_text' => $body,
            'preview_html' => '<p>'.$body.'</p><p><a href="https://example.com/guide">helpful guide</a></p>',
            'target_url' => null,
        ]);

        $result = app(ArticleEvaluationService::class)->evaluate($submission->fresh(), $advertiser);

        $this->assertTrue($result['approved'], (string) ($result['message'] ?? ''));
    }

    public function test_site_description_rules_reject_contact_details(): void
    {
        $html = '<p>This listing is for your audience and the publishers who write guest posts here. WhatsApp me +441234567890.</p>';

        $this->assertNotEmpty(SiteDescriptionRules::errors($html));
    }
}
