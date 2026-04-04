<?php

namespace Tests\Unit\Models;

use App\Models\Session;
use Tests\TestCase;

class SessionIsPriorityCategoryTest extends TestCase
{
    public function test_null_override_uses_client_category(): void
    {
        $s = new Session(['client_category' => 'PWD / Senior / Pregnant', 'priority_lane_override' => null]);
        $this->assertTrue($s->isPriorityCategory());

        $s2 = new Session(['client_category' => 'Other: walk-in', 'priority_lane_override' => null]);
        $this->assertFalse($s2->isPriorityCategory());
    }

    public function test_non_null_override_wins_over_category_string(): void
    {
        $s = new Session(['client_category' => 'Other: walk-in', 'priority_lane_override' => true]);
        $this->assertTrue($s->isPriorityCategory());

        $s2 = new Session(['client_category' => 'Other: walk-in', 'priority_lane_override' => false]);
        $this->assertFalse($s2->isPriorityCategory());

        $s3 = new Session(['client_category' => 'Regular', 'priority_lane_override' => true]);
        $this->assertTrue($s3->isPriorityCategory());
    }
}
