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

    public function test_blocks_phone_share_with_context(): void
    {
        $this->assertTrue($this->guard->isBlocked('My phone is +1 555 123 4567'));
        $this->assertTrue($this->guard->isBlocked('WhatsApp: +441234567890'));
    }
}
