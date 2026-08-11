<?php

namespace Tests\Feature;

use App\Models\ContentSubmission;
use App\Models\Role;
use App\Models\User;
use App\Support\ContentLibrarySchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CreatesContentSubmissions;
use Tests\TestCase;

/**
 * Content Library phases 0–2: schema check, upload kill-switch, status chips.
 */
class ContentLibraryPhases02Test extends TestCase
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

    public function test_library_schema_command_passes_after_migrate(): void
    {
        $this->assertTrue(ContentLibrarySchema::ready());
        $this->assertSame([], ContentLibrarySchema::missing());

        $exit = Artisan::call('content:library-schema');
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('CONTENT LIBRARY SCHEMA OK', Artisan::output());
    }

    public function test_upload_kill_switch_blocks_library_and_legacy_upload(): void
    {
        config(['content_upload.enabled' => false]);
        $advertiser = $this->advertiser();
        Storage::fake('local');

        $file = UploadedFile::fake()->create('piece.docx', 120, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');

        $this->actingAs($advertiser)
            ->postJson(route('advertiser.content-library.upload'), [
                'file' => $file,
                'country' => 'de',
                'language' => 'de',
                'image_rights' => ContentSubmission::IMAGE_RIGHTS_NONE,
            ])
            ->assertForbidden()
            ->assertJsonPath('success', false);

        $this->actingAs($advertiser)
            ->postJson(route('advertiser.content-submissions.upload'), [
                'file' => $file,
                'country' => 'de',
                'language' => 'de',
                'image_rights' => ContentSubmission::IMAGE_RIGHTS_NONE,
            ])
            ->assertForbidden()
            ->assertJsonPath('success', false);

        $html = $this->actingAs($advertiser)
            ->get(route('advertiser.content-library'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Uploads disabled', $html);
        $this->assertStringContainsString('temporarily turned off', $html);
        $this->assertStringNotContainsString('data-bs-target="#uploadContentModal"', $html);
    }

    public function test_config_endpoint_reports_uploads_enabled_flag(): void
    {
        $advertiser = $this->advertiser();

        $this->actingAs($advertiser)
            ->getJson(route('advertiser.content-submissions.config'))
            ->assertOk()
            ->assertJsonPath('config.enabled', true);

        config(['content_upload.enabled' => false]);

        $this->actingAs($advertiser)
            ->getJson(route('advertiser.content-submissions.config'))
            ->assertOk()
            ->assertJsonPath('config.enabled', false);
    }

    public function test_status_strip_includes_archived_expired_and_evaluating_badge(): void
    {
        $advertiser = $this->advertiser();

        $ready = $this->createApprovedSubmission($advertiser);
        $ready->update(['title' => 'Ready Piece']);

        $evaluating = ContentSubmission::create([
            'user_id' => $advertiser->id,
            'title' => 'Still Checking',
            'original_filename' => 'checking.docx',
            'disk' => 'local',
            'path' => 'content-uploads/checking.docx',
            'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'extension' => 'docx',
            'size_bytes' => 100,
            'country' => 'de',
            'language' => 'de',
            'moderation_status' => ContentSubmission::STATUS_PROCESSING,
            'evaluation_status' => 'processing',
            'image_rights' => ContentSubmission::IMAGE_RIGHTS_NONE,
        ]);
        $this->assertSame('evaluating', $evaluating->fresh()->libraryAvailability());

        $archived = $this->createApprovedSubmission($advertiser);
        $archived->update(['title' => 'Boxed Away']);
        $archived->archive();

        $expired = $this->createApprovedSubmission($advertiser);
        $expired->update([
            'title' => 'Past Due',
            'expires_at' => now()->subDay(),
        ]);

        $html = $this->actingAs($advertiser)
            ->get(route('advertiser.content-library', [
                'status' => 'approved',
                'availability' => 'available',
            ]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('library-status-box--archived', $html);
        $this->assertStringContainsString('library-status-box--expired', $html);
        $this->assertStringContainsString('>Archived</span>', $html);
        $this->assertStringContainsString('>Expired</span>', $html);
        $this->assertStringContainsString('library-eval-badge', $html);
        $this->assertStringContainsString('Evaluating 1', $html);
        $this->assertStringContainsString('still evaluating', $html);
        $this->assertStringContainsString('Ready Piece', $html);
        $this->assertStringContainsString('Still Checking', $html);
        $this->assertStringContainsString('Evaluating…', $html);

        $archivedHtml = $this->actingAs($advertiser)
            ->get(route('advertiser.content-library', ['availability' => 'archived']))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('Boxed Away', $archivedHtml);
        $this->assertStringNotContainsString('Ready Piece', $archivedHtml);

        $expiredHtml = $this->actingAs($advertiser)
            ->get(route('advertiser.content-library', ['availability' => 'expired']))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('Past Due', $expiredHtml);
        $this->assertStringNotContainsString('Ready Piece', $expiredHtml);
    }

    public function test_orderable_scope_requires_market_and_archive_columns(): void
    {
        $advertiser = $this->advertiser();
        $ok = $this->createApprovedSubmission($advertiser);

        $this->assertTrue(
            ContentSubmission::query()->orderable()->whereKey($ok->id)->exists()
        );

        $ok->update(['country' => null]);
        $this->assertFalse(
            ContentSubmission::query()->orderable()->whereKey($ok->id)->exists()
        );
    }
}
