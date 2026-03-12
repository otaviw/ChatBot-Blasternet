<?php

namespace Tests\Unit\Support;

use App\Support\ConversationStatus;
use PHPUnit\Framework\TestCase;

class ConversationStatusTest extends TestCase
{
    public function test_all_returns_expected_values(): void
    {
        $this->assertSame([
            ConversationStatus::OPEN,
            ConversationStatus::IN_PROGRESS,
            ConversationStatus::CLOSED,
        ], ConversationStatus::all());
    }
}
