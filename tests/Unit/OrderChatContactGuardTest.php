<?php

namespace Tests\Unit;

use App\Services\OrderChatContactGuard;
use PHPUnit\Framework\TestCase;

class OrderChatContactGuardTest extends TestCase
{
    private OrderChatContactGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->guard = new OrderChatContactGuard;
    }

    public function test_allows_normal_order_messages_and_urls(): void
    {
        $this->assertFalse($this->guard->isBlocked('Please publish the article today.'));
        $this->assertFalse($this->guard->isBlocked('Live URL: https://example.com/guest-post-12345'));
        $this->assertFalse($this->guard->isBlocked('Can you fix the anchor text on the page?'));
    }

    public function test_blocks_email_share_and_obfuscation(): void
    {
        $this->assertTrue($this->guard->inspect('email me at name@gmail.com')['blocked']);
        $this->assertSame(OrderChatContactGuard::REASON_SHARE, $this->guard->inspect('name@gmail.com')['reason']);
        $this->assertTrue($this->guard->isBlocked('name at gmail dot com'));
        $this->assertTrue($this->guard->isBlocked('mailto:name@example.com'));
        $this->assertTrue($this->guard->isBlocked('name[@]gmail[.]com'));
    }

    public function test_blocks_ask_for_contact(): void
    {
        $ask = $this->guard->inspect("What's your email?");
        $this->assertTrue($ask['blocked']);
        $this->assertSame(OrderChatContactGuard::REASON_ASK, $ask['reason']);

        $this->assertTrue($this->guard->isBlocked('Send me your phone number'));
        $this->assertTrue($this->guard->isBlocked('Can I call you?'));
        $this->assertTrue($this->guard->isBlocked('whatsapp me'));
    }

    public function test_message_for_surfaces_are_everyday_english(): void
    {
        foreach (['article', 'description', 'revision', 'generic'] as $surface) {
            $line = OrderChatContactGuard::messageFor($surface);
            $this->assertNotSame('', $line);
            $this->assertDoesNotMatchRegularExpression('/SQLSTATE|Exception|\.env/i', $line);
        }
    }

    public function test_content_mode_allows_product_mentions_without_handles(): void
    {
        $this->assertFalse($this->guard->isBlocked(
            'This guide covers Telegram marketing and WhatsApp Business tips for brands.',
            OrderChatContactGuard::MODE_CONTENT
        ));
        $this->assertTrue($this->guard->isBlocked(
            'Telegram me @brandhelp after you publish.',
            OrderChatContactGuard::MODE_CONTENT
        ));
        $this->assertTrue($this->guard->isBlocked(
            'Reach us on telegram: @publisherhelp',
            OrderChatContactGuard::MODE_CONTENT
        ));
    }

    public function test_content_mode_allows_seo_copy_about_the_site_and_dates(): void
    {
        $article = 'Published 2024-12-15. A number of brands should contact their audience '
            .'and keep conversations outside the site homepage when they build personal contact with readers.';

        $this->assertFalse($this->guard->isBlocked($article, OrderChatContactGuard::MODE_CONTENT));
        $this->assertTrue($this->guard->isBlocked($article));
    }

    public function test_content_mode_blocks_messenger_links_and_local_phone_cues(): void
    {
        $this->assertTrue($this->guard->isBlocked(
            'Read more at https://t.me/brandhelp after you publish.',
            OrderChatContactGuard::MODE_CONTENT
        ));
        $this->assertTrue($this->guard->isBlocked(
            'Message the editor on https://wa.me/441234567890',
            OrderChatContactGuard::MODE_CONTENT
        ));
        $this->assertTrue($this->guard->isBlocked(
            'Phone 415-555-1234 if the draft needs a change.',
            OrderChatContactGuard::MODE_CONTENT
        ));
        $this->assertFalse($this->guard->isBlocked(
            'Use a contact form and keep a number of follow-ups in 2024-12-15.',
            OrderChatContactGuard::MODE_CONTENT
        ));
        $this->assertTrue($this->guard->isBlocked(
            'Message the editor on t.me/brandhelp after you publish.',
            OrderChatContactGuard::MODE_CONTENT
        ));
        $this->assertTrue($this->guard->isBlocked(
            'Send proofs to wa.me/441234567890',
            OrderChatContactGuard::MODE_CONTENT
        ));
        $this->assertFalse($this->guard->isBlocked(
            'Growth since 2000 2024-12-15 has been steady for a number of brands.',
            OrderChatContactGuard::MODE_CONTENT
        ));
        $this->assertFalse($this->guard->isBlocked(
            '<p>Hero image</p><img src="/storage/articles/hero@2x.png" alt="hero">',
            OrderChatContactGuard::MODE_CONTENT
        ));
        $this->assertTrue($this->guard->isBlocked(
            "What's your email after you publish?",
            OrderChatContactGuard::MODE_CONTENT
        ));
        $this->assertTrue($this->guard->isBlocked(
            'Can I call you about the draft?',
            OrderChatContactGuard::MODE_CONTENT
        ));
    }

    public function test_blocks_phone_share_with_context(): void
    {
        $this->assertTrue($this->guard->isBlocked('My phone is +1 555 123 4567'));
        $this->assertTrue($this->guard->isBlocked('WhatsApp: +441234567890'));
    }
}
