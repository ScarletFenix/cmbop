<?php

namespace Tests\Unit;

use App\Support\CommunityInbox;
use Tests\TestCase;

class CommunityInboxTest extends TestCase
{
    public function test_claims_include_approved_not_accepted(): void
    {
        $this->assertContains('approved', CommunityInbox::statusesFor('claims'));
        $this->assertNotContains('accepted', CommunityInbox::statusesFor('claims'));
        $this->assertNotContains('resolved', CommunityInbox::statusesFor('claims'));
        $this->assertNull(CommunityInbox::normalizeStatus('claims', 'accepted'));
        $this->assertSame('approved', CommunityInbox::normalizeStatus('claims', 'approved'));
    }

    public function test_problems_and_suggestions_use_resolved_not_accepted(): void
    {
        foreach (['problems', 'suggestions'] as $tab) {
            $this->assertContains('resolved', CommunityInbox::statusesFor($tab));
            $this->assertNotContains('accepted', CommunityInbox::statusesFor($tab));
            $this->assertNotContains('approved', CommunityInbox::statusesFor($tab));
            $this->assertNull(CommunityInbox::normalizeStatus($tab, 'accepted'));
            $this->assertNull(CommunityInbox::normalizeStatus($tab, 'approved'));
        }
    }

    public function test_websites_use_accepted_not_approved(): void
    {
        $this->assertContains('accepted', CommunityInbox::statusesFor('websites'));
        $this->assertNotContains('approved', CommunityInbox::statusesFor('websites'));
        $this->assertNotContains('resolved', CommunityInbox::statusesFor('websites'));
        $this->assertSame('accepted', CommunityInbox::normalizeStatus('websites', 'accepted'));
        $this->assertNull(CommunityInbox::normalizeStatus('websites', 'approved'));
    }

    public function test_tab_query_drops_status_that_is_illegal_on_the_target_tab(): void
    {
        $this->assertSame(
            ['tab' => 'problems'],
            CommunityInbox::tabQuery('problems', null, 'approved')
        );
        $this->assertSame(
            ['tab' => 'claims', 'status' => 'approved'],
            CommunityInbox::tabQuery('claims', null, 'approved')
        );
        $this->assertSame(
            ['tab' => 'problems', 'q' => 'checkout'],
            CommunityInbox::tabQuery('problems', 'checkout', 'accepted')
        );
        $this->assertSame(
            ['tab' => 'websites', 'q' => 'saas', 'status' => 'pending'],
            CommunityInbox::tabQuery('websites', 'saas', 'pending')
        );
    }

    public function test_unknown_tab_and_array_status_are_ignored(): void
    {
        $this->assertSame('problems', CommunityInbox::normalizeTab('nope'));
        $this->assertSame('problems', CommunityInbox::normalizeTab(['injected']));
        $this->assertNull(CommunityInbox::normalizeStatus('problems', ['pending']));
        $this->assertNull(CommunityInbox::normalizeStatus('problems', ''));
    }
}
