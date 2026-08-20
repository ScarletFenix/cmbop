<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\ContentModeration\ContentModerationService;
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

    public function test_evaluation_allows_telegram_as_a_topic_without_a_handle(): void
    {
        config(['content_moderation.enabled' => true]);

        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);
        $body = $this->englishBody().' The piece also covers Telegram marketing for community managers.';
        $submission->update([
            'language' => 'en',
            'extracted_text' => $body,
            'preview_html' => '<p>'.$body.'</p>',
            'target_url' => null,
        ]);

        $result = app(ArticleEvaluationService::class)->evaluate($submission->fresh(), $advertiser);

        $this->assertTrue($result['approved'], (string) ($result['message'] ?? ''));
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

    public function test_evaluation_allows_dates_and_outside_the_site_copy(): void
    {
        config(['content_moderation.enabled' => true]);

        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);
        $body = $this->englishBody()
            .' Published 2024-12-15. A number of brands should contact their audience'
            .' and keep conversations outside the site homepage.';
        $submission->update([
            'language' => 'en',
            'extracted_text' => $body,
            'preview_html' => '<p>'.$body.'</p>',
            'target_url' => null,
        ]);

        $result = app(ArticleEvaluationService::class)->evaluate($submission->fresh(), $advertiser);

        $this->assertTrue($result['approved'], (string) ($result['message'] ?? ''));
    }

    public function test_checkout_still_blocks_contact_when_keyword_moderation_is_off(): void
    {
        config(['content_moderation.enabled' => false]);

        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);
        $body = $this->englishBody().' Telegram me @brandhelp after you publish.';
        $submission->update([
            'language' => 'en',
            'extracted_text' => $body,
            'preview_html' => '<p>'.$body.'</p><p><a href="https://t.me/brandhelp">chat</a></p>',
            'target_url' => null,
        ]);

        $check = app(ContentModerationService::class)->assertSubmissionsApproved(
            [$submission->fresh()],
            $advertiser
        );

        $this->assertFalse($check['ok']);
        $this->assertStringContainsString(
            'messaging-app',
            strtolower((string) ($check['failures'][0]['message'] ?? ''))
        );
    }

    public function test_checkout_still_blocks_shorteners_when_keyword_moderation_is_off(): void
    {
        config([
            'content_moderation.enabled' => false,
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

        $check = app(ContentModerationService::class)->assertSubmissionsApproved(
            [$submission->fresh()],
            $advertiser
        );

        $this->assertFalse($check['ok']);
        $this->assertStringContainsString(
            'shortener',
            strtolower((string) ($check['failures'][0]['message'] ?? ''))
        );
    }

    public function test_evaluation_rejects_whatsapp_backlink_target(): void
    {
        config(['content_moderation.enabled' => true]);

        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);
        $body = $this->englishBody();
        $submission->update([
            'language' => 'en',
            'extracted_text' => $body,
            'preview_html' => '<p>'.$body.'</p>',
            'target_url' => 'https://wa.me/441234567890',
        ]);

        $result = app(ArticleEvaluationService::class)->evaluate($submission->fresh(), $advertiser);

        $this->assertFalse($result['approved']);
        $this->assertSame(OrderChatContactGuard::messageFor('article'), $result['message']);
    }

    public function test_evaluation_rejects_plain_text_shortener(): void
    {
        config(['content_moderation.enabled' => true]);

        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);
        $body = $this->englishBody().' Full case study: bit.ly/guestpost';
        $submission->update([
            'language' => 'en',
            'extracted_text' => $body,
            'preview_html' => '<p>'.$body.'</p>',
            'target_url' => null,
        ]);

        $result = app(ArticleEvaluationService::class)->evaluate($submission->fresh(), $advertiser);

        $this->assertFalse($result['approved']);
        $this->assertStringContainsString('shortener', strtolower((string) $result['message']));
    }

    public function test_evaluation_allows_retina_image_filenames(): void
    {
        config(['content_moderation.enabled' => true]);

        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);
        $body = $this->englishBody();
        $submission->update([
            'language' => 'en',
            'extracted_text' => $body,
            'preview_html' => '<p>'.$body.'</p><img src="/storage/articles/hero@2x.png" alt="hero">',
            'target_url' => null,
            'feature_image_url' => '/storage/articles/hero@2x.png',
        ]);

        $result = app(ArticleEvaluationService::class)->evaluate($submission->fresh(), $advertiser);

        $this->assertTrue($result['approved'], (string) ($result['message'] ?? ''));
    }

    public function test_evaluation_allows_year_2000_next_to_a_date(): void
    {
        config(['content_moderation.enabled' => true]);

        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);
        $body = $this->englishBody().' Growth since 2000 2024-12-15 has been steady.';
        $submission->update([
            'language' => 'en',
            'extracted_text' => $body,
            'preview_html' => '<p>'.$body.'</p>',
            'target_url' => null,
        ]);

        $result = app(ArticleEvaluationService::class)->evaluate($submission->fresh(), $advertiser);

        $this->assertTrue($result['approved'], (string) ($result['message'] ?? ''));
    }
}
