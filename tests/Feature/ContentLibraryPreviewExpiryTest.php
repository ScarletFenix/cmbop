<?php

namespace Tests\Feature;

use App\Models\ContentSubmission;
use App\Models\Role;
use App\Models\User;
use App\Services\ContentUpload\ArticlePreviewImage;
use App\Services\ContentUpload\ContentUploadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CreatesContentSubmissions;
use Tests\TestCase;

class ContentLibraryPreviewExpiryTest extends TestCase
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

    public function test_upload_copy_says_articles_are_kept_for_retention_months(): void
    {
        $advertiser = $this->advertiser();

        $html = $this->actingAs($advertiser)
            ->get(route('advertiser.content-library'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Max 10 MB', $html);
        $this->assertStringContainsString('kept 6 months', $html);
        $this->assertStringContainsString('preview stays in Expired', $html);
        $this->assertStringNotContainsString('Max 5 MB', $html);
    }

    public function test_expired_library_is_preview_only(): void
    {
        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);
        $submission->update([
            'title' => 'Past Due Preview',
            'expires_at' => now()->subDay(),
        ]);

        $html = $this->actingAs($advertiser)
            ->get(route('advertiser.content-library', ['availability' => 'expired']))
            ->assertOk()
            ->assertSee('Past Due Preview')
            ->assertSee('Preview only — original file removed', false)
            ->getContent();

        $this->assertStringNotContainsString('js-open-editor', $html);
        $this->assertStringNotContainsString(
            '/advertiser/content-library/'.$submission->id.'/order',
            $html
        );
        $this->assertStringNotContainsString(
            '/advertiser/content-submissions/'.$submission->id.'/download',
            $html
        );
        $this->assertStringContainsString('js-open-preview', $html);

        $this->actingAs($advertiser)
            ->getJson(route('advertiser.content-submissions.preview', $submission))
            ->assertOk()
            ->assertJsonPath('editable', false)
            ->assertJsonPath('can_order', false)
            ->assertJsonPath('has_file', true);

        $this->actingAs($advertiser)
            ->get(route('advertiser.content-submissions.download', $submission))
            ->assertNotFound();

        $this->actingAs($advertiser)
            ->putJson(route('advertiser.content-submissions.content', $submission), [
                'preview_html' => '<p>Should not save</p>',
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_expired_rejected_article_is_expired_not_needs_fix(): void
    {
        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);
        $submission->update([
            'title' => 'Rejected Then Expired',
            'moderation_status' => ContentSubmission::STATUS_REJECTED,
            'evaluation_status' => 'rejected',
            'expires_at' => now()->subDay(),
        ]);

        $this->assertSame('expired', $submission->fresh()->libraryAvailability());
        $this->assertFalse($submission->fresh()->canEditArticle());

        $expiredHtml = $this->actingAs($advertiser)
            ->get(route('advertiser.content-library', ['availability' => 'expired']))
            ->assertOk()
            ->assertSee('Rejected Then Expired')
            ->assertSee('Preview only — original file removed', false)
            ->getContent();
        $this->assertStringNotContainsString('Resubmit', $expiredHtml);
        $this->assertStringNotContainsString('js-open-editor', $expiredHtml);

        $this->actingAs($advertiser)
            ->get(route('advertiser.content-library', [
                'status' => 'all',
                'availability' => 'needs_fix',
            ]))
            ->assertOk()
            ->assertDontSee('Rejected Then Expired');

        $chipHtml = $this->actingAs($advertiser)
            ->get(route('advertiser.content-library'))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('aria-label="Needs corrections, 0 articles"', $chipHtml);
        $this->assertStringContainsString('aria-label="Expired, 1 article"', $chipHtml);

        $bootHtml = $this->actingAs($advertiser)
            ->get(route('advertiser.content-library', [
                'edit' => $submission->id,
                'upload' => 1,
            ]))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('editSubmission: null', $bootHtml);
        $this->assertStringContainsString('id="replaceIdInput" value=""', $bootHtml);
    }

    public function test_expiry_instant_is_preview_only_and_cannot_be_ordered(): void
    {
        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);
        $at = now()->startOfSecond();
        $submission->update([
            'title' => 'Expires Now',
            'expires_at' => $at,
        ]);
        $this->travelTo($at);

        $fresh = $submission->fresh();
        $this->assertTrue($fresh->isExpired());
        $this->assertFalse($fresh->canBeOrdered());
        $this->assertFalse($fresh->canEditArticle());
        $this->assertFalse($fresh->canDownloadOriginal());
        $this->assertSame('expired', $fresh->libraryAvailability());

        $this->actingAs($advertiser)
            ->get(route('advertiser.content-library.order', $submission))
            ->assertRedirect(route('advertiser.content-library'))
            ->assertSessionHas('error', 'Expired articles are preview only and cannot be ordered.');

        $this->actingAs($advertiser)
            ->get(route('advertiser.content-library', ['availability' => 'expired']))
            ->assertOk()
            ->assertSee('Expires Now');
    }

    public function test_library_js_blocks_editor_when_payload_is_not_editable(): void
    {
        $js = (string) file_get_contents(public_path('assets/js/content-library.js'));
        $this->assertStringContainsString('if (!payload.editable)', $js);
        $this->assertStringContainsString('Expired articles are preview only.', $js);
        $this->assertStringContainsString('const canEdit = previewModalState.editable;', $js);
        $this->assertStringNotContainsString('!!articleEditorSubmissionId && Number(articleEditorSubmissionId) === Number(previewModalState.submissionId)', $js);
    }

    public function test_replace_upload_rejects_expired_articles_on_both_endpoints(): void
    {
        Storage::fake('local');
        config(['content_moderation.enabled' => false]);
        Mail::fake();

        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);
        $submission->update(['expires_at' => now()->subDay()]);

        $path = sys_get_temp_dir().'/replace-expired-'.uniqid('', true).'.docx';
        $this->makeDocxFile($path);

        foreach ([
            'advertiser.content-library.upload',
            'advertiser.content-submissions.upload',
        ] as $routeName) {
            $this->actingAs($advertiser)
                ->postJson(route($routeName), [
                    'file' => new UploadedFile(
                        $path,
                        'revised.docx',
                        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                        null,
                        true
                    ),
                    'country' => 'us',
                    'language' => 'en',
                    'replace_id' => $submission->id,
                    'image_rights' => ContentSubmission::IMAGE_RIGHTS_NONE,
                ])
                ->assertStatus(422)
                ->assertJsonPath('success', false)
                ->assertJsonPath('title', 'Expired');
        }

        @unlink($path);

        $this->assertTrue($submission->fresh()->hasStoredFile());
        $this->assertSame(1, ContentSubmission::query()->where('user_id', $advertiser->id)->count());
    }

    public function test_editor_image_stores_webp_on_public_disk_not_private(): void
    {
        if (! function_exists('imagecreatetruecolor') || ! function_exists('imagewebp')) {
            $this->markTestSkipped('GD WebP not available');
        }

        Storage::fake('public');
        Storage::fake('local');
        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);

        $png = $this->largePngBytes();
        $this->assertGreaterThan(ArticlePreviewImage::SKIP_UNDER_BYTES, strlen($png));
        $path = sys_get_temp_dir().'/preview-'.uniqid('', true).'.png';
        file_put_contents($path, $png);

        $response = $this->actingAs($advertiser)
            ->postJson(route('advertiser.content-submissions.editor-image'), [
                'image' => new UploadedFile($path, 'figure.png', 'image/png', null, true),
                'content_submission_id' => $submission->id,
                'current_image_count' => 0,
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        @unlink($path);

        $url = (string) $response->json('url');
        $this->assertStringStartsWith('/storage/content-articles/'.$advertiser->id.'/', $url);
        $this->assertStringEndsWith('.webp', $url);
        $this->assertSame([], Storage::disk('local')->allFiles());

        $relative = ltrim(substr($url, strlen('/storage/')), '/');
        $this->assertTrue(Storage::disk('public')->exists($relative));
        $stored = Storage::disk('public')->get($relative);
        $this->assertStringStartsWith('RIFF', $stored);
    }

    public function test_store_article_image_does_not_write_the_private_docx_disk(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $advertiser = $this->advertiser();
        $png = $this->pngBytes(16, 16);

        $url = app(ContentUploadService::class)->storeArticleImage($png, 'png', 'tiny.png', $advertiser);

        $this->assertNotNull($url);
        $this->assertStringStartsWith('/storage/content-articles/', $url);
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertNotEmpty(Storage::disk('public')->allFiles());
    }

    public function test_empty_expired_copy_explains_preview_only(): void
    {
        $advertiser = $this->advertiser();

        $this->actingAs($advertiser)
            ->get(route('advertiser.content-library', ['availability' => 'expired']))
            ->assertOk()
            ->assertSee('preview only', false)
            ->assertSee('original file is removed', false)
            ->assertDontSee('Automatic purge deletes unused expired files only', false);
    }

    private function pngBytes(int $width, int $height): string
    {
        $img = imagecreatetruecolor($width, $height);
        imagefilledrectangle($img, 0, 0, $width, $height, imagecolorallocate($img, 12, 80, 160));
        ob_start();
        imagepng($img);
        $png = ob_get_clean();
        imagedestroy($img);

        return is_string($png) ? $png : '';
    }

    private function largePngBytes(): string
    {
        $img = imagecreatetruecolor(320, 240);
        imagefilledrectangle($img, 0, 0, 319, 239, imagecolorallocate($img, 12, 80, 160));
        ob_start();
        imagepng($img, null, 0);
        $png = ob_get_clean();
        imagedestroy($img);

        return is_string($png) ? $png : '';
    }
}
