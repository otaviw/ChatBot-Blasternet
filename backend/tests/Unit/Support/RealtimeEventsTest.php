<?php

namespace Tests\Unit\Support;

use App\Support\RealtimeEvents;
use PHPUnit\Framework\TestCase;

class RealtimeEventsTest extends TestCase
{
    public function test_all_contains_expected_events(): void
    {
        $events = RealtimeEvents::all();

        $this->assertContains(RealtimeEvents::MESSAGE_CREATED, $events);
        $this->assertContains(RealtimeEvents::MESSAGE_UPDATED, $events);
        $this->assertContains(RealtimeEvents::MESSAGE_STATUS_UPDATED, $events);
        $this->assertContains(RealtimeEvents::MESSAGE_REACTIONS_UPDATED, $events);
        $this->assertContains(RealtimeEvents::BOT_UPDATED, $events);
        $this->assertContains(RealtimeEvents::CONVERSATION_TRANSFERRED, $events);
        $this->assertContains(RealtimeEvents::NOTIFICATION_CREATED, $events);
        $this->assertContains(RealtimeEvents::CAMPAIGN_UPDATED, $events);
    }
}
