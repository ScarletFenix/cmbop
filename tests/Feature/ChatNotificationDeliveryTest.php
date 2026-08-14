<?php

namespace Tests\Feature;

use App\Mail\NewChatMessageNotification;
use App\Models\InAppNotification;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Sending a chat message has to reach the other side three ways: the bell, the
 * unread badge in the header, and an email. The first two happen in-request; the
 * email is queued, which is why it never arrived while nothing drained the queue.
 */
class ChatNotificationDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private function userWithRole(string $role): User
    {
        $roleModel = Role::firstOrCreate(['name' => $role]);
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $roleModel->id,
        ]);
        $user->roles()->attach($roleModel->id);

        return $user->fresh();
    }

    private function siteFor(User $publisher): Site
    {
        return Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Chat Notify Site',
            'site_url' => 'https://chat-notify.example',
            'domain' => 'chat-notify.example',
            'da' => 30,
            'dr' => 30,
            'traffic' => 900,
            'country' => 'us',
            'language' => 'en',
            'countries' => ['us'],
            'languages' => ['en'],
            'category' => 'marketing',
            'price' => 50,
            'publication_time' => '5 days',
            'link_type' => 'dofollow',
            'description' => 'Test site',
            'verified' => true,
            'active' => true,
        ]);
    }

    private function order(User $advertiser, Site $site): Order
    {
        $order = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => 'ORD-CHATN-'.uniqid(),
            'reference_code' => 'REF-CHATN-'.uniqid(),
            'subtotal' => 50,
            'tax' => 0,
            'total_amount' => 50,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => 'processing',
            'paid_at' => now(),
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'price' => 50,
            'content_link' => 'https://example.com/article.docx',
        ]);

        return $order->fresh('items');
    }

    public function test_a_chat_message_raises_a_bell_for_the_other_side(): void
    {
        Mail::fake();
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $order = $this->order($advertiser, $this->siteFor($publisher));

        $this->actingAs($advertiser)
            ->postJson('/chat/send/'.$order->id, ['message' => 'Any update on the draft?'])
            ->assertOk()
            ->assertJsonPath('success', true);

        $note = InAppNotification::where('user_id', $publisher->id)->latest('id')->first();

        $this->assertNotNull($note, 'The publisher needs a bell for a new chat message.');
        $this->assertStringContainsString($order->order_number, $note->title);
        $this->assertStringContainsString('Any update on the draft?', (string) $note->message);
        $this->assertSame(InAppNotification::STATUS_UNREAD, $note->status);
    }

    public function test_the_header_badge_counts_the_unread_message(): void
    {
        Mail::fake();
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $order = $this->order($advertiser, $this->siteFor($publisher));

        $this->actingAs($advertiser)
            ->postJson('/chat/send/'.$order->id, ['message' => 'Ping from the advertiser.'])
            ->assertOk();

        $this->actingAs($publisher)
            ->getJson(route('chat.unread-summary'))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('unread_chat', 1);
    }

    public function test_the_chat_email_is_queued_for_the_other_side(): void
    {
        Mail::fake();
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $order = $this->order($advertiser, $this->siteFor($publisher));

        $this->actingAs($advertiser)
            ->postJson('/chat/send/'.$order->id, ['message' => 'Emailed question about the brief.'])
            ->assertOk();

        Mail::assertQueued(
            NewChatMessageNotification::class,
            fn (NewChatMessageNotification $mail) => $mail->hasTo($publisher->email)
        );
    }

    public function test_the_queued_chat_email_actually_leaves_the_queue(): void
    {
        // Build fixtures on the sync queue so welcome/admin/enrichment mail
        // is not sitting in front of the one chat job. Web drain only takes
        // five jobs per request; older mail would otherwise leave the chat
        // email behind (attempts=0) and look like drain is broken.
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $order = $this->order($advertiser, $this->siteFor($publisher));

        config([
            'queue.default' => 'database',
            'email_notifications.queue_connection' => 'database',
            'email_notifications.auto_drain' => true,
        ]);

        // Deliberately not Mail::fake(): the point is that the job is really
        // pushed onto the database queue and then really consumed.
        $this->actingAs($advertiser)
            ->postJson('/chat/send/'.$order->id, ['message' => 'This one should actually arrive.'])
            ->assertOk();

        // The drain runs after the response, so the same request that queued the
        // mail also delivers it. Nothing is left behind and nothing failed.
        $this->assertSame(0, DB::table('jobs')->count(), 'The chat email should not be left sitting on the queue.');
        $this->assertSame(0, DB::table('failed_jobs')->count());

        $subjects = collect(Mail::mailer()->getSymfonyTransport()->messages())
            ->map(fn ($sent) => $sent->getOriginalMessage()->getSubject());

        $this->assertTrue(
            $subjects->contains(fn (?string $subject) => str_contains((string) $subject, $order->order_number)),
            'Expected the chat email to be handed to the mailer. Got: '.$subjects->implode(' | ')
        );
    }

    public function test_a_backlogged_chat_email_is_delivered_by_ordinary_traffic(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $order = $this->order($advertiser, $this->siteFor($publisher));

        config([
            'queue.default' => 'database',
            'email_notifications.queue_connection' => 'database',
            'email_notifications.auto_drain' => false,
        ]);

        // Drain off: this is the state the deployment was in, with mail piling up.
        $this->actingAs($advertiser)
            ->postJson('/chat/send/'.$order->id, ['message' => 'Queued while nothing was draining.'])
            ->assertOk();

        $this->assertGreaterThan(0, DB::table('jobs')->count());

        config(['email_notifications.auto_drain' => true]);

        // The publisher simply opening a page is enough to consume the backlog.
        $this->actingAs($publisher)->get('/')->assertSuccessful();

        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(0, DB::table('failed_jobs')->count());
    }

    public function test_a_publisher_reply_notifies_the_advertiser(): void
    {
        Mail::fake();
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $order = $this->order($advertiser, $this->siteFor($publisher));

        $this->actingAs($publisher)
            ->postJson('/chat/send/'.$order->id, ['message' => 'Publishing tomorrow morning.'])
            ->assertOk();

        $note = InAppNotification::where('user_id', $advertiser->id)->latest('id')->first();
        $this->assertNotNull($note);
        $this->assertStringContainsString('Publishing tomorrow morning.', (string) $note->message);

        Mail::assertQueued(
            NewChatMessageNotification::class,
            fn (NewChatMessageNotification $mail) => $mail->hasTo($advertiser->email)
        );
    }
}
