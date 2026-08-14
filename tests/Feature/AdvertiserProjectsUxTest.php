<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Project;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdvertiserProjectsUxTest extends TestCase
{
    use RefreshDatabase;

    private function advertiser(): User
    {
        $role = Role::firstOrCreate(['name' => 'advertiser']);
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    private function publisher(): User
    {
        $role = Role::firstOrCreate(['name' => 'publisher']);
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    private function siteFor(User $publisher): Site
    {
        return Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Projects UX Site',
            'site_url' => 'https://projects-ux.example',
            'domain' => 'projects-ux.example',
            'da' => 30,
            'dr' => 30,
            'traffic' => 1000,
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

    private function makeOrder(User $advertiser, Site $site, array $orderAttrs = [], array $itemAttrs = []): Order
    {
        $order = Order::create(array_merge([
            'user_id' => $advertiser->id,
            'order_number' => 'ORD-PRJ-'.uniqid(),
            'reference_code' => 'REF-PRJ-'.uniqid(),
            'subtotal' => 50,
            'tax' => 0,
            'total_amount' => 50,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => 'pending',
            'paid_at' => now(),
        ], $orderAttrs));

        OrderItem::create(array_merge([
            'order_id' => $order->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'price' => 50,
            'content_link' => 'https://example.com/article.docx',
        ], $itemAttrs));

        return $order->fresh('items');
    }

    private function projectCardHtml(string $html, string $projectName): string
    {
        $needle = e($projectName);
        $pos = strpos($html, $needle);
        $this->assertNotFalse($pos, 'Expected project "'.$projectName.'" in HTML');

        $nextCard = strpos($html, 'col-md-4', $pos + 1);
        $length = $nextCard !== false ? $nextCard - $pos : 12000;

        return substr($html, $pos, max($length, 1));
    }

    private function badgeCount(string $cardHtml, string $title): int
    {
        $this->assertMatchesRegularExpression(
            '/title="'.preg_quote($title, '/').'">\s*(\d+)\s*</',
            $cardHtml,
            'Missing "'.$title.'" badge'
        );
        preg_match('/title="'.preg_quote($title, '/').'">\s*(\d+)\s*</', $cardHtml, $match);

        return (int) $match[1];
    }

    public function test_campaigns_blade_does_not_use_rand_for_badges(): void
    {
        $blade = file_get_contents(resource_path('views/advertiser/campaigns.blade.php'));

        $this->assertIsString($blade);
        $this->assertStringNotContainsString('rand(', $blade);
        $this->assertStringContainsString('data-slb-confirm="This project will be removed', $blade);
    }

    public function test_update_keeps_the_same_project_name(): void
    {
        $user = $this->advertiser();
        $project = Project::create([
            'user_id' => $user->id,
            'project_name' => 'Acme Client',
            'project_url' => 'https://acme.example',
        ]);

        $this->actingAs($user)
            ->from(route('advertiser.projects.index'))
            ->put(route('advertiser.projects.update', $project), [
                'project_name' => 'Acme Client',
                'project_url' => 'https://acme.example',
            ])
            ->assertRedirect(route('advertiser.projects.index'))
            ->assertSessionHas('success')
            ->assertSessionHasNoErrors();

        $this->assertSame('Acme Client', $project->fresh()->project_name);
    }

    public function test_update_rejects_another_of_the_same_users_project_names(): void
    {
        $user = $this->advertiser();
        Project::create([
            'user_id' => $user->id,
            'project_name' => 'First Client',
            'project_url' => 'https://first.example',
        ]);
        $second = Project::create([
            'user_id' => $user->id,
            'project_name' => 'Second Client',
            'project_url' => 'https://second.example',
        ]);

        $this->actingAs($user)
            ->from(route('advertiser.projects.index'))
            ->put(route('advertiser.projects.update', $second), [
                'project_name' => 'First Client',
                'project_url' => 'https://second.example',
            ])
            ->assertRedirect(route('advertiser.projects.index'))
            ->assertSessionHasErrors('project_name');

        $this->assertSame('Second Client', $second->fresh()->project_name);
    }

    public function test_update_allows_a_name_already_used_by_another_advertiser(): void
    {
        $other = $this->advertiser();
        Project::create([
            'user_id' => $other->id,
            'project_name' => 'Shared Name',
            'project_url' => 'https://other-shared.example',
        ]);

        $user = $this->advertiser();
        $project = Project::create([
            'user_id' => $user->id,
            'project_name' => 'My Client',
            'project_url' => 'https://mine.example',
        ]);

        $this->actingAs($user)
            ->from(route('advertiser.projects.index'))
            ->put(route('advertiser.projects.update', $project), [
                'project_name' => 'Shared Name',
                'project_url' => 'https://mine.example',
            ])
            ->assertRedirect(route('advertiser.projects.index'))
            ->assertSessionHas('success')
            ->assertSessionHasNoErrors();

        $this->assertSame('Shared Name', $project->fresh()->project_name);
        $this->assertSame('shared-name-'.$user->id, $project->fresh()->slug);
        $this->assertNotSame($project->fresh()->slug, Project::where('user_id', $other->id)->value('slug'));
    }

    public function test_projects_page_shows_zero_badges_when_no_matching_placements(): void
    {
        $user = $this->advertiser();
        Project::create([
            'user_id' => $user->id,
            'project_name' => 'Empty Client',
            'project_url' => 'https://empty.example',
        ]);

        $html = $this->actingAs($user)
            ->get(route('advertiser.projects.index'))
            ->assertOk()
            ->assertSee('Empty Client', false)
            ->getContent();

        $card = $this->projectCardHtml($html, 'Empty Client');
        $this->assertSame(0, $this->badgeCount($card, 'Not started'));
        $this->assertSame(0, $this->badgeCount($card, 'In progress'));
        $this->assertSame(0, $this->badgeCount($card, 'Waiting approval'));
        $this->assertSame(0, $this->badgeCount($card, 'Needs improvements'));
        $this->assertSame(0, $this->badgeCount($card, 'Completed'));
        $this->assertSame(0, $this->badgeCount($card, 'Rejected'));
    }

    public function test_projects_page_counts_placements_by_target_url_host(): void
    {
        $user = $this->advertiser();
        $other = $this->advertiser();
        $site = $this->siteFor($this->publisher());

        Project::create([
            'user_id' => $user->id,
            'project_name' => 'Acme Client',
            'project_url' => 'https://acme.example',
        ]);
        Project::create([
            'user_id' => $user->id,
            'project_name' => 'Beta Client',
            'project_url' => 'https://beta.example',
        ]);

        $this->makeOrder($user, $site, [
            'status' => 'completed',
            'payment_status' => 'paid',
        ], [
            'target_url' => 'https://www.acme.example/blog/post',
        ]);
        $this->makeOrder($user, $site, [
            'status' => 'processing',
            'payment_status' => 'paid',
        ], [
            'target_url' => 'https://beta.example/landing',
        ]);
        $this->makeOrder($user, $site, [
            'status' => 'review',
            'payment_status' => 'paid',
        ], [
            'target_url' => 'https://unrelated.example/page',
        ]);
        $this->makeOrder($other, $site, [
            'status' => 'completed',
            'payment_status' => 'paid',
        ], [
            'target_url' => 'https://acme.example/other-user',
        ]);

        $html = $this->actingAs($user)
            ->get(route('advertiser.projects.index'))
            ->assertOk()
            ->getContent();

        $acme = $this->projectCardHtml($html, 'Acme Client');
        $this->assertSame(0, $this->badgeCount($acme, 'Not started'));
        $this->assertSame(0, $this->badgeCount($acme, 'In progress'));
        $this->assertSame(0, $this->badgeCount($acme, 'Waiting approval'));
        $this->assertSame(0, $this->badgeCount($acme, 'Needs improvements'));
        $this->assertSame(1, $this->badgeCount($acme, 'Completed'));
        $this->assertSame(0, $this->badgeCount($acme, 'Rejected'));

        $beta = $this->projectCardHtml($html, 'Beta Client');
        $this->assertSame(0, $this->badgeCount($beta, 'Not started'));
        $this->assertSame(1, $this->badgeCount($beta, 'In progress'));
        $this->assertSame(0, $this->badgeCount($beta, 'Waiting approval'));
        $this->assertSame(0, $this->badgeCount($beta, 'Needs improvements'));
        $this->assertSame(0, $this->badgeCount($beta, 'Completed'));
        $this->assertSame(0, $this->badgeCount($beta, 'Rejected'));
    }
}
