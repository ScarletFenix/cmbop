<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\ContentUpload\ArticlePreviewImage;
use App\Services\ContentUpload\ContentUploadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
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

        $this->assertStringContainsString('kept 6 months', $html);
        $this->assertStringContainsString('preview stays in Expired', $html);
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

    public function test_editor_image_stores_webp_on_public_disk_not_private(): void
    {
        if (! function_exists('imagecreatetruecolor') || ! function_exists('imagewebp')) {
            $this->markTestSkipped('GD WebP not available');
        }

        Storage::fake('public');
        Storage::fake('local');
        $advertiser = $this->advertiser();

        $png = $this->pngBytes(400, 300);
        $this->assertGreaterThan(ArticlePreviewImage::SKIP_UNDER_BYTES, strlen($png));
        $path = sys_get_temp_dir().'/preview-'.uniqid('', true).'.png';
        file_put_contents($path, $png);

        $response = $this->actingAs($advertiser)
            ->postJson(route('advertiser.content-submissions.editor-image'), [
                'image' => new UploadedFile($path, 'figure.png', 'image/png', null, true),
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
}
