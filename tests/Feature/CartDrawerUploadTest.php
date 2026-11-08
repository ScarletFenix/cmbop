<?php

namespace Tests\Feature;

use App\Models\ContentSubmission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\ContentUpload\ContentUploadService;
use App\Services\Marketplace\CheckoutAddOnService;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CreatesContentSubmissions;
use Tests\TestCase;

class CartDrawerUploadTest extends TestCase
{
    use CreatesContentSubmissions;
    use RefreshDatabase;

    private User $advertiser;

    private User $publisher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
        config(['content_moderation.enabled' => false]);
        Mail::fake();
        Storage::fake('local');

        $advertiserRole = Role::where('name', 'advertiser')->firstOrFail();
        $publisherRole = Role::where('name', 'publisher')->firstOrFail();

        $this->advertiser = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $advertiserRole->id,
        ]);
        $this->advertiser->roles()->attach($advertiserRole->id);

        $this->publisher = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $publisherRole->id,
        ]);
        $this->publisher->roles()->attach($publisherRole->id);
    }

    private function makeSite(string $slug = 'upload-cart', array $overrides = []): Site
    {
        return Site::create(array_merge([
            'publisher_id' => $this->publisher->id,
            'site_name' => 'Upload Cart '.$slug,
            'site_url' => 'https://'.$slug.'.example',
            'domain' => $slug.'.example',
            'da' => 40,
            'dr' => 45,
            'traffic' => 8000,
            'country' => 'us',
            'language' => 'en',
            'countries' => ['us'],
            'languages' => ['en'],
            'category' => 'marketing',
            'price' => 100,
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => 'Cart drawer upload listing.',
            'verified' => true,
            'active' => true,
        ], $overrides));
    }

    private function addToCart(Site $site, array $extra = []): array
    {
        return $this->actingAs($this->advertiser)
            ->postJson(route('advertiser.cart.add'), array_merge([
                'id' => $site->id,
                'homepage_days' => 'none',
            ], $extra))
            ->assertOk()
            ->json();
    }

    private function docxUpload(string $name = 'guest-post.docx', ?string $text = null): UploadedFile
    {
        $path = sys_get_temp_dir().'/cart-upload-'.uniqid('', true).'.docx';
        $this->makeDocxFile(
            $path,
            $text ?? str_repeat('Useful editorial content about productivity software for busy teams. ', 60)
        );

        return new UploadedFile(
            $path,
            $name,
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            null,
            true
        );
    }

    public function test_drawer_opens_a_docx_picker_instead_of_redirecting(): void
    {
        $layout = (string) file_get_contents(resource_path('views/advertiser/layouts/app.blade.php'));

        $this->assertStringContainsString('id="cartArticleFileInput"', $layout);
        $this->assertStringContainsString('accept=".docx,application/vnd.openxmlformats-officedocument.wordprocessingml.document"', $layout);
        $this->assertStringContainsString('data-cart-dropzone', $layout);
        $this->assertStringContainsString('function openCartArticlePicker', $layout);
        $this->assertStringContainsString("getElementById('cartArticleFileInput')", $layout);
        $this->assertStringContainsString('input.click()', $layout);
        $this->assertStringContainsString('function uploadCartArticleFile', $layout);
        $this->assertStringContainsString('delete cartUploadState[cartUploadStateKey(assigned, copyIndex || 0)]', $layout);
        $this->assertStringContainsString('cartUploadChunkBytes', $layout);
        $this->assertStringContainsString('ContentUploadService::CHUNK_KILOBYTES', $layout);
        $this->assertStringContainsString('ContentUploadService::MAX_KILOBYTES', $layout);
        $this->assertStringContainsString("'article.docx'", $layout);
        $this->assertStringContainsString('X-Upload-Bytes', $layout);
        $this->assertStringContainsString('That file is over the 10 MB limit.', $layout);
        $this->assertStringContainsString('Word .docx only', $layout);
        $this->assertStringContainsString('Or use a saved article', $layout);
        $this->assertStringContainsString('(written || !choseUpload)', $layout);
        $this->assertStringContainsString("fullyReady ? '' : ' is-unready'", $layout);
        $this->assertStringContainsString('const fullyReady = lineFullyAssigned(item);', $layout);
        $this->assertStringContainsString('options.length > 0 || selectedId', $layout);
        $this->assertStringNotContainsString('window.location.href = contentLibraryUploadUrl', $layout);
        $this->assertStringNotContainsString('if (approvedArticles.length === 0)', $layout);
    }

    public function test_upload_assigns_the_article_and_marks_the_line_ready(): void
    {
        $site = $this->makeSite('ready-line');
        $this->addToCart($site);
        $file = $this->docxUpload();

        $payload = $this->actingAs($this->advertiser)
            ->postJson(route('advertiser.cart.upload-article'), [
                'id' => $site->id,
                'homepage_days' => 'none',
                'copy_index' => 0,
                'original_filename' => 'guest-post.docx',
                'file' => $file,
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('uploaded', true)
            ->assertJsonPath('approved', true)
            ->json();

        $line = $payload['cart'][0];
        $this->assertGreaterThan(0, (int) ($line['content_submission_id'] ?? 0));
        $this->assertSame('upload', $line['article_choice']);
        $this->assertSame($line['content_submission_id'], $line['content_submission_ids'][0] ?? null);
        $this->assertDatabaseHas('content_submissions', [
            'id' => $line['content_submission_id'],
            'user_id' => $this->advertiser->id,
            'country' => 'us',
            'language' => 'en',
        ]);
        $this->assertContains(
            (int) $line['content_submission_id'],
            collect($payload['approved_articles'] ?? [])->pluck('id')->all()
        );
    }

    public function test_wrong_type_is_rejected_with_a_clear_message(): void
    {
        $site = $this->makeSite('bad-type');
        $this->addToCart($site);
        $path = sys_get_temp_dir().'/cart-upload-'.uniqid('', true).'.pdf';
        file_put_contents($path, '%PDF-1.4 fake');
        $file = new UploadedFile($path, 'notes.pdf', 'application/pdf', null, true);

        $message = (string) $this->actingAs($this->advertiser)
            ->postJson(route('advertiser.cart.upload-article'), [
                'id' => $site->id,
                'homepage_days' => 'none',
                'file' => $file,
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->json('message');

        $this->assertStringContainsString('.docx', strtolower($message));
        @unlink($path);
        $this->assertSame(0, ContentSubmission::query()->where('user_id', $this->advertiser->id)->count());
    }

    public function test_oversized_file_is_rejected(): void
    {
        $site = $this->makeSite('too-big');
        $this->addToCart($site);
        $file = UploadedFile::fake()->create('huge.docx', ContentUploadService::MAX_KILOBYTES + 512);

        $message = (string) $this->actingAs($this->advertiser)
            ->postJson(route('advertiser.cart.upload-article'), [
                'id' => $site->id,
                'homepage_days' => 'none',
                'file' => $file,
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->json('message');

        $this->assertTrue(
            str_contains($message, '10 MB') || str_contains($message, 'docx') || str_contains($message, 'over')
        );
    }

    public function test_pack_upload_targets_the_requested_placement(): void
    {
        $site = $this->makeSite('pack-slots');
        $this->addToCart($site, ['quantity' => 2]);
        $this->assertSame(2, (int) session('cart')[0]['quantity']);

        $payload = $this->actingAs($this->advertiser)
            ->postJson(route('advertiser.cart.upload-article'), [
                'id' => $site->id,
                'homepage_days' => 'none',
                'copy_index' => 1,
                'original_filename' => 'second-placement.docx',
                'file' => $this->docxUpload('second-placement.docx'),
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->json();

        $ids = $payload['cart'][0]['content_submission_ids'] ?? [];
        $this->assertSame(0, (int) ($ids[0] ?? -1));
        $this->assertGreaterThan(0, (int) ($ids[1] ?? 0));
        $this->assertSame((int) $ids[1], (int) $payload['submission_id']);
    }

    public function test_saved_article_lock_still_blocks_reuse_across_sites(): void
    {
        $first = $this->makeSite('lock-a');
        $second = $this->makeSite('lock-b', [
            'domain' => 'lock-b.example',
            'site_url' => 'https://lock-b.example',
        ]);
        $this->addToCart($first);
        $this->addToCart($second);

        $uploaded = $this->actingAs($this->advertiser)
            ->postJson(route('advertiser.cart.upload-article'), [
                'id' => $first->id,
                'homepage_days' => 'none',
                'file' => $this->docxUpload('locked.docx'),
                'original_filename' => 'locked.docx',
            ])
            ->assertOk()
            ->json();

        $submissionId = (int) $uploaded['submission_id'];
        $this->assertGreaterThan(0, $submissionId);

        $this->actingAs($this->advertiser)
            ->postJson(route('advertiser.cart.assign-article'), [
                'id' => $second->id,
                'homepage_days' => 'none',
                'content_submission_id' => $submissionId,
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_chunked_upload_assembles_and_assigns(): void
    {
        $site = $this->makeSite('chunks');
        $this->addToCart($site);
        $path = sys_get_temp_dir().'/cart-chunk-src-'.uniqid('', true).'.docx';
        $this->makeDocxFile($path, str_repeat('Useful editorial content about productivity software for busy teams. ', 80));
        $bytes = (string) file_get_contents($path);
        @unlink($path);
        $this->assertGreaterThan(200, strlen($bytes));

        $mid = (int) intdiv(strlen($bytes), 2);
        $uploadId = '22222222-2222-4222-8222-222222222222';
        $partOne = sys_get_temp_dir().'/cart-chunk-a-'.uniqid('', true).'.docx';
        $partTwo = sys_get_temp_dir().'/cart-chunk-b-'.uniqid('', true).'.docx';
        file_put_contents($partOne, substr($bytes, 0, $mid));
        file_put_contents($partTwo, substr($bytes, $mid));

        $this->actingAs($this->advertiser)
            ->postJson(route('advertiser.cart.upload-article'), [
                'id' => $site->id,
                'homepage_days' => 'none',
                'file' => new UploadedFile($partOne, 'article.docx', 'application/octet-stream', null, true),
                'chunk_index' => 0,
                'chunk_total' => 2,
                'upload_id' => $uploadId,
                'original_filename' => 'chunked-guest-post.docx',
                'client_bytes' => (string) strlen($bytes),
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('chunk_received', true);

        $payload = $this->actingAs($this->advertiser)
            ->postJson(route('advertiser.cart.upload-article'), [
                'id' => $site->id,
                'homepage_days' => 'none',
                'file' => new UploadedFile($partTwo, 'article.docx', 'application/octet-stream', null, true),
                'chunk_index' => 1,
                'chunk_total' => 2,
                'upload_id' => $uploadId,
                'original_filename' => 'chunked-guest-post.docx',
                'client_bytes' => (string) strlen($bytes),
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonMissingPath('chunk_received')
            ->json();

        @unlink($partOne);
        @unlink($partTwo);

        $this->assertGreaterThan(0, (int) ($payload['cart'][0]['content_submission_id'] ?? 0));
        $this->assertSame('upload', $payload['cart'][0]['article_choice']);
    }

    public function test_missing_cart_line_returns_not_found(): void
    {
        $site = $this->makeSite('missing');

        $this->actingAs($this->advertiser)
            ->postJson(route('advertiser.cart.upload-article'), [
                'id' => $site->id,
                'homepage_days' => 'none',
                'file' => $this->docxUpload(),
            ])
            ->assertStatus(404)
            ->assertJsonPath('success', false);
    }

    public function test_route_is_throttled_like_library_upload(): void
    {
        $route = collect(app('router')->getRoutes())->first(
            fn ($item) => $item->getName() === 'advertiser.cart.upload-article'
        );

        $this->assertNotNull($route);
        $this->assertContains('POST', $route->methods());
        $this->assertTrue(collect($route->gatherMiddleware())->contains(
            fn ($middleware) => is_string($middleware) && str_starts_with($middleware, 'throttle:30,1')
        ));
    }

    public function test_copy_keeps_saved_articles_secondary(): void
    {
        $copy = app(CheckoutAddOnService::class)->cartArticleCopy();
        $this->assertSame('Or use a saved article', $copy['saved_article_label']);
        $this->assertStringContainsString('10 MB', $copy['dropzone_hint']);
        $this->assertStringNotContainsString('partner', strtolower($copy['saved_article_label']));
    }
}
