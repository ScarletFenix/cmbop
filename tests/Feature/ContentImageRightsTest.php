<?php

namespace Tests\Feature;

use App\Models\ContentSubmission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Support\CreatesContentSubmissions;
use Tests\TestCase;

class ContentImageRightsTest extends TestCase
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

        return $user;
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function upload(User $advertiser, array $extra = []): TestResponse
    {
        Storage::fake('local');
        config(['content_moderation.enabled' => false]);
        Mail::fake();

        $path = sys_get_temp_dir().'/image-rights-'.uniqid().'.docx';
        $this->makeDocxFile($path, str_repeat('Useful editorial content about productivity software for busy teams. ', 60));

        $response = $this->actingAs($advertiser)->postJson(route('advertiser.content-library.upload'), array_merge([
            'file' => new UploadedFile(
                $path,
                'article.docx',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                null,
                true
            ),
            'title' => 'Image rights article',
            'country' => 'us',
            'language' => 'en',
        ], $extra));

        @unlink($path);

        return $response;
    }

    public function test_upload_is_rejected_without_an_image_rights_declaration(): void
    {
        $this->upload($this->advertiser())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['image_rights']);

        $this->assertSame(0, ContentSubmission::count());
    }

    public function test_upload_is_rejected_for_an_unknown_declaration(): void
    {
        $this->upload($this->advertiser(), ['image_rights' => 'whatever'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['image_rights']);
    }

    public function test_sourced_images_must_name_a_source(): void
    {
        $this->upload($this->advertiser(), ['image_rights' => ContentSubmission::IMAGE_RIGHTS_LICENSED])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['image_rights_source']);
    }

    public function test_owning_the_images_is_recorded_on_the_article(): void
    {
        $advertiser = $this->advertiser();

        $this->upload($advertiser, ['image_rights' => ContentSubmission::IMAGE_RIGHTS_OWN])
            ->assertOk()
            ->assertJsonPath('success', true);

        $submission = ContentSubmission::where('user_id', $advertiser->id)->firstOrFail();
        $this->assertSame(ContentSubmission::IMAGE_RIGHTS_OWN, $submission->image_rights);
        $this->assertNull($submission->image_rights_source);
        $this->assertNotNull($submission->image_rights_declared_at);
    }

    public function test_a_sourced_declaration_stores_the_source(): void
    {
        $advertiser = $this->advertiser();

        $this->upload($advertiser, [
            'image_rights' => ContentSubmission::IMAGE_RIGHTS_LICENSED,
            'image_rights_source' => 'https://unsplash.com/photos/abc123',
        ])->assertOk();

        $submission = ContentSubmission::where('user_id', $advertiser->id)->firstOrFail();
        $this->assertSame(ContentSubmission::IMAGE_RIGHTS_LICENSED, $submission->image_rights);
        $this->assertSame('https://unsplash.com/photos/abc123', $submission->image_rights_source);
    }

    public function test_declaring_no_images_does_not_keep_a_stale_source(): void
    {
        $advertiser = $this->advertiser();

        $this->upload($advertiser, [
            'image_rights' => ContentSubmission::IMAGE_RIGHTS_NONE,
            'image_rights_source' => 'https://example.com/ignored',
        ])->assertOk();

        $submission = ContentSubmission::where('user_id', $advertiser->id)->firstOrFail();
        $this->assertSame(ContentSubmission::IMAGE_RIGHTS_NONE, $submission->image_rights);
        $this->assertNull($submission->image_rights_source);
    }

    public function test_images_added_in_the_editor_cannot_bypass_the_declaration(): void
    {
        config(['content_moderation.enabled' => false]);
        Mail::fake();

        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);
        $submission->update([
            'image_rights' => ContentSubmission::IMAGE_RIGHTS_NONE,
            'image_rights_declared_at' => now(),
        ]);

        $this->actingAs($advertiser)
            ->putJson(route('advertiser.content-submissions.content', $submission), [
                'preview_html' => '<p>Body copy</p><img src="/storage/content-articles/1/x.png" alt="">',
            ])
            ->assertStatus(422)
            ->assertJsonPath('needs_image_rights', true);

        $this->assertSame(
            ContentSubmission::IMAGE_RIGHTS_NONE,
            $submission->fresh()->image_rights
        );
    }

    public function test_updating_the_declaration_lets_the_edit_through(): void
    {
        config(['content_moderation.enabled' => false]);
        Mail::fake();

        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);
        $submission->update([
            'image_rights' => ContentSubmission::IMAGE_RIGHTS_NONE,
            'image_rights_declared_at' => now(),
        ]);

        $this->actingAs($advertiser)
            ->putJson(route('advertiser.content-submissions.content', $submission), [
                'preview_html' => '<p>Body copy</p><img src="/storage/content-articles/1/x.png" alt="">',
                'image_rights' => ContentSubmission::IMAGE_RIGHTS_LICENSED,
                'image_rights_source' => 'https://pexels.com/photo/999',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $fresh = $submission->fresh();
        $this->assertSame(ContentSubmission::IMAGE_RIGHTS_LICENSED, $fresh->image_rights);
        $this->assertSame('https://pexels.com/photo/999', $fresh->image_rights_source);
    }

    public function test_text_only_edits_are_unaffected(): void
    {
        config(['content_moderation.enabled' => false]);
        Mail::fake();

        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);
        $submission->update([
            'image_rights' => ContentSubmission::IMAGE_RIGHTS_NONE,
            'image_rights_declared_at' => now(),
        ]);

        $this->actingAs($advertiser)
            ->putJson(route('advertiser.content-submissions.content', $submission), [
                'preview_html' => '<p>Still no pictures in this article at all.</p>',
            ])
            ->assertOk();
    }

    public function test_the_model_knows_when_a_declaration_stops_covering_the_article(): void
    {
        $submission = new ContentSubmission;

        $submission->preview_html = '<p>text only</p>';
        $submission->image_rights = ContentSubmission::IMAGE_RIGHTS_NONE;
        $this->assertFalse($submission->hasImages());
        $this->assertTrue($submission->imageRightsCoverContent());

        $submission->preview_html = '<p>text</p><img src="/storage/a.png">';
        $this->assertTrue($submission->hasImages());
        $this->assertFalse($submission->imageRightsCoverContent());

        $submission->image_rights = ContentSubmission::IMAGE_RIGHTS_OWN;
        $this->assertTrue($submission->imageRightsCoverContent());

        $this->assertTrue(ContentSubmission::imageRightsNeedsSource(ContentSubmission::IMAGE_RIGHTS_LICENSED));
        $this->assertFalse(ContentSubmission::imageRightsNeedsSource(ContentSubmission::IMAGE_RIGHTS_OWN));
    }

    public function test_both_upload_forms_ask_for_the_declaration(): void
    {
        $advertiser = $this->advertiser();

        $library = $this->actingAs($advertiser)
            ->get(route('advertiser.content-library'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-image-rights', $library);
        $this->assertStringContainsString('name="image_rights"', $library);
        $this->assertStringContainsString('name="image_rights_source"', $library);
        $this->assertStringContainsString('image-rights.js', $library);

        // The checkout wizard uses the same partial, so assert on the partial itself.
        $wizard = file_get_contents(resource_path('views/advertiser/partials/content-submission-wizard.blade.php'));
        $this->assertStringContainsString("@include('advertiser.partials.image-rights-declaration'", $wizard);
        $this->assertStringContainsString('appendImageRights', $wizard);
    }
}
