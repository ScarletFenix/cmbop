<?php

namespace Tests\Unit;

use App\Models\EmailCampaign;
use PHPUnit\Framework\TestCase;

class EmailCampaignLoadsTest extends TestCase
{
    public function test_model_parses_without_continue_outside_loop(): void
    {
        $this->assertTrue(class_exists(EmailCampaign::class));
    }
}
