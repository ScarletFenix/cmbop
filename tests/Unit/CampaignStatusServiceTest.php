<?php

namespace Tests\Unit;

use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Campaign\CampaignStatusService;
use Tests\TestCase;

class CampaignStatusServiceTest extends TestCase
{
    public function test_bucket_mapping_for_pipeline_states(): void
    {
        $service = new CampaignStatusService;

        $completed = new OrderItem(['publisher_status' => 'completed']);
        $completed->setRelation('order', new Order(['status' => 'processing']));
        $this->assertSame('completed', $service->bucketFor($completed));

        $review = new OrderItem(['publisher_status' => 'accepted', 'modification_requested' => null]);
        $review->setRelation('order', new Order(['status' => 'review']));
        $this->assertSame('waiting_approval', $service->bucketFor($review));

        $mods = new OrderItem(['publisher_status' => 'accepted', 'modification_requested' => 'yes']);
        $mods->setRelation('order', new Order(['status' => 'processing']));
        $this->assertSame('needs_improvements', $service->bucketFor($mods));

        $rejected = new OrderItem(['publisher_status' => 'rejected']);
        $rejected->setRelation('order', new Order(['status' => 'processing']));
        $this->assertSame('rejected', $service->bucketFor($rejected));

        $progress = new OrderItem(['publisher_status' => 'accepted']);
        $progress->setRelation('order', new Order(['status' => 'processing']));
        $this->assertSame('in_progress', $service->bucketFor($progress));

        $pending = new OrderItem(['publisher_status' => 'pending']);
        $pending->setRelation('order', new Order(['status' => 'pending']));
        $this->assertSame('not_started', $service->bucketFor($pending));
    }
}
