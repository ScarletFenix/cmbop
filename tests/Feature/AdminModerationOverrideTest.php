<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\ContentModerationLog;
use App\Models\ContentModerationSetting;
use App\Models\ContentSubmission;
use App\Models\Role;
use App\Models\User;
use App\Services\ContentModeration\ContentModerationService;
use App\Services\ContentUpload\ArticleEvaluationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesContentSubmissions;
use Tests\TestCase;

class AdminModerationOverrideTest extends TestCase
{
    use CreatesContentSubmissions;
    use RefreshDatabase;

    private function admin(): User
    {
        $role = Role::firstOrCreate(['name' => 'admin']);
        $user = User::factory()->create(['email_verified_at' => now(), 'active_role_id' => $role->id]);
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    private function advertiser(): User
    {
        $role = Role::firstOrCreate(['name' => 'advertiser']);
        $user = User::factory()->create(['email_verified_at' => now(), 'active_role_id' => $role->id]);
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    private function rejectCasinoArticle(User $advertiser): array
    {
        config(['content_moderation.enabled' => true]);
        ContentModerationSetting::clearCache();

        $submission = $this->createApprovedSubmission($advertiser);
        config(['content_moderation.enabled' => true]);
        ContentModerationSetting::clearCache();
        $body = 'Play at the best online casino and claim your no deposit bonus for slots and roulette today.';
        $submission->update([
            'extracted_text' => $body,
            'preview_html' => '<p>'.$body.'</p>',
            'moderation_status' => ContentSubmission::STATUS_REJECTED,
        ]);

        $result = app(ContentModerationService::class)->scanExtractedContent(
            text: $body,
            html: '<p>'.$body.'</p>',
            sourceLabel: 'upload:'.$submission->id,
            user: $advertiser,
            title: 'Casino guide',
            links: [],
            contentSubmissionId: (int) $submission->id,
        );

        $submission->update([
            'moderation_status' => ContentSubmission::STATUS_REJECTED,
            'evaluation_status' => 'rejected',
            'evaluation_report' => [
                'summary' => $result['user_message'] ?? 'Restricted content',
                'passed_compliance' => false,
                'checks' => [[
                    'key' => 'restricted_content',
                    'label' => 'Restricted content',
                    'status' => 'fail',
                    'detail' => 'Found: casino',
                ]],
            ],
            'moderation_log_id' => $result['log']?->id,
            'scan_token' => $result['scan_token'],
        ]);

        return [$submission->fresh(), $result['log']];
    }

    public function test_scan_writes_content_submission_id(): void
    {
        [$submission, $log] = $this->rejectCasinoArticle($this->advertiser());

        $this->assertInstanceOf(ContentModerationLog::class, $log);
        $this->assertSame((int) $submission->id, (int) $log->content_submission_id);
        $this->assertFalse((bool) ($log->passed ?? true));
    }

    public function test_weapons_evaluation_report_does_not_call_it_casino(): void
    {
        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);
        $body = 'Buy firearms and ammunition for sale, including ghost gun kits, shipped discreetly.';
        $submission->update([
            'title' => 'Weapons guide',
            'extracted_text' => $body,
            'preview_html' => '<p>'.$body.'</p>',
        ]);
        config(['content_moderation.enabled' => true]);
        ContentModerationSetting::clearCache();

        $result = app(ArticleEvaluationService::class)->evaluate($submission->fresh(), $advertiser);
        $this->assertFalse((bool) ($result['approved'] ?? true));
        $label = collect($result['report']['checks'] ?? [])
            ->firstWhere('key', 'restricted_content')['label'] ?? '';
        $this->assertStringContainsString('weapons', strtolower((string) $label));
        $this->assertStringNotContainsString('casino', strtolower((string) $label));
    }

    public function test_weapons_rejection_does_not_call_the_hit_casino(): void
    {
        config(['content_moderation.enabled' => true]);
        $result = app(ContentModerationService::class)->scanExtractedContent(
            text: 'Buy firearms and ammunition for sale, including ghost gun kits, shipped discreetly.',
            html: '<p>Buy firearms and ammunition for sale.</p>',
            sourceLabel: 'test',
            title: 'Weapons guide',
        );

        $this->assertFalse((bool) ($result['passed'] ?? true));
        $this->assertSame('weapons', $result['log']?->detected_category);
        $this->assertStringContainsString('weapons', strtolower((string) $result['user_message']));
        $this->assertStringNotContainsString('casino', strtolower((string) $result['user_message']));
    }

    public function test_override_approves_the_linked_article_for_checkout(): void
    {
        $admin = $this->admin();
        $advertiser = $this->advertiser();
        [$submission, $log] = $this->rejectCasinoArticle($advertiser);

        $this->actingAs($admin)
            ->post(route('admin.moderation.override', $log), [
                'notes' => 'News article about regulation, not a promo.',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $submission->refresh();
        $log->refresh();
        $this->assertTrue((bool) $log->admin_override);
        $this->assertSame(ContentSubmission::STATUS_APPROVED, $submission->moderation_status);
        $this->assertSame('approved', $submission->evaluation_status);
        $this->assertSame([], $submission->evaluationReasonGroups()['blocking']);
        $this->assertSame((int) $log->id, (int) $submission->moderation_log_id);

        $check = app(ContentModerationService::class)->assertSubmissionsApproved([$submission], $advertiser);
        $this->assertTrue($check['ok'], json_encode($check['failures']));
        $this->assertTrue(ActivityLog::query()->where('action', 'moderation.overridden')->exists());
    }

    public function test_title_change_after_override_revokes_the_pass(): void
    {
        $admin = $this->admin();
        $advertiser = $this->advertiser();
        [$submission, $log] = $this->rejectCasinoArticle($advertiser);

        $this->actingAs($admin)
            ->post(route('admin.moderation.override', $log), [
                'notes' => 'Allow this version only.',
            ])
            ->assertRedirect();

        $submission->refresh()->update([
            'title' => 'Best online casino bonus guide',
        ]);

        $check = app(ContentModerationService::class)->assertSubmissionsApproved([$submission->fresh()], $advertiser);
        $this->assertFalse($check['ok']);
        $this->assertSame(ContentSubmission::STATUS_REJECTED, $submission->fresh()->moderation_status);
    }

    public function test_editing_after_override_revokes_the_pass(): void
    {
        $admin = $this->admin();
        $advertiser = $this->advertiser();
        [$submission, $log] = $this->rejectCasinoArticle($advertiser);

        $this->actingAs($admin)
            ->post(route('admin.moderation.override', $log), [
                'notes' => 'Allow this version only.',
            ])
            ->assertRedirect();

        $submission->refresh();
        $submission->update([
            'extracted_text' => $submission->extracted_text.' Visit https://www.bet365.com/sports tonight.',
            'preview_html' => $submission->preview_html.'<p>Visit <a href="https://www.bet365.com/sports">here</a>.</p>',
        ]);

        $check = app(ContentModerationService::class)->assertSubmissionsApproved([$submission->fresh()], $advertiser);
        $this->assertFalse($check['ok']);
        $this->assertSame(ContentSubmission::STATUS_REJECTED, $submission->fresh()->moderation_status);
    }

    public function test_revert_rechecks_and_blocks_checkout(): void
    {
        $admin = $this->admin();
        $advertiser = $this->advertiser();
        [$submission, $log] = $this->rejectCasinoArticle($advertiser);

        $this->actingAs($admin)
            ->post(route('admin.moderation.override', $log), ['notes' => 'Temporary allow.'])
            ->assertRedirect();

        $this->actingAs($admin)
            ->post(route('admin.moderation.revert', $log->fresh()))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(ContentSubmission::STATUS_REJECTED, $submission->fresh()->moderation_status);
        $this->assertSame('rejected', $submission->fresh()->evaluation_status);
        $this->assertStringNotContainsString(
            'Approved by admin override',
            (string) ($submission->fresh()->evaluation_report['summary'] ?? '')
        );
        $check = app(ContentModerationService::class)->assertSubmissionsApproved([$submission->fresh()], $advertiser);
        $this->assertFalse($check['ok']);
    }

    public function test_stale_reject_row_cannot_be_overridden(): void
    {
        $admin = $this->admin();
        $advertiser = $this->advertiser();
        [$submission, $old] = $this->rejectCasinoArticle($advertiser);

        $current = ContentModerationLog::create([
            'user_id' => $advertiser->id,
            'content_submission_id' => $submission->id,
            'document_url' => 'upload:'.$submission->id,
            'status' => ContentModerationLog::STATUS_REJECTED,
            'passed' => false,
            'scan_token' => 'scan-current',
            'word_count' => 20,
        ]);
        $submission->update(['moderation_log_id' => $current->id, 'scan_token' => 'scan-current']);

        $this->actingAs($admin)
            ->from(route('admin.moderation.index'))
            ->post(route('admin.moderation.override', $old), [
                'notes' => 'Trying to override an old row.',
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertFalse((bool) $old->fresh()->admin_override);
        $this->assertSame(ContentSubmission::STATUS_REJECTED, $submission->fresh()->moderation_status);
        $this->assertSame((int) $current->id, (int) $submission->fresh()->moderation_log_id);
    }

    public function test_library_reject_blocks_checkout_until_the_article_is_edited(): void
    {
        $admin = $this->admin();
        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);
        config(['content_moderation.enabled' => true]);
        ContentModerationSetting::clearCache();

        $this->actingAs($admin)
            ->post(route('admin.content-library.override', $submission), [
                'decision' => 'rejected',
                'notes' => 'Client asked us to hold this version.',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $submission->refresh();
        $this->assertSame(ContentSubmission::STATUS_REJECTED, $submission->moderation_status);
        $this->assertTrue((bool) $submission->moderationLog?->admin_override);
        $this->assertNotEmpty($submission->moderationLog?->signals['override_fingerprint'] ?? null);

        $check = app(ContentModerationService::class)->assertSubmissionsApproved([$submission], $advertiser);
        $this->assertFalse($check['ok']);

        $submission->update([
            'extracted_text' => $submission->extracted_text.' Updated closing paragraph for the new brief.',
        ]);

        $check = app(ContentModerationService::class)->assertSubmissionsApproved([$submission->fresh()], $advertiser);
        $this->assertTrue($check['ok'], json_encode($check['failures']));
    }

    public function test_url_override_is_not_immortal(): void
    {
        $advertiser = $this->advertiser();
        $url = 'https://docs.google.com/document/d/stale-override/edit';
        $log = ContentModerationLog::create([
            'user_id' => $advertiser->id,
            'document_url' => $url,
            'status' => ContentModerationLog::STATUS_APPROVED,
            'passed' => true,
            'admin_override' => true,
            'scan_token' => 'scan-url-old',
            'word_count' => 20,
            'created_at' => now()->subDays(2),
            'updated_at' => now()->subDays(2),
        ]);

        $this->assertFalse($log->isUsableApproval(900));

        config(['content_moderation.enabled' => true]);
        ContentModerationSetting::clearCache();
        $check = app(ContentModerationService::class)->assertLinksApproved([$url], $advertiser);
        $this->assertFalse($check['ok']);
    }

    public function test_advertiser_cannot_override(): void
    {
        $advertiser = $this->advertiser();
        [, $log] = $this->rejectCasinoArticle($advertiser);

        $this->actingAs($advertiser)
            ->post(route('admin.moderation.override', $log), ['notes' => 'Please approve this.'])
            ->assertForbidden();
    }

    public function test_override_requires_notes(): void
    {
        $admin = $this->admin();
        [, $log] = $this->rejectCasinoArticle($this->advertiser());

        $this->actingAs($admin)
            ->from(route('admin.moderation.index'))
            ->post(route('admin.moderation.override', $log), ['notes' => ''])
            ->assertRedirect(route('admin.moderation.index'))
            ->assertSessionHasErrors('notes');
    }

    public function test_index_shows_revert_on_the_current_override(): void
    {
        $admin = $this->admin();
        [$submission, $log] = $this->rejectCasinoArticle($this->advertiser());

        $this->actingAs($admin)
            ->post(route('admin.moderation.override', $log), ['notes' => 'Allow this version.'])
            ->assertRedirect();

        $this->actingAs($admin)
            ->get(route('admin.moderation.index'))
            ->assertOk()
            ->assertSee('Revert')
            ->assertSee(route('admin.moderation.revert', $log), false);
        $this->assertSame((int) $log->id, (int) $submission->fresh()->moderation_log_id);
    }

    public function test_override_fails_when_the_article_was_deleted(): void
    {
        $admin = $this->admin();
        [$submission, $log] = $this->rejectCasinoArticle($this->advertiser());
        $submission->delete();

        $this->actingAs($admin)
            ->from(route('admin.moderation.index'))
            ->post(route('admin.moderation.override', $log), ['notes' => 'Approve anyway.'])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertFalse((bool) $log->fresh()->admin_override);
    }

    public function test_doc_button_does_not_use_upload_protocol(): void
    {
        $admin = $this->admin();
        [$submission, $log] = $this->rejectCasinoArticle($this->advertiser());

        $this->actingAs($admin)
            ->get(route('admin.moderation.index'))
            ->assertOk()
            ->assertDontSee('href="upload:', false)
            ->assertSee(route('admin.content-library.show', $submission), false)
            ->assertSee(route('admin.moderation.show', $log), false);
    }

    public function test_queue_filters_and_array_search_do_not_500(): void
    {
        $admin = $this->admin();
        [$submission] = $this->rejectCasinoArticle($this->advertiser());

        $this->actingAs($admin)
            ->get(route('admin.moderation.index', ['status' => 'rejected']))
            ->assertOk()
            ->assertSee($submission->user?->email);

        $this->actingAs($admin)
            ->get(route('admin.moderation.index', ['status' => 'approved']))
            ->assertOk()
            ->assertDontSee($submission->user?->email);

        $this->actingAs($admin)
            ->get(route('admin.moderation.index', ['q' => ['injected']]))
            ->assertOk();
    }

    public function test_settings_save_writes_override_and_activity(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post(route('admin.moderation.settings'), [
                'confidence_threshold' => 75,
                'categories' => ['gambling', 'adult', 'cbd', 'alcohol', 'tobacco', 'weapons'],
                'extra_keywords' => "viagra\n",
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $override = ContentModerationSetting::getValue('config_override', []);
        $this->assertFalse((bool) ($override['enabled'] ?? true));
        $this->assertSame(75, (int) ($override['confidence_threshold'] ?? 0));
        $this->assertContains('viagra', ContentModerationSetting::getValue('extra_keywords', []));
        $this->assertTrue(ActivityLog::query()->where('action', 'moderation.settings_updated')->exists());
    }

    public function test_log_show_page_lists_matched_terms(): void
    {
        $admin = $this->admin();
        [, $log] = $this->rejectCasinoArticle($this->advertiser());

        $this->actingAs($admin)
            ->get(route('admin.moderation.show', $log))
            ->assertOk()
            ->assertSee('casino')
            ->assertSee('Matched terms');
    }
}
