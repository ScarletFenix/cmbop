<?php

namespace Tests\Feature;

use App\Mail\BulkSiteItemsRejected;
use App\Mail\BulkSitesSeededNotification;
use App\Mail\SiteStatusNotification;
use App\Models\ActivityLog;
use App\Models\BulkSiteRequest;
use App\Models\BulkSiteRequestItem;
use App\Models\Category;
use App\Models\Country;
use App\Models\InAppNotification;
use App\Models\Language;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\CategoriesTableSeeder;
use Database\Seeders\CountriesTableSeeder;
use Database\Seeders\LanguagesTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class BulkDoneRejectRowsTest extends TestCase
{
    use RefreshDatabase;

    private User $publisher;

    private User $marketer;

    private User $admin;

    private User $advertiser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesTableSeeder::class);
        $this->seed(CountriesTableSeeder::class);
        $this->seed(LanguagesTableSeeder::class);
        $this->seed(CategoriesTableSeeder::class);

        $publisherRole = Role::where('name', 'publisher')->firstOrFail();
        $this->publisher = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $publisherRole->id,
        ]);
        $this->publisher->roles()->attach($publisherRole->id);

        $marketingRole = Role::where('name', 'marketing')->firstOrFail();
        $this->marketer = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $marketingRole->id,
            'name' => 'Marketer Casey',
        ]);
        $this->marketer->roles()->attach($marketingRole->id);

        $adminRole = Role::where('name', 'admin')->firstOrFail();
        $this->admin = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $adminRole->id,
            'name' => 'Admin Avery',
        ]);
        $this->admin->roles()->attach($adminRole->id);

        $advertiserRole = Role::where('name', 'advertiser')->firstOrFail();
        $this->advertiser = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $advertiserRole->id,
        ]);
        $this->advertiser->roles()->attach($advertiserRole->id);
    }

    /**
     * @return list<array{0:string,1:User}>
     */
    private function staffActors(): array
    {
        return [
            ['admin', $this->admin],
            ['marketing', $this->marketer],
        ];
    }

    private function marketplaceCodes(): array
    {
        $country = Country::marketplace()->where('code', 'de')->first()
            ?? Country::marketplace()->firstOrFail();
        $language = Language::marketplace()->where('code', 'de')->first()
            ?? Language::marketplace()->firstOrFail();

        return [strtolower($country->code), strtolower($language->code)];
    }

    /**
     * @return array{0:BulkSiteRequest,1:list<BulkSiteRequestItem>}
     */
    private function makeBulkWithItems(int $count, string $prefix): array
    {
        $bulk = BulkSiteRequest::create([
            'publisher_id' => $this->publisher->id,
            'status' => BulkSiteRequest::STATUS_REQUESTED,
            'estimated_count' => $count,
        ]);

        $items = [];
        for ($i = 1; $i <= $count; $i++) {
            $domain = $prefix.'-'.$i.'.example';
            $items[] = BulkSiteRequestItem::create([
                'bulk_site_request_id' => $bulk->id,
                'site_url' => 'https://'.$domain,
                'domain' => $domain,
                'price' => 40 + $i,
            ]);
        }

        return [$bulk, $items];
    }

    private function completeRow(BulkSiteRequestItem $item): array
    {
        [$country, $language] = $this->marketplaceCodes();
        $category = Category::query()->firstOrFail();

        return [
            $item->id => [
                'language' => $language,
                'country' => $country,
                'da' => 30,
                'dr' => 35,
                'traffic' => 5000,
                'categories' => $category->name,
            ],
        ];
    }

    public function test_done_page_shows_delete_and_note_for_admin_and_marketer(): void
    {
        [$bulk] = $this->makeBulkWithItems(2, 'ui');

        foreach ($this->staffActors() as [$prefix, $user]) {
            $this->actingAs($user)
                ->get(route($prefix.'.bulk-site-requests.show', $bulk))
                ->assertOk()
                ->assertSee('id="bulkDoneForm"', false)
                ->assertSee(route($prefix.'.bulk-site-requests.done', $bulk), false)
                ->assertSee('data-bulk-reject-row', false)
                ->assertSee('name="rejection_note"', false)
                ->assertSee('Note to publisher (removed sites)', false)
                ->assertSee('Delete a row you will not add', false);
        }

        $blade = file_get_contents(resource_path('views/admin/bulk-site-requests/show.blade.php'));
        $this->assertStringContainsString("staff_route('bulk-site-requests.done'", $blade);
        $this->assertStringContainsString('rejected_item_ids[]', $blade);
        $this->assertStringContainsString('function markRowRejected', $blade);
        $this->assertStringNotContainsString('route(\'admin.bulk-site-requests.done\'', $blade);
    }

    public function test_done_two_complete_and_reject_one_notifies_once_for_both_roles(): void
    {
        foreach ($this->staffActors() as [$prefix, $user]) {
            Mail::fake();
            [$bulk, $items] = $this->makeBulkWithItems(3, $prefix.'-mix');
            [$keepA, $keepB, $drop] = $items;

            $this->actingAs($user)
                ->from(route($prefix.'.bulk-site-requests.show', $bulk))
                ->post(route($prefix.'.bulk-site-requests.done', $bulk), [
                    'items' => $this->completeRow($keepA) + $this->completeRow($keepB),
                    'rejected_item_ids' => [$drop->id],
                    'rejection_note' => 'These metrics do not meet our listing bar.',
                ])
                ->assertRedirect(route($prefix.'.bulk-site-requests.show', $bulk))
                ->assertSessionHas('success', function ($message) {
                    $message = (string) $message;

                    return str_contains($message, '2 site(s) added')
                        && str_contains($message, '1 site was removed');
                });

            $this->assertDatabaseHas('sites', ['domain' => $keepA->domain]);
            $this->assertDatabaseHas('sites', ['domain' => $keepB->domain]);
            $this->assertDatabaseMissing('sites', ['domain' => $drop->domain]);
            $this->assertDatabaseMissing('bulk_site_request_items', ['id' => $drop->id]);
            $this->assertNotNull($keepA->fresh()->site_id);
            $this->assertSame(2, $bulk->fresh()->items()->count());

            Mail::assertQueued(BulkSitesSeededNotification::class, 1);
            Mail::assertQueued(BulkSiteItemsRejected::class, 1);
            Mail::assertQueued(BulkSiteItemsRejected::class, function (BulkSiteItemsRejected $mail) use ($drop) {
                return $mail->hasTo($this->publisher->email)
                    && $mail->domains === [$drop->domain]
                    && str_contains($mail->note, 'listing bar');
            });
            Mail::assertNotQueued(SiteStatusNotification::class);

            $this->assertSame(1, InAppNotification::query()
                ->where('user_id', $this->publisher->id)
                ->where('related_id', $bulk->id)
                ->where('title', 'A site was not added from your bulk request')
                ->count());
            $this->assertSame(1, InAppNotification::query()
                ->where('user_id', $this->publisher->id)
                ->where('related_id', $bulk->id)
                ->where('title', '2 sites were added to Pending sites')
                ->count());

            $this->assertDatabaseHas('activity_logs', [
                'action' => 'bulk_request.items_rejected',
                'user_id' => $user->id,
                'subject_id' => $bulk->id,
            ]);
            $rejectLog = ActivityLog::query()
                ->where('action', 'bulk_request.items_rejected')
                ->where('subject_id', $bulk->id)
                ->first();
            $this->assertNotNull($rejectLog);
            $this->assertSame([$drop->domain], $rejectLog->properties['domains'] ?? null);
            $this->assertSame(BulkSiteRequest::STATUS_AWAITING_PUBLISHER, $bulk->fresh()->status);
        }
    }

    public function test_reject_only_with_note_deletes_items_and_does_not_cancel(): void
    {
        foreach ($this->staffActors() as [$prefix, $user]) {
            Mail::fake();
            [$bulk, $items] = $this->makeBulkWithItems(2, $prefix.'-only');

            $this->actingAs($user)
                ->from(route($prefix.'.bulk-site-requests.show', $bulk))
                ->post(route($prefix.'.bulk-site-requests.done', $bulk), [
                    'rejected_item_ids' => array_map(fn ($item) => $item->id, $items),
                    'rejection_note' => 'We are not listing these two domains.',
                ])
                ->assertRedirect(route($prefix.'.bulk-site-requests.show', $bulk))
                ->assertSessionHas('success', fn ($message) => str_contains((string) $message, '2 sites were removed'));

            $this->assertSame(0, Site::query()->where('bulk_site_request_id', $bulk->id)->count());
            $this->assertSame(0, $bulk->fresh()->items()->count());
            $this->assertSame(BulkSiteRequest::STATUS_REQUESTED, $bulk->fresh()->status);
            $this->assertNotSame(BulkSiteRequest::STATUS_CANCELLED, $bulk->fresh()->status);

            Mail::assertQueued(BulkSiteItemsRejected::class, 1);
            Mail::assertNotQueued(BulkSitesSeededNotification::class);
            Mail::assertNotQueued(SiteStatusNotification::class);

            $this->assertSame(1, InAppNotification::query()
                ->where('user_id', $this->publisher->id)
                ->where('related_id', $bulk->id)
                ->where('title', '2 sites were not added from your bulk request')
                ->count());
        }
    }

    public function test_reject_without_note_does_not_delete(): void
    {
        foreach ($this->staffActors() as [$prefix, $user]) {
            Mail::fake();
            [$bulk, $items] = $this->makeBulkWithItems(1, $prefix.'-nonote');

            $this->actingAs($user)
                ->from(route($prefix.'.bulk-site-requests.show', $bulk))
                ->post(route($prefix.'.bulk-site-requests.done', $bulk), [
                    'rejected_item_ids' => [$items[0]->id],
                ])
                ->assertRedirect(route($prefix.'.bulk-site-requests.show', $bulk))
                ->assertSessionHasErrors('rejection_note');

            $this->assertDatabaseHas('bulk_site_request_items', ['id' => $items[0]->id]);
            Mail::assertNothingQueued();
        }
    }

    public function test_reject_note_shorter_than_ten_does_not_delete(): void
    {
        foreach ($this->staffActors() as [$prefix, $user]) {
            Mail::fake();
            [$bulk, $items] = $this->makeBulkWithItems(1, $prefix.'-short');

            $this->actingAs($user)
                ->from(route($prefix.'.bulk-site-requests.show', $bulk))
                ->post(route($prefix.'.bulk-site-requests.done', $bulk), [
                    'rejected_item_ids' => [$items[0]->id],
                    'rejection_note' => 'too short',
                ])
                ->assertRedirect(route($prefix.'.bulk-site-requests.show', $bulk))
                ->assertSessionHasErrors('rejection_note');

            $this->assertDatabaseHas('bulk_site_request_items', ['id' => $items[0]->id]);
            Mail::assertNothingQueued();
        }
    }

    public function test_partial_row_still_blocks_even_with_rejects_and_note(): void
    {
        foreach ($this->staffActors() as [$prefix, $user]) {
            Mail::fake();
            [$country, $language] = $this->marketplaceCodes();
            [$bulk, $items] = $this->makeBulkWithItems(2, $prefix.'-partial');
            [$partial, $drop] = $items;

            $this->actingAs($user)
                ->from(route($prefix.'.bulk-site-requests.show', $bulk))
                ->post(route($prefix.'.bulk-site-requests.done', $bulk), [
                    'items' => [
                        $partial->id => [
                            'language' => $language,
                            'country' => $country,
                            'da' => 20,
                        ],
                    ],
                    'rejected_item_ids' => [$drop->id],
                    'rejection_note' => 'Valid publisher note for the removed site.',
                ])
                ->assertRedirect(route($prefix.'.bulk-site-requests.show', $bulk))
                ->assertSessionHasErrors()
                ->assertSessionHas('error');

            $this->assertDatabaseHas('bulk_site_request_items', ['id' => $drop->id]);
            $this->assertDatabaseMissing('sites', ['domain' => $partial->domain]);
            Mail::assertNothingQueued();
        }
    }

    public function test_cannot_reject_item_that_already_has_a_site(): void
    {
        foreach ($this->staffActors() as [$prefix, $user]) {
            Mail::fake();
            [$bulk, $items] = $this->makeBulkWithItems(2, $prefix.'-linked');
            [$linked, $pending] = $items;

            $site = Site::create([
                'publisher_id' => $this->publisher->id,
                'bulk_site_request_id' => $bulk->id,
                'site_name' => $linked->domain,
                'site_url' => $linked->site_url,
                'domain' => $linked->domain,
                'example_url' => $linked->site_url,
                'da' => 10,
                'dr' => 10,
                'traffic' => 100,
                'country' => 'de',
                'language' => 'de',
                'category' => 'Pending',
                'price' => 50,
                'publication_time' => 'permanent',
                'link_type' => 'dofollow',
                'description' => str_repeat('Placeholder description text. ', 3),
                'verified' => false,
                'active' => false,
                'onboarding_status' => Site::ONBOARDING_AWAITING_DETAILS,
            ]);
            $linked->forceFill(['site_id' => $site->id])->save();

            $this->actingAs($user)
                ->from(route($prefix.'.bulk-site-requests.show', $bulk))
                ->post(route($prefix.'.bulk-site-requests.done', $bulk), [
                    'rejected_item_ids' => [$linked->id],
                    'rejection_note' => 'Trying to drop an already added site.',
                ])
                ->assertRedirect(route($prefix.'.bulk-site-requests.show', $bulk))
                ->assertSessionHas('error');

            $this->assertDatabaseHas('bulk_site_request_items', [
                'id' => $linked->id,
                'site_id' => $site->id,
            ]);
            $this->assertDatabaseHas('sites', ['id' => $site->id]);
            $this->assertDatabaseHas('bulk_site_request_items', ['id' => $pending->id]);
            Mail::assertNothingQueued();
        }
    }

    public function test_complete_row_wins_over_same_id_in_rejected_list(): void
    {
        foreach ($this->staffActors() as [$prefix, $user]) {
            Mail::fake();
            [$bulk, $items] = $this->makeBulkWithItems(1, $prefix.'-win');
            $item = $items[0];

            $this->actingAs($user)
                ->post(route($prefix.'.bulk-site-requests.done', $bulk), [
                    'items' => $this->completeRow($item),
                    'rejected_item_ids' => [$item->id],
                    'rejection_note' => 'Should be ignored because the row is complete.',
                ])
                ->assertRedirect()
                ->assertSessionHas('success');

            $this->assertDatabaseHas('sites', ['domain' => $item->domain]);
            $this->assertNotNull($item->fresh()->site_id);
            Mail::assertQueued(BulkSitesSeededNotification::class, 1);
            Mail::assertNotQueued(BulkSiteItemsRejected::class);
        }
    }

    public function test_empty_unmarked_rows_stay_pending(): void
    {
        foreach ($this->staffActors() as [$prefix, $user]) {
            Mail::fake();
            [$bulk, $items] = $this->makeBulkWithItems(3, $prefix.'-leave');
            [$keep, $drop, $leave] = $items;

            $this->actingAs($user)
                ->post(route($prefix.'.bulk-site-requests.done', $bulk), [
                    'items' => $this->completeRow($keep),
                    'rejected_item_ids' => [$drop->id],
                    'rejection_note' => 'Dropping the middle domain only.',
                ])
                ->assertRedirect()
                ->assertSessionHas('success');

            $this->assertNotNull($keep->fresh()->site_id);
            $this->assertDatabaseMissing('bulk_site_request_items', ['id' => $drop->id]);
            $this->assertNull($leave->fresh()->site_id);
            $this->assertSame(1, $bulk->fresh()->items()->whereNull('site_id')->count());
        }
    }

    public function test_publisher_and_advertiser_cannot_post_done(): void
    {
        [$bulk, $items] = $this->makeBulkWithItems(1, 'guest');

        foreach ([$this->publisher, $this->advertiser] as $user) {
            foreach (['admin', 'marketing'] as $prefix) {
                $this->actingAs($user)
                    ->post(route($prefix.'.bulk-site-requests.done', $bulk), [
                        'rejected_item_ids' => [$items[0]->id],
                        'rejection_note' => 'Outsiders should not be able to reject rows.',
                    ])
                    ->assertForbidden();
            }
        }

        $this->assertDatabaseHas('bulk_site_request_items', ['id' => $items[0]->id]);
    }
}
