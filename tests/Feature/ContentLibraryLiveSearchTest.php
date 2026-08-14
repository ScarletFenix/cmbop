<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesContentSubmissions;
use Tests\TestCase;

class ContentLibraryLiveSearchTest extends TestCase
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

    public function test_results_endpoint_requires_auth(): void
    {
        $this->get(route('advertiser.content-library.results'))
            ->assertRedirect();
    }

    public function test_results_endpoint_returns_fragment_not_full_layout(): void
    {
        $advertiser = $this->advertiser();
        $article = $this->createApprovedSubmission($advertiser);
        $article->update(['title' => 'Growth Playbook']);

        $html = $this->actingAs($advertiser)
            ->get(route('advertiser.content-library.results', ['q' => 'Growth']))
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertSee('Growth Playbook')
            ->assertSee('library-status-row', false)
            ->assertDontSee('<html', false)
            ->getContent();

        $this->assertStringContainsString('library-table', $html);
    }

    public function test_index_has_catalog_search_chrome(): void
    {
        $advertiser = $this->advertiser();

        $this->actingAs($advertiser)
            ->get(route('advertiser.content-library'))
            ->assertOk()
            ->assertSee('for="librarySearchInput">Search</label>', false)
            ->assertSee('id="librarySearchClear"', false)
            ->assertSee('id="librarySearchStatus"', false)
            ->assertSee('id="libraryLiveRegion"', false)
            ->assertDontSee('data-slb-live-search="form"', false);
    }

    public function test_word_and_requires_every_token_on_title_or_filename(): void
    {
        $advertiser = $this->advertiser();

        $both = $this->createApprovedSubmission($advertiser);
        $both->update([
            'title' => 'Growth Playbook',
            'original_filename' => 'article.docx',
        ]);

        $oneWord = $this->createApprovedSubmission($advertiser);
        $oneWord->update([
            'title' => 'Growth Guide',
            'original_filename' => 'notes.docx',
        ]);

        $filenameHit = $this->createApprovedSubmission($advertiser);
        $filenameHit->update([
            'title' => 'Alpha Draft',
            'original_filename' => 'summer-guide.docx',
        ]);

        $this->actingAs($advertiser)
            ->get(route('advertiser.content-library', ['q' => 'Growth Playbook']))
            ->assertOk()
            ->assertSee('Growth Playbook')
            ->assertDontSee('Growth Guide')
            ->assertDontSee('Alpha Draft');

        $this->actingAs($advertiser)
            ->get(route('advertiser.content-library', ['q' => 'Growth']))
            ->assertOk()
            ->assertSee('Growth Playbook')
            ->assertSee('Growth Guide')
            ->assertDontSee('Alpha Draft');

        $this->actingAs($advertiser)
            ->get(route('advertiser.content-library.results', ['q' => 'summer guide']))
            ->assertOk()
            ->assertSee('Alpha Draft')
            ->assertDontSee('Growth Playbook')
            ->assertDontSee('Growth Guide');
    }
}
