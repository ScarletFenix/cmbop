<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesContentSubmissions;
use Tests\TestCase;

/**
 * Approved-table leftover copy: one expiry policy line, labeled scores.
 */
class ContentLibraryTableCopyTest extends TestCase
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

    public function test_approved_list_shows_expiry_policy_once_and_labeled_scores(): void
    {
        $advertiser = $this->advertiser();
        $first = $this->createApprovedSubmission($advertiser);
        $first->update(['title' => 'First Copy Piece']);
        $second = $this->createApprovedSubmission($advertiser);
        $second->update([
            'title' => 'Second Copy Piece',
            'uniqueness_score' => 25,
            'quality_score' => 92,
        ]);

        $html = $this->actingAs($advertiser)
            ->get(route('advertiser.content-library'))
            ->assertOk()
            ->assertSee('First Copy Piece')
            ->assertSee('Second Copy Piece')
            ->assertSee('Uniqueness · Quality')
            ->assertSee('Unique 85%')
            ->assertSee('Quality 80%')
            ->assertSee('Unique 25%')
            ->assertSee('Quality 92%')
            ->assertSee('Advisory — still orderable')
            ->assertSee('library-table-note', false)
            ->getContent();

        $this->assertSame(1, substr_count($html, 'Unused originals are removed after expiry; preview stays.'));
        $this->assertSame(2, substr_count($html, 'Expires in'));
        $this->assertDoesNotMatchRegularExpression(
            '/library-expiry-hint[^>]*>[^<]*unused originals are removed after expiry/i',
            $html
        );
        $this->assertStringNotContainsString('>85%</td>', $html);
        $this->assertStringNotContainsString('85% · 80%', preg_replace('/\s+/', ' ', $html) ?? $html);
    }

    public function test_missing_score_is_labeled_dash_not_bare_percent(): void
    {
        $advertiser = $this->advertiser();
        $article = $this->createApprovedSubmission($advertiser);
        $article->update([
            'title' => 'Partial Score Piece',
            'uniqueness_score' => null,
            'quality_score' => 77,
        ]);

        $this->actingAs($advertiser)
            ->get(route('advertiser.content-library'))
            ->assertOk()
            ->assertSee('Partial Score Piece')
            ->assertSee('Unique —')
            ->assertSee('Quality 77%')
            ->assertDontSee('Advisory — still orderable');
    }

    public function test_live_search_fragment_keeps_labeled_scores_and_one_policy_line(): void
    {
        $advertiser = $this->advertiser();
        $article = $this->createApprovedSubmission($advertiser);
        $article->update(['title' => 'Live Copy Playbook']);

        $html = $this->actingAs($advertiser)
            ->get(route('advertiser.content-library.results', ['q' => 'Live Copy']))
            ->assertOk()
            ->assertSee('Live Copy Playbook')
            ->assertSee('Uniqueness · Quality')
            ->assertSee('Unique 85%')
            ->assertSee('Quality 80%')
            ->assertDontSee('<html', false)
            ->getContent();

        $this->assertSame(1, substr_count($html, 'Unused originals are removed after expiry; preview stays.'));
        $this->assertStringContainsString('library-table-note', $html);
    }

    public function test_processing_chip_omits_approved_expiry_policy_note(): void
    {
        $advertiser = $this->advertiser();
        $article = $this->createApprovedSubmission($advertiser);
        $article->update(['title' => 'Still Available Piece']);

        $html = $this->actingAs($advertiser)
            ->get(route('advertiser.content-library', ['availability' => 'in_progress']))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('library-table-note', $html);
        $this->assertStringNotContainsString('Unused originals are removed after expiry; preview stays.', $html);
    }
}
