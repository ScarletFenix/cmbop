<?php

namespace Tests\Feature;

use App\Models\ContentSubmission;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\Advertiser\ContentLibrarySearchQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CreatesContentSubmissions;
use Tests\TestCase;

class ContentLibraryNextGapsTest extends TestCase
{
    use CreatesContentSubmissions;
    use RefreshDatabase;

    private function advertiser(): User
    {
        $role = Role::firstOrCreate(['name' => 'advertiser']);
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->roles()->attach($role->id);
        $user->active_role_id = $role->id;
        $user->save();

        return $user->fresh();
    }

    private function siteFor(User $publisher): Site
    {
        return Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Gaps Site',
            'site_url' => 'https://gaps-site.example',
            'domain' => 'gaps-site.example',
            'da' => 30,
            'dr' => 30,
            'traffic' => 500,
            'country' => 'us',
            'language' => 'en',
            'countries' => ['us'],
            'languages' => ['en'],
            'category' => 'marketing',
            'price' => 40,
            'publication_time' => '7 days',
            'link_type' => 'dofollow',
            'description' => 'Test site',
            'verified' => true,
            'active' => true,
        ]);
    }

    private function publisher(): User
    {
        $role = Role::firstOrCreate(['name' => 'publisher']);
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    private function paidOrder(User $advertiser, ContentSubmission $submission, Site $site, bool $live = false): Order
    {
        $order = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => 'ORD-GAPS-'.uniqid(),
            'reference_code' => 'REF-GAPS-'.uniqid(),
            'subtotal' => 40,
            'tax' => 0,
            'total_amount' => 40,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => $live ? 'processing' : 'pending',
        ]);

        $attrs = [
            'order_id' => $order->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'price' => 40,
            'content_link' => 'https://example.com/article.docx',
            'content_submission_id' => $submission->id,
        ];
        if ($live) {
            $attrs['live_url'] = 'https://live.example/gaps-post';
            $attrs['live_url_submitted_at'] = now();
        }

        $item = OrderItem::create($attrs);
        $submission->update([
            'order_id' => $order->id,
            'order_item_id' => $item->id,
        ]);

        return $order;
    }

    public function test_duplicate_unused_row_is_orderable_and_leaves_source(): void
    {
        $advertiser = $this->advertiser();
        $source = $this->createApprovedSubmission($advertiser);
        $source->update(['title' => 'Source Playbook']);
        $sourcePath = $source->path;

        $response = $this->actingAs($advertiser)
            ->postJson(route('advertiser.content-library.duplicate', $source), [])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonMissing(['SQLSTATE']);

        $cloneId = (int) $response->json('submission.id');
        $this->assertNotSame($source->id, $cloneId);

        $clone = ContentSubmission::query()->findOrFail($cloneId);
        $source = $source->fresh();

        $this->assertSame('Source Playbook (copy)', $clone->title);
        $this->assertSame($advertiser->id, $clone->user_id);
        $this->assertNull($clone->order_id);
        $this->assertNull($clone->order_item_id);
        $this->assertNull($clone->site_id);
        $this->assertNull($clone->archived_at);
        $this->assertTrue($clone->canOrderFromLibrary());
        $this->assertTrue($source->canOrderFromLibrary());
        $this->assertSame('Source Playbook', $source->title);
        $this->assertSame($sourcePath, $source->path);
        $this->assertNotSame($sourcePath, $clone->path);
        $this->assertTrue(Storage::disk('local')->exists($clone->path));
        $this->assertTrue($clone->expires_at?->isFuture() ?? false);
    }

    public function test_duplicate_from_paid_lock_creates_unused_clone(): void
    {
        $advertiser = $this->advertiser();
        $source = $this->createApprovedSubmission($advertiser);
        $source->update(['title' => 'Locked Playbook']);
        $this->paidOrder($advertiser, $source, $this->siteFor($this->publisher()));
        $source = $source->fresh();
        $this->assertTrue($source->isLockedByPaidOrder());

        $response = $this->actingAs($advertiser)
            ->postJson(route('advertiser.content-library.duplicate', $source))
            ->assertOk()
            ->assertJsonPath('success', true);

        $clone = ContentSubmission::query()->findOrFail((int) $response->json('submission.id'));
        $source = $source->fresh();

        $this->assertTrue($source->isLockedByPaidOrder());
        $this->assertFalse($source->canOrderFromLibrary());
        $this->assertNull($clone->order_id);
        $this->assertFalse($clone->isLockedByPaidOrder());
        $this->assertTrue($clone->canOrderFromLibrary());
    }

    public function test_duplicate_from_completed_live_is_rejected(): void
    {
        $advertiser = $this->advertiser();
        $source = $this->createApprovedSubmission($advertiser);
        $source->update(['title' => 'Live Playbook']);
        $this->paidOrder($advertiser, $source, $this->siteFor($this->publisher()), true);
        $source = $source->fresh();
        $this->assertTrue($source->isPublished());
        $this->assertFalse($source->canDuplicateForLibrary());

        $before = ContentSubmission::query()->count();
        $this->actingAs($advertiser)
            ->postJson(route('advertiser.content-library.duplicate', $source))
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonMissing(['SQLSTATE']);

        $this->assertSame($before, ContentSubmission::query()->count());
    }

    public function test_duplicate_expired_without_file_keeps_preview(): void
    {
        $advertiser = $this->advertiser();
        $source = $this->createApprovedSubmission($advertiser);
        $oldPath = $source->path;
        Storage::disk('local')->delete($oldPath);
        $source->update([
            'title' => 'Expired Playbook',
            'path' => '',
            'size_bytes' => 0,
            'expires_at' => now()->subDay(),
            'preview_html' => '<p>Expired preview body stays.</p>',
            'extracted_text' => 'Expired preview body stays.',
        ]);

        $response = $this->actingAs($advertiser)
            ->postJson(route('advertiser.content-library.duplicate', $source))
            ->assertOk()
            ->assertJsonPath('success', true);

        $clone = ContentSubmission::query()->findOrFail((int) $response->json('submission.id'));
        $this->assertSame('<p>Expired preview body stays.</p>', $clone->preview_html);
        $this->assertTrue($clone->expires_at?->isFuture() ?? false);
        $this->assertFalse(filled($clone->path));
    }

    public function test_duplicate_rejects_other_users_article(): void
    {
        $owner = $this->advertiser();
        $stranger = $this->advertiser();
        $source = $this->createApprovedSubmission($owner);

        $this->actingAs($stranger)
            ->postJson(route('advertiser.content-library.duplicate', $source))
            ->assertForbidden();
    }

    public function test_duplicate_can_change_market(): void
    {
        config(['content_moderation.enabled' => false]);
        $advertiser = $this->advertiser();
        $source = $this->createApprovedSubmission($advertiser, null, 0, 'best software tools', 'https://example.com/tools', 'us', 'en');
        $source->update(['title' => 'Market Playbook']);

        $response = $this->actingAs($advertiser)
            ->postJson(route('advertiser.content-library.duplicate', $source), [
                'country' => 'uk',
                'language' => 'en',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $clone = ContentSubmission::query()->findOrFail((int) $response->json('submission.id'));
        $this->assertSame('uk', $clone->country);
        $this->assertSame('en', $clone->language);
        $this->assertSame('us', $source->fresh()->country);
    }

    public function test_duplicate_leftover_dropped_table_is_json_not_sql(): void
    {
        $advertiser = $this->advertiser();
        Schema::dropIfExists('content_submissions');

        $this->actingAs($advertiser)
            ->postJson('/advertiser/content-library/1/duplicate')
            ->assertStatus(500)
            ->assertJsonPath('success', false)
            ->assertJsonMissing(['SQLSTATE'])
            ->assertDontSee('SQLSTATE');
    }

    public function test_library_page_exposes_multi_docx_upload(): void
    {
        $advertiser = $this->advertiser();
        $this->createApprovedSubmission($advertiser)->update(['title' => 'Upload Harness Piece']);

        $html = $this->actingAs($advertiser)
            ->get(route('advertiser.content-library'))
            ->assertOk()
            ->assertSee('Duplicate', false)
            ->getContent();

        $this->assertMatchesRegularExpression('/id="libraryFileInput"[^>]*\bmultiple\b/', $html);
        $this->assertStringContainsString('multiUploadLimit', $html);

        $js = (string) file_get_contents(public_path('assets/js/content-library.js'));
        $this->assertStringContainsString('libraryMultiUploadLimit', $js);
        $this->assertStringContainsString('assignLibraryFiles', $js);
        $this->assertStringContainsString('replace_id', $js);
    }

    public function test_search_matches_target_url_and_body(): void
    {
        $advertiser = $this->advertiser();
        $byUrl = $this->createApprovedSubmission($advertiser);
        $byUrl->update([
            'title' => 'Alpha Filename Piece',
            'original_filename' => 'alpha.docx',
            'target_url' => 'https://brand-only-url.example/landing',
            'extracted_text' => 'Plain marketing copy without the secret token.',
        ]);
        $byBody = $this->createApprovedSubmission($advertiser);
        $byBody->update([
            'title' => 'Beta Filename Piece',
            'original_filename' => 'beta.docx',
            'target_url' => 'https://other.example/page',
            'extracted_text' => 'Body mentions zebrauniquephrase in the middle.',
        ]);
        $neither = $this->createApprovedSubmission($advertiser);
        $neither->update([
            'title' => 'Gamma Filename Piece',
            'original_filename' => 'gamma.docx',
            'target_url' => 'https://unrelated.example/page',
            'extracted_text' => 'Nothing special here.',
        ]);

        $this->actingAs($advertiser)
            ->get(route('advertiser.content-library', ['q' => 'brand-only-url']))
            ->assertOk()
            ->assertSee('Alpha Filename Piece')
            ->assertDontSee('Beta Filename Piece')
            ->assertDontSee('Gamma Filename Piece');

        $this->actingAs($advertiser)
            ->get(route('advertiser.content-library', ['q' => 'zebrauniquephrase']))
            ->assertOk()
            ->assertSee('Beta Filename Piece')
            ->assertDontSee('Alpha Filename Piece')
            ->assertDontSee('Gamma Filename Piece');

        $this->actingAs($advertiser)
            ->get(route('advertiser.content-library', ['q' => 'zebrauniquephrase missingword']))
            ->assertOk()
            ->assertDontSee('Beta Filename Piece');
    }

    public function test_search_query_service_covers_url_and_body(): void
    {
        $search = app(ContentLibrarySearchQuery::class);
        $query = ContentSubmission::query();
        $search->apply($query, 'brand-only-url');

        $sql = strtolower($query->toSql());
        $this->assertStringContainsString('target_url', $sql);
        $this->assertStringContainsString('extracted_text', $sql);
        $this->assertStringContainsString('title', $sql);
        $this->assertStringContainsString('original_filename', $sql);
    }

    public function test_sort_by_uniqueness_and_min_quality_filter(): void
    {
        $advertiser = $this->advertiser();
        $high = $this->createApprovedSubmission($advertiser);
        $high->update([
            'title' => 'High Unique Piece',
            'uniqueness_score' => 95,
            'quality_score' => 40,
        ]);
        $mid = $this->createApprovedSubmission($advertiser);
        $mid->update([
            'title' => 'Mid Unique Piece',
            'uniqueness_score' => 60,
            'quality_score' => 88,
        ]);
        $low = $this->createApprovedSubmission($advertiser);
        $low->update([
            'title' => 'Low Unique Piece',
            'uniqueness_score' => 20,
            'quality_score' => 30,
        ]);

        $html = $this->actingAs($advertiser)
            ->get(route('advertiser.content-library', [
                'status' => 'all',
                'availability' => 'all',
                'sort' => 'uniqueness',
            ]))
            ->assertOk()
            ->assertSee('High Unique Piece')
            ->assertSee('value="uniqueness"', false)
            ->getContent();

        $highPos = strpos($html, 'High Unique Piece');
        $midPos = strpos($html, 'Mid Unique Piece');
        $lowPos = strpos($html, 'Low Unique Piece');
        $this->assertNotFalse($highPos);
        $this->assertNotFalse($midPos);
        $this->assertNotFalse($lowPos);
        $this->assertLessThan($midPos, $highPos);
        $this->assertLessThan($lowPos, $midPos);

        $this->actingAs($advertiser)
            ->get(route('advertiser.content-library', [
                'status' => 'all',
                'availability' => 'all',
                'min_quality' => 80,
            ]))
            ->assertOk()
            ->assertSee('Mid Unique Piece')
            ->assertDontSee('High Unique Piece')
            ->assertDontSee('Low Unique Piece');

        $this->actingAs($advertiser)
            ->get(route('advertiser.content-library', [
                'status' => 'all',
                'availability' => 'all',
                'sort' => 'not-a-sort',
            ]))
            ->assertOk()
            ->assertSee('value="latest"', false);
    }

    public function test_search_apply_does_not_throw_on_empty_builder(): void
    {
        $search = app(ContentLibrarySearchQuery::class);
        $query = ContentSubmission::query();
        $search->apply($query, '');
        $this->assertInstanceOf(Builder::class, $query);

        $blocked = ContentSubmission::query();
        $search->apply($blocked, '%_%');
        $this->assertStringContainsString('0 = 1', $blocked->toSql());
    }
}
