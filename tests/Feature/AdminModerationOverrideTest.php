<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\ContentModerationLog;
use App\Models\ContentModerationSetting;
use App\Models\ContentSubmission;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\ContentModeration\ContentModerationEngine;
use App\Services\ContentModeration\ContentModerationService;
use App\Services\ContentUpload\ArticleEvaluationService;
use App\Services\OrderPaymentService;
use App\Services\StripeCustomerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CreatesContentSubmissions;
use Tests\TestCase;
use ZipArchive;

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
        $this->assertSame(1, ActivityLog::query()->where('action', 'moderation.overridden')->count());

        $this->actingAs($admin)
            ->post(route('admin.moderation.override', $log), [
                'notes' => 'News article about regulation, not a promo.',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(1, ActivityLog::query()->where('action', 'moderation.overridden')->count());
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

    public function test_title_only_draft_save_after_override_revokes_approval(): void
    {
        $admin = $this->admin();
        $advertiser = $this->advertiser();
        [$submission, $log] = $this->rejectCasinoArticle($advertiser);

        $this->actingAs($admin)
            ->post(route('admin.moderation.override', $log), [
                'notes' => 'Allow this version only.',
            ])
            ->assertRedirect();

        $this->actingAs($advertiser)
            ->patchJson(route('advertiser.content-submissions.update', $submission), [
                'title' => 'Best online casino bonus guide',
            ])
            ->assertOk()
            ->assertJsonPath('approved', false);

        $this->assertSame(ContentSubmission::STATUS_REJECTED, $submission->fresh()->moderation_status);
    }

    public function test_settlement_rechecks_policy_after_a_silent_title_edit(): void
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
        $this->assertTrue($submission->fresh()->isReadyForCheckout());

        $publisherRole = Role::firstOrCreate(['name' => 'publisher']);
        $publisher = User::factory()->create(['email_verified_at' => now()]);
        $publisher->roles()->attach($publisherRole->id);
        $site = Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Override Settlement Site',
            'site_url' => 'https://override-settle.example',
            'domain' => 'override-settle.example',
            'da' => 20,
            'dr' => 20,
            'traffic' => 100,
            'country' => 'us',
            'language' => 'en',
            'category' => 'marketing',
            'price' => 40,
            'publication_time' => '7 days',
            'link_type' => 'dofollow',
            'description' => 'Settlement policy recheck',
            'verified' => true,
            'active' => true,
        ]);
        $order = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => 'ORD-OVR-1',
            'reference_code' => 'REF-OVR-1',
            'subtotal' => 40,
            'tax' => 0,
            'total_amount' => 40,
            'payment_method' => 'card',
            'payment_status' => 'unpaid',
            'status' => 'pending',
        ]);
        OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'price' => 40,
            'content_link' => 'https://example.com/article.docx',
            'content_submission_id' => $submission->id,
        ]);

        $state = app(OrderPaymentService::class)->libraryContentStateForSettlement($order->fresh());
        $this->assertSame('unready', $state);
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
        $fresh = $submission->fresh();
        $this->assertSame('rejected', $fresh->evaluation_status);
        $this->assertStringNotContainsString(
            'Approved by admin override',
            (string) ($fresh->evaluation_report['summary'] ?? '')
        );
        $this->assertStringNotContainsString(
            'approved by admin override',
            strtolower(implode(' ', $fresh->evaluationReasonGroups()['blocking']))
        );
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
        $this->assertSame('approved', $submission->fresh()->evaluation_status);
        $this->assertStringNotContainsString(
            'Rejected by admin override',
            (string) ($submission->fresh()->evaluation_report['summary'] ?? '')
        );
    }

    public function test_skipped_scan_is_not_a_usable_approval(): void
    {
        $advertiser = $this->advertiser();
        $url = 'https://docs.google.com/document/d/skipped-cache/edit';
        $log = ContentModerationLog::create([
            'user_id' => $advertiser->id,
            'document_url' => $url,
            'status' => ContentModerationLog::STATUS_APPROVED,
            'passed' => true,
            'scan_token' => 'scan-skipped',
            'word_count' => 20,
            'signals' => ['moderation_disabled' => true],
        ]);

        $this->assertTrue($log->wasSkipped());
        $this->assertFalse($log->isUsableApproval(900));

        config(['content_moderation.enabled' => true]);
        ContentModerationSetting::clearCache();
        $check = app(ContentModerationService::class)->assertLinksApproved([$url], $advertiser);
        $this->assertFalse($check['ok']);
    }

    public function test_staff_override_clears_the_skipped_flag(): void
    {
        $admin = $this->admin();
        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);
        $log = ContentModerationLog::create([
            'user_id' => $advertiser->id,
            'content_submission_id' => $submission->id,
            'document_url' => 'upload:'.$submission->id,
            'status' => ContentModerationLog::STATUS_APPROVED,
            'passed' => true,
            'scan_token' => 'scan-skipped-lib',
            'word_count' => 20,
            'signals' => ['moderation_disabled' => true],
        ]);
        $submission->update(['moderation_log_id' => $log->id, 'scan_token' => 'scan-skipped-lib']);

        $this->actingAs($admin)
            ->post(route('admin.content-library.override', $submission), [
                'decision' => 'approved',
                'notes' => 'Reviewed after turning the scanner back on.',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $log->refresh();
        $this->assertTrue((bool) $log->admin_override);
        $this->assertFalse($log->wasSkipped());
        $this->assertNotEmpty($log->signals['override_fingerprint'] ?? null);
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
        ]);
        ContentModerationLog::query()->whereKey($log->id)->update([
            'created_at' => now()->subDays(2),
            'updated_at' => now()->subDays(2),
        ]);
        $log->refresh();

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
        $this->assertSame(1, ActivityLog::query()->where('action', 'moderation.settings_updated')->count());

        $this->actingAs($admin)
            ->post(route('admin.moderation.settings'), [
                'confidence_threshold' => 75,
                'categories' => ['gambling', 'adult', 'cbd', 'alcohol', 'tobacco', 'weapons'],
                'extra_keywords' => "viagra\n",
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(1, ActivityLog::query()->where('action', 'moderation.settings_updated')->count());
    }

    public function test_legacy_override_without_fingerprint_does_not_relog(): void
    {
        $admin = $this->admin();
        $advertiser = $this->advertiser();
        [$submission, $log] = $this->rejectCasinoArticle($advertiser);

        $this->actingAs($admin)
            ->post(route('admin.moderation.override', $log), [
                'notes' => 'News article about regulation, not a promo.',
            ])
            ->assertRedirect();

        $this->assertSame(1, ActivityLog::query()->where('action', 'moderation.overridden')->count());

        $log->refresh();
        $signals = is_array($log->signals) ? $log->signals : [];
        unset($signals['override_fingerprint']);
        $log->forceFill(['signals' => $signals])->save();

        $this->actingAs($admin)
            ->post(route('admin.moderation.override', $log), [
                'notes' => 'News article about regulation, not a promo.',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(1, ActivityLog::query()->where('action', 'moderation.overridden')->count());
        $this->assertNotEmpty($log->fresh()->signals['override_fingerprint'] ?? null);
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

    public function test_revision_confirm_existing_still_allows_an_unedited_override(): void
    {
        Mail::fake();
        $admin = $this->admin();
        $advertiser = $this->advertiser();
        [$submission, $log] = $this->rejectCasinoArticle($advertiser);

        $this->actingAs($admin)
            ->post(route('admin.moderation.override', $log), [
                'notes' => 'Allow this version only.',
            ])
            ->assertRedirect();

        $item = $this->paidLibraryItem($advertiser, $submission);

        $this->actingAs($advertiser)
            ->postJson(route('advertiser.orders.fulfill-content-revision', $item->order_id), [
                'confirm_existing' => true,
                'order_item_id' => $item->id,
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertFalse($item->fresh()->isContentRevisionRequested());
        $this->assertSame(ContentSubmission::STATUS_APPROVED, $submission->fresh()->moderation_status);
    }

    public function test_revision_confirm_existing_blocks_a_silent_title_edit(): void
    {
        Mail::fake();
        $admin = $this->admin();
        $advertiser = $this->advertiser();
        [$submission, $log] = $this->rejectCasinoArticle($advertiser);

        $this->actingAs($admin)
            ->post(route('admin.moderation.override', $log), [
                'notes' => 'Allow this version only.',
            ])
            ->assertRedirect();

        $item = $this->paidLibraryItem($advertiser, $submission);
        $submission->refresh()->update([
            'title' => 'Best online casino bonus guide',
        ]);
        $this->assertTrue($submission->fresh()->isApproved());

        $this->actingAs($advertiser)
            ->postJson(route('advertiser.orders.fulfill-content-revision', $item->order_id), [
                'confirm_existing' => true,
                'order_item_id' => $item->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['confirm_existing']);

        $this->assertTrue($item->fresh()->isContentRevisionRequested());
        $this->assertSame(ContentSubmission::STATUS_REJECTED, $submission->fresh()->moderation_status);
    }

    public function test_revision_attach_blocks_a_silently_edited_override(): void
    {
        Mail::fake();
        $admin = $this->admin();
        $advertiser = $this->advertiser();
        $current = $this->createApprovedSubmission($advertiser);
        [$replacement, $log] = $this->rejectCasinoArticle($advertiser);

        $this->actingAs($admin)
            ->post(route('admin.moderation.override', $log), [
                'notes' => 'Allow this version only.',
            ])
            ->assertRedirect();

        $item = $this->paidLibraryItem($advertiser, $current);
        $replacement->refresh()->update([
            'title' => 'Best online casino bonus guide',
        ]);
        $this->assertTrue($replacement->fresh()->isApproved());

        $this->actingAs($advertiser)
            ->postJson(route('advertiser.orders.fulfill-content-revision', $item->order_id), [
                'content_submission_id' => $replacement->id,
                'order_item_id' => $item->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['content_submission_id']);

        $this->assertTrue($item->fresh()->isContentRevisionRequested());
        $this->assertSame($current->id, (int) $item->fresh()->content_submission_id);
        $this->assertSame(ContentSubmission::STATUS_REJECTED, $replacement->fresh()->moderation_status);
    }

    public function test_pay_again_blocks_a_silent_title_edit_without_opening_stripe(): void
    {
        $admin = $this->admin();
        $advertiser = $this->advertiser();
        [$submission, $log] = $this->rejectCasinoArticle($advertiser);

        $this->actingAs($admin)
            ->post(route('admin.moderation.override', $log), [
                'notes' => 'Allow this version only.',
            ])
            ->assertRedirect();

        $site = $this->publisherSite();
        $order = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => 'ORD-OVR-RETRY',
            'reference_code' => 'REF-OVR-RETRY',
            'subtotal' => 40,
            'tax' => 0,
            'total_amount' => 40,
            'payment_method' => 'card',
            'payment_status' => 'failed',
            'status' => 'pending',
        ]);
        OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'price' => 40,
            'content_link' => route('advertiser.content-submissions.download', $submission),
            'content_submission_id' => $submission->id,
        ]);
        $submission->update([
            'order_id' => $order->id,
            'title' => 'Best online casino bonus guide',
        ]);

        $this->mock(StripeCustomerService::class, function ($mock) {
            $mock->shouldReceive('configured')->andReturn(true);
            $mock->shouldReceive('createCheckoutSession')->never();
        });

        $this->actingAs($advertiser)
            ->getJson(route('advertiser.orders.list'))
            ->assertOk()
            ->assertJsonPath('orders.0.can_retry_payment', true);

        $this->actingAs($advertiser)
            ->postJson(route('advertiser.orders.retry-payment', $order))
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertSame(ContentSubmission::STATUS_REJECTED, $submission->fresh()->moderation_status);
        $this->assertSame('failed', $order->fresh()->payment_status);
    }

    public function test_html_only_casino_edit_fails_live_policy(): void
    {
        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);
        config(['content_moderation.enabled' => true]);
        ContentModerationSetting::clearCache();

        $submission->update([
            'preview_html' => '<p>Play at the best online casino and claim your no deposit bonus.</p>',
        ]);
        $this->assertStringNotContainsString('casino', strtolower((string) $submission->fresh()->extracted_text));
        $this->assertTrue($submission->fresh()->isApproved());

        $check = app(ContentModerationService::class)->assertSubmissionsApproved([$submission->fresh()], $advertiser);
        $this->assertFalse($check['ok']);
        $this->assertSame(ContentSubmission::STATUS_REJECTED, $submission->fresh()->moderation_status);
    }

    public function test_casino_anchor_only_fails_live_policy(): void
    {
        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);
        config(['content_moderation.enabled' => true]);
        ContentModerationSetting::clearCache();

        $submission->update(['anchor_text' => 'Best online casino bonus']);
        $this->assertStringNotContainsString('casino', strtolower((string) $submission->fresh()->extracted_text));
        $this->assertStringNotContainsString('casino', strtolower((string) $submission->fresh()->preview_html));
        $this->assertTrue($submission->fresh()->isApproved());

        $check = app(ContentModerationService::class)->assertSubmissionsApproved([$submission->fresh()], $advertiser);
        $this->assertFalse($check['ok']);
        $this->assertSame(ContentSubmission::STATUS_REJECTED, $submission->fresh()->moderation_status);
    }

    public function test_casino_detected_link_anchor_fails_live_policy(): void
    {
        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);
        config(['content_moderation.enabled' => true]);
        ContentModerationSetting::clearCache();

        $payload = is_array($submission->draft_payload) ? $submission->draft_payload : [];
        $payload['detected_links'] = [
            [
                'anchor' => 'best software tools',
                'url' => 'https://example.com/tools',
            ],
            [
                'anchor' => 'Best online casino bonus',
                'url' => 'https://example.com/offer',
            ],
        ];
        $submission->update(['draft_payload' => $payload]);
        $this->assertSame('best software tools', $submission->fresh()->anchor_text);
        $this->assertTrue($submission->fresh()->isApproved());

        $check = app(ContentModerationService::class)->assertSubmissionsApproved([$submission->fresh()], $advertiser);
        $this->assertFalse($check['ok']);
        $this->assertSame(ContentSubmission::STATUS_REJECTED, $submission->fresh()->moderation_status);
    }

    public function test_casino_image_alt_fails_live_policy(): void
    {
        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);
        config(['content_moderation.enabled' => true]);
        ContentModerationSetting::clearCache();

        $body = (string) $submission->extracted_text;
        $submission->update([
            'preview_html' => '<p>'.$body.'</p><img src="/storage/hero.jpg" alt="Best online casino bonus">',
        ]);
        $this->assertStringNotContainsString('casino', strtolower((string) $submission->fresh()->extracted_text));
        $this->assertTrue($submission->fresh()->isApproved());

        $check = app(ContentModerationService::class)->assertSubmissionsApproved([$submission->fresh()], $advertiser);
        $this->assertFalse($check['ok']);
        $this->assertSame(ContentSubmission::STATUS_REJECTED, $submission->fresh()->moderation_status);
    }

    public function test_anchor_only_draft_save_after_override_revokes_approval(): void
    {
        $admin = $this->admin();
        $advertiser = $this->advertiser();
        [$submission, $log] = $this->rejectCasinoArticle($advertiser);

        $this->actingAs($admin)
            ->post(route('admin.moderation.override', $log), [
                'notes' => 'Allow this version only.',
            ])
            ->assertRedirect();

        $this->actingAs($advertiser)
            ->patchJson(route('advertiser.content-submissions.update', $submission), [
                'anchor_text' => 'Best online casino bonus',
                'target_url' => 'https://example.com/tools',
            ])
            ->assertOk()
            ->assertJsonPath('approved', false);

        $this->assertSame(ContentSubmission::STATUS_REJECTED, $submission->fresh()->moderation_status);
    }

    public function test_silent_casino_anchor_after_override_revokes_the_pass(): void
    {
        $admin = $this->admin();
        $advertiser = $this->advertiser();
        [$submission, $log] = $this->rejectCasinoArticle($advertiser);

        $this->actingAs($admin)
            ->post(route('admin.moderation.override', $log), [
                'notes' => 'Allow this version only.',
            ])
            ->assertRedirect();

        $check = app(ContentModerationService::class)->assertSubmissionsApproved([$submission->fresh()], $advertiser);
        $this->assertTrue($check['ok'], json_encode($check['failures']));

        $submission->refresh()->update([
            'anchor_text' => 'Best online casino bonus',
        ]);

        $check = app(ContentModerationService::class)->assertSubmissionsApproved([$submission->fresh()], $advertiser);
        $this->assertFalse($check['ok']);
        $this->assertSame(ContentSubmission::STATUS_REJECTED, $submission->fresh()->moderation_status);
    }

    public function test_casino_only_in_stored_docx_fails_live_policy(): void
    {
        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);
        config(['content_moderation.enabled' => true]);
        ContentModerationSetting::clearCache();

        $this->makeDocxFile(
            Storage::disk('local')->path($submission->path),
            'Play at the best online casino and claim your no deposit bonus for slots and roulette today.'
        );
        $this->assertStringNotContainsString('casino', strtolower((string) $submission->fresh()->extracted_text));
        $this->assertTrue($submission->fresh()->isApproved());

        $check = app(ContentModerationService::class)->assertSubmissionsApproved([$submission->fresh()], $advertiser);
        $this->assertFalse($check['ok']);
        $this->assertSame(ContentSubmission::STATUS_REJECTED, $submission->fresh()->moderation_status);
    }

    public function test_casino_only_in_docx_header_fails_live_policy(): void
    {
        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);
        config(['content_moderation.enabled' => true]);
        ContentModerationSetting::clearCache();

        $this->writeDocxWithHeader(
            Storage::disk('local')->path($submission->path),
            (string) $submission->extracted_text,
            'Best online casino bonus for this issue'
        );
        $this->assertTrue($submission->fresh()->isApproved());

        $check = app(ContentModerationService::class)->assertSubmissionsApproved([$submission->fresh()], $advertiser);
        $this->assertFalse($check['ok']);
        $this->assertSame(ContentSubmission::STATUS_REJECTED, $submission->fresh()->moderation_status);
    }

    public function test_gambling_url_only_in_stored_docx_fails_live_policy(): void
    {
        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);
        config(['content_moderation.enabled' => true]);
        ContentModerationSetting::clearCache();

        $this->writeDocxWithHeader(
            Storage::disk('local')->path($submission->path),
            (string) $submission->extracted_text,
            'click here',
            'https://www.bet365.com/en/sports'
        );
        $this->assertTrue($submission->fresh()->isApproved());

        $check = app(ContentModerationService::class)->assertSubmissionsApproved([$submission->fresh()], $advertiser);
        $this->assertFalse($check['ok']);
        $this->assertSame(ContentSubmission::STATUS_REJECTED, $submission->fresh()->moderation_status);
    }

    public function test_zero_width_casino_in_preview_fails_live_policy(): void
    {
        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);
        config(['content_moderation.enabled' => true]);
        ContentModerationSetting::clearCache();

        $hidden = 'Play at the best online cas'."\u{200B}".'ino and claim your bonus.';
        $submission->update([
            'extracted_text' => $hidden,
            'preview_html' => '<p>'.$hidden.'</p>',
        ]);
        $this->assertTrue($submission->fresh()->isApproved());

        $check = app(ContentModerationService::class)->assertSubmissionsApproved([$submission->fresh()], $advertiser);
        $this->assertFalse($check['ok']);
        $this->assertSame(ContentSubmission::STATUS_REJECTED, $submission->fresh()->moderation_status);
    }

    public function test_silent_docx_swap_after_override_revokes_the_pass(): void
    {
        $admin = $this->admin();
        $advertiser = $this->advertiser();
        [$submission, $log] = $this->rejectCasinoArticle($advertiser);

        $this->actingAs($admin)
            ->post(route('admin.moderation.override', $log), [
                'notes' => 'Allow this version only.',
            ])
            ->assertRedirect();

        $check = app(ContentModerationService::class)->assertSubmissionsApproved([$submission->fresh()], $advertiser);
        $this->assertTrue($check['ok'], json_encode($check['failures']));

        $this->makeDocxFile(
            Storage::disk('local')->path($submission->path),
            'Updated file: play at the best online casino tonight.'
        );

        $check = app(ContentModerationService::class)->assertSubmissionsApproved([$submission->fresh()], $advertiser);
        $this->assertFalse($check['ok']);
        $this->assertSame(ContentSubmission::STATUS_REJECTED, $submission->fresh()->moderation_status);
    }

    public function test_casino_only_in_target_url_path_fails_live_policy(): void
    {
        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);
        config(['content_moderation.enabled' => true]);
        ContentModerationSetting::clearCache();

        $submission->update(['target_url' => 'https://example.com/best-online-casino-bonus']);
        $this->assertTrue($submission->fresh()->isApproved());

        $check = app(ContentModerationService::class)->assertSubmissionsApproved([$submission->fresh()], $advertiser);
        $this->assertFalse($check['ok']);
        $this->assertSame(ContentSubmission::STATUS_REJECTED, $submission->fresh()->moderation_status);
    }

    public function test_percent_encoded_casino_in_target_url_fails_live_policy(): void
    {
        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);
        config(['content_moderation.enabled' => true]);
        ContentModerationSetting::clearCache();

        $submission->update(['target_url' => 'https://example.com/best-online-cas%69no-bonus']);
        $this->assertTrue($submission->fresh()->isApproved());

        $check = app(ContentModerationService::class)->assertSubmissionsApproved([$submission->fresh()], $advertiser);
        $this->assertFalse($check['ok']);
        $this->assertSame(ContentSubmission::STATUS_REJECTED, $submission->fresh()->moderation_status);
    }

    public function test_percent_encoded_casino_in_image_src_fails_live_policy(): void
    {
        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);
        config(['content_moderation.enabled' => true]);
        ContentModerationSetting::clearCache();

        $body = (string) $submission->extracted_text;
        $submission->update([
            'preview_html' => '<p>'.$body.'</p><img src="/storage/best-online-cas%69no-bonus.jpg" alt="hero">',
        ]);
        $this->assertTrue($submission->fresh()->isApproved());

        $check = app(ContentModerationService::class)->assertSubmissionsApproved([$submission->fresh()], $advertiser);
        $this->assertFalse($check['ok']);
        $this->assertSame(ContentSubmission::STATUS_REJECTED, $submission->fresh()->moderation_status);
    }

    public function test_casino_only_in_feature_image_url_fails_live_policy(): void
    {
        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);
        config(['content_moderation.enabled' => true]);
        ContentModerationSetting::clearCache();

        $submission->update(['feature_image_url' => 'https://cdn.example.com/best-online-casino-bonus.jpg']);
        $this->assertTrue($submission->fresh()->isApproved());

        $check = app(ContentModerationService::class)->assertSubmissionsApproved([$submission->fresh()], $advertiser);
        $this->assertFalse($check['ok']);
        $this->assertSame(ContentSubmission::STATUS_REJECTED, $submission->fresh()->moderation_status);
    }

    public function test_casino_only_in_original_filename_fails_live_policy(): void
    {
        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);
        config(['content_moderation.enabled' => true]);
        ContentModerationSetting::clearCache();

        $submission->update(['original_filename' => 'Best-online-casino-bonus.docx']);
        $this->assertSame('Test Article', $submission->fresh()->title);
        $this->assertTrue($submission->fresh()->isApproved());

        $check = app(ContentModerationService::class)->assertSubmissionsApproved([$submission->fresh()], $advertiser);
        $this->assertFalse($check['ok']);
        $this->assertSame(ContentSubmission::STATUS_REJECTED, $submission->fresh()->moderation_status);
    }

    public function test_casino_only_in_image_src_path_fails_live_policy(): void
    {
        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);
        config(['content_moderation.enabled' => true]);
        ContentModerationSetting::clearCache();

        $body = (string) $submission->extracted_text;
        $submission->update([
            'preview_html' => '<p>'.$body.'</p><img src="/storage/best-online-casino-bonus.jpg" alt="hero">',
        ]);
        $this->assertTrue($submission->fresh()->isApproved());

        $check = app(ContentModerationService::class)->assertSubmissionsApproved([$submission->fresh()], $advertiser);
        $this->assertFalse($check['ok']);
        $this->assertSame(ContentSubmission::STATUS_REJECTED, $submission->fresh()->moderation_status);
    }

    public function test_casino_only_in_docx_properties_fails_live_policy(): void
    {
        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);
        config(['content_moderation.enabled' => true]);
        ContentModerationSetting::clearCache();

        $this->writeDocxWithCoreTitle(
            Storage::disk('local')->path($submission->path),
            (string) $submission->extracted_text,
            'Best online casino bonus'
        );
        $this->assertTrue($submission->fresh()->isApproved());

        $check = app(ContentModerationService::class)->assertSubmissionsApproved([$submission->fresh()], $advertiser);
        $this->assertFalse($check['ok']);
        $this->assertSame(ContentSubmission::STATUS_REJECTED, $submission->fresh()->moderation_status);
    }

    public function test_casino_only_in_docx_image_descr_fails_live_policy(): void
    {
        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);
        config(['content_moderation.enabled' => true]);
        ContentModerationSetting::clearCache();

        $this->writeDocxWithImageDescr(
            Storage::disk('local')->path($submission->path),
            (string) $submission->extracted_text,
            'Best online casino bonus'
        );
        $this->assertTrue($submission->fresh()->isApproved());

        $check = app(ContentModerationService::class)->assertSubmissionsApproved([$submission->fresh()], $advertiser);
        $this->assertFalse($check['ok']);
        $this->assertSame(ContentSubmission::STATUS_REJECTED, $submission->fresh()->moderation_status);
    }

    public function test_casino_only_in_image_srcset_fails_live_policy(): void
    {
        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);
        config(['content_moderation.enabled' => true]);
        ContentModerationSetting::clearCache();

        $body = (string) $submission->extracted_text;
        $submission->update([
            'preview_html' => '<p>'.$body.'</p><img src="/storage/hero.jpg" srcset="/storage/best-online-casino-bonus.jpg 1x" alt="hero">',
        ]);
        $this->assertTrue($submission->fresh()->isApproved());

        $check = app(ContentModerationService::class)->assertSubmissionsApproved([$submission->fresh()], $advertiser);
        $this->assertFalse($check['ok']);
        $this->assertSame(ContentSubmission::STATUS_REJECTED, $submission->fresh()->moderation_status);
    }

    public function test_leet_casino_in_preview_fails_live_policy(): void
    {
        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);
        config(['content_moderation.enabled' => true]);
        ContentModerationSetting::clearCache();

        $hidden = 'Play at the best online casin0 tonight and claim your bonus.';
        $submission->update([
            'extracted_text' => $hidden,
            'preview_html' => '<p>'.$hidden.'</p>',
        ]);
        $this->assertTrue($submission->fresh()->isApproved());

        $check = app(ContentModerationService::class)->assertSubmissionsApproved([$submission->fresh()], $advertiser);
        $this->assertFalse($check['ok']);
        $this->assertSame(ContentSubmission::STATUS_REJECTED, $submission->fresh()->moderation_status);
    }

    public function test_casino_only_in_css_background_url_fails_live_policy(): void
    {
        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);
        config(['content_moderation.enabled' => true]);
        ContentModerationSetting::clearCache();

        $body = (string) $submission->extracted_text;
        $submission->update([
            'preview_html' => '<p>'.$body.'</p><div style="background-image:url(/storage/best-online-casino-bonus.jpg)">cover</div>',
        ]);
        $this->assertTrue($submission->fresh()->isApproved());

        $check = app(ContentModerationService::class)->assertSubmissionsApproved([$submission->fresh()], $advertiser);
        $this->assertFalse($check['ok']);
        $this->assertSame(ContentSubmission::STATUS_REJECTED, $submission->fresh()->moderation_status);
    }

    public function test_casino_only_in_docx_media_filename_fails_live_policy(): void
    {
        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);
        config(['content_moderation.enabled' => true]);
        ContentModerationSetting::clearCache();

        $this->writeDocxWithNamedImage(
            Storage::disk('local')->path($submission->path),
            (string) $submission->extracted_text,
            'best-online-casino-bonus.png'
        );
        $this->assertTrue($submission->fresh()->isApproved());

        $check = app(ContentModerationService::class)->assertSubmissionsApproved([$submission->fresh()], $advertiser);
        $this->assertFalse($check['ok']);
        $this->assertSame(ContentSubmission::STATUS_REJECTED, $submission->fresh()->moderation_status);
    }

    public function test_combining_mark_casino_in_preview_fails_live_policy(): void
    {
        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);
        config(['content_moderation.enabled' => true]);
        ContentModerationSetting::clearCache();

        $hidden = 'Play at the best online c'."\u{0338}".'a'."\u{0338}".'s'."\u{0338}".'i'."\u{0338}".'n'."\u{0338}".'o tonight.';
        $submission->update([
            'extracted_text' => $hidden,
            'preview_html' => '<p>'.$hidden.'</p>',
        ]);
        $this->assertTrue($submission->fresh()->isApproved());

        $check = app(ContentModerationService::class)->assertSubmissionsApproved([$submission->fresh()], $advertiser);
        $this->assertFalse($check['ok']);
        $this->assertSame(ContentSubmission::STATUS_REJECTED, $submission->fresh()->moderation_status);
    }

    public function test_casino_past_first_score_window_fails_live_policy(): void
    {
        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);
        config(['content_moderation.enabled' => true]);
        ContentModerationSetting::clearCache();

        $pad = str_repeat('Useful editorial copy about software teams. ', 5500);
        $this->assertGreaterThan(ContentModerationEngine::SCORE_TEXT_CHARS, mb_strlen($pad));
        $hidden = $pad.'Play at the best online casino tonight and claim your bonus.';
        $submission->update([
            'extracted_text' => $hidden,
            'preview_html' => '<p>'.$hidden.'</p>',
        ]);
        $this->assertTrue($submission->fresh()->isApproved());

        $check = app(ContentModerationService::class)->assertSubmissionsApproved([$submission->fresh()], $advertiser);
        $this->assertFalse($check['ok']);
        $this->assertSame(ContentSubmission::STATUS_REJECTED, $submission->fresh()->moderation_status);
    }

    public function test_casino_only_in_docx_svg_text_fails_live_policy(): void
    {
        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);
        config(['content_moderation.enabled' => true]);
        ContentModerationSetting::clearCache();

        $this->writeDocxWithSvg(
            Storage::disk('local')->path($submission->path),
            (string) $submission->extracted_text,
            '<svg xmlns="http://www.w3.org/2000/svg"><text>Best online casino bonus</text></svg>'
        );
        $this->assertTrue($submission->fresh()->isApproved());

        $check = app(ContentModerationService::class)->assertSubmissionsApproved([$submission->fresh()], $advertiser);
        $this->assertFalse($check['ok']);
        $this->assertSame(ContentSubmission::STATUS_REJECTED, $submission->fresh()->moderation_status);
    }

    public function test_casino_only_in_docx_embedding_filename_fails_live_policy(): void
    {
        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);
        config(['content_moderation.enabled' => true]);
        ContentModerationSetting::clearCache();

        $this->writeDocxWithEmbedding(
            Storage::disk('local')->path($submission->path),
            (string) $submission->extracted_text,
            'best-online-casino-offer.xlsx'
        );
        $this->assertTrue($submission->fresh()->isApproved());

        $check = app(ContentModerationService::class)->assertSubmissionsApproved([$submission->fresh()], $advertiser);
        $this->assertFalse($check['ok']);
        $this->assertSame(ContentSubmission::STATUS_REJECTED, $submission->fresh()->moderation_status);
    }

    public function test_feature_image_draft_save_after_override_revokes_approval(): void
    {
        $admin = $this->admin();
        $advertiser = $this->advertiser();
        [$submission, $log] = $this->rejectCasinoArticle($advertiser);

        $this->actingAs($admin)
            ->post(route('admin.moderation.override', $log), [
                'notes' => 'Allow this version only.',
            ])
            ->assertRedirect();

        $this->actingAs($advertiser)
            ->patchJson(route('advertiser.content-submissions.update', $submission), [
                'feature_image_url' => 'https://cdn.example.com/best-online-casino-bonus.jpg',
            ])
            ->assertOk()
            ->assertJsonPath('approved', false);

        $this->assertSame(ContentSubmission::STATUS_REJECTED, $submission->fresh()->moderation_status);
    }

    public function test_admin_mark_paid_blocks_a_silent_title_edit_and_keeps_the_reject(): void
    {
        $admin = $this->admin();
        $advertiser = $this->advertiser();
        [$submission, $log] = $this->rejectCasinoArticle($advertiser);

        $this->actingAs($admin)
            ->post(route('admin.moderation.override', $log), [
                'notes' => 'Allow this version only.',
            ])
            ->assertRedirect();

        $site = $this->publisherSite();
        $order = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => 'ORD-OVR-MARKPAID',
            'reference_code' => 'REF-OVR-MARKPAID',
            'subtotal' => 40,
            'tax' => 0,
            'total_amount' => 40,
            'payment_method' => 'wise',
            'payment_status' => 'pending',
            'status' => 'pending',
        ]);
        $item = OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'price' => 40,
            'content_link' => route('advertiser.content-submissions.download', $submission),
            'content_submission_id' => $submission->id,
            'content_path' => $submission->path,
            'content_original_name' => $submission->original_filename,
        ]);
        $submission->update([
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'title' => 'Best online casino bonus guide',
        ]);
        $this->assertTrue($submission->fresh()->isApproved());

        $this->actingAs($admin)
            ->postJson(route('admin.payments.updateStatus', $order->id), [
                'payment_status' => 'paid',
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertSame('pending', $order->fresh()->payment_status);
        $this->assertNull($order->fresh()->paid_at);
        $this->assertSame(ContentSubmission::STATUS_REJECTED, $submission->fresh()->moderation_status);
    }

    private function publisherSite(): Site
    {
        $publisherRole = Role::firstOrCreate(['name' => 'publisher']);
        $publisher = User::factory()->create(['email_verified_at' => now()]);
        $publisher->roles()->attach($publisherRole->id);

        return Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Override Policy Site',
            'site_url' => 'https://override-policy.example',
            'domain' => 'override-policy.example',
            'da' => 20,
            'dr' => 20,
            'traffic' => 100,
            'country' => 'us',
            'language' => 'en',
            'price' => 40,
            'publication_time' => '7 days',
            'link_type' => 'dofollow',
            'description' => 'Policy recheck site',
            'verified' => true,
            'active' => true,
        ]);
    }

    private function paidLibraryItem(User $advertiser, ContentSubmission $submission): OrderItem
    {
        $site = $this->publisherSite();
        $order = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => 'ORD-OVR-REV-'.random_int(1000, 9999),
            'reference_code' => 'REF-OVR-REV-'.random_int(1000, 9999),
            'subtotal' => 40,
            'tax' => 0,
            'total_amount' => 40,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => 'processing',
            'paid_at' => now(),
        ]);
        $item = OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'price' => 40,
            'content_link' => route('advertiser.content-submissions.download', $submission),
            'content_submission_id' => $submission->id,
            'content_disk' => $submission->disk,
            'content_path' => $submission->path,
            'content_original_name' => $submission->original_filename,
            'accepted_at' => now(),
            'publisher_status' => 'accepted',
            'content_revision_requested' => 'yes',
            'content_revision_requested_at' => now(),
            'content_revision_reason' => 'Please tighten the intro and fix brand spelling.',
        ]);
        $submission->update([
            'order_id' => $order->id,
            'order_item_id' => $item->id,
        ]);

        return $item->fresh();
    }

    private function writeDocxWithHeader(string $absolutePath, string $body, string $header, ?string $headerUrl = null): void
    {
        $dir = dirname($absolutePath);
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $zip = new ZipArchive;
        $zip->open($absolutePath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            .'<Override PartName="/word/header1.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.header+xml"/>'
            .'</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
            .'</Relationships>');
        $headerXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<w:hdr xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" '
            .'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><w:p>';
        if ($headerUrl) {
            $zip->addFromString('word/_rels/header1.xml.rels', '<?xml version="1.0"?>'
                .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .'<Relationship Id="rIdH1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink" '
                .'Target="'.htmlspecialchars($headerUrl, ENT_XML1).'" TargetMode="External"/>'
                .'</Relationships>');
            $headerXml .= '<w:hyperlink r:id="rIdH1"><w:r><w:t>'.htmlspecialchars($header, ENT_XML1).'</w:t></w:r></w:hyperlink>';
        } else {
            $headerXml .= '<w:r><w:t>'.htmlspecialchars($header, ENT_XML1).'</w:t></w:r>';
        }
        $headerXml .= '</w:p></w:hdr>';
        $zip->addFromString('word/header1.xml', $headerXml);
        $zip->addFromString('word/document.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'
            .'<w:p><w:r><w:t>'.htmlspecialchars($body, ENT_XML1).'</w:t></w:r></w:p>'
            .'</w:body></w:document>');
        $zip->close();
    }

    private function writeDocxWithCoreTitle(string $absolutePath, string $body, string $coreTitle): void
    {
        $dir = dirname($absolutePath);
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $zip = new ZipArchive;
        $zip->open($absolutePath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            .'<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            .'</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
            .'</Relationships>');
        $zip->addFromString('docProps/core.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" '
            .'xmlns:dc="http://purl.org/dc/elements/1.1/">'
            .'<dc:title>'.htmlspecialchars($coreTitle, ENT_XML1).'</dc:title>'
            .'</cp:coreProperties>');
        $zip->addFromString('word/document.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'
            .'<w:p><w:r><w:t>'.htmlspecialchars($body, ENT_XML1).'</w:t></w:r></w:p>'
            .'</w:body></w:document>');
        $zip->close();
    }

    private function writeDocxWithImageDescr(string $absolutePath, string $body, string $descr): void
    {
        $dir = dirname($absolutePath);
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $zip = new ZipArchive;
        $zip->open($absolutePath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            .'</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
            .'</Relationships>');
        $zip->addFromString('word/document.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" '
            .'xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing"><w:body>'
            .'<w:p><w:r><w:t>'.htmlspecialchars($body, ENT_XML1).'</w:t></w:r></w:p>'
            .'<w:p><w:r><w:drawing><wp:inline><wp:docPr id="1" name="Picture 1" descr="'
            .htmlspecialchars($descr, ENT_XML1).'"/></wp:inline></w:drawing></w:r></w:p>'
            .'</w:body></w:document>');
        $zip->close();
    }

    private function writeDocxWithNamedImage(string $absolutePath, string $body, string $imageName): void
    {
        $dir = dirname($absolutePath);
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        $zip = new ZipArchive;
        $zip->open($absolutePath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Default Extension="png" ContentType="image/png"/>'
            .'<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            .'</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
            .'</Relationships>');
        $zip->addFromString('word/media/'.$imageName, $png);
        $zip->addFromString('word/document.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'
            .'<w:p><w:r><w:t>'.htmlspecialchars($body, ENT_XML1).'</w:t></w:r></w:p>'
            .'</w:body></w:document>');
        $zip->close();
    }

    private function writeDocxWithSvg(string $absolutePath, string $body, string $svg): void
    {
        $dir = dirname($absolutePath);
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $zip = new ZipArchive;
        $zip->open($absolutePath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Default Extension="svg" ContentType="image/svg+xml"/>'
            .'<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            .'</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
            .'</Relationships>');
        $zip->addFromString('word/media/chart.svg', $svg);
        $zip->addFromString('word/document.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'
            .'<w:p><w:r><w:t>'.htmlspecialchars($body, ENT_XML1).'</w:t></w:r></w:p>'
            .'</w:body></w:document>');
        $zip->close();
    }

    private function writeDocxWithEmbedding(string $absolutePath, string $body, string $embeddingName): void
    {
        $dir = dirname($absolutePath);
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $zip = new ZipArchive;
        $zip->open($absolutePath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Default Extension="xlsx" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"/>'
            .'<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            .'</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
            .'</Relationships>');
        $zip->addFromString('word/embeddings/'.$embeddingName, 'not-a-real-workbook');
        $zip->addFromString('word/document.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'
            .'<w:p><w:r><w:t>'.htmlspecialchars($body, ENT_XML1).'</w:t></w:r></w:p>'
            .'</w:body></w:document>');
        $zip->close();
    }
}
