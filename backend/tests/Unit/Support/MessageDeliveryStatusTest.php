<?php

namespace Tests\Unit\Support;

use App\Support\MessageDeliveryStatus;
use PHPUnit\Framework\TestCase;

class MessageDeliveryStatusTest extends TestCase
{
    public function test_all_returns_expected_values(): void
    {
        $this->assertSame([
            MessageDeliveryStatus::PENDING,
            MessageDeliveryStatus::SENT,
            MessageDeliveryStatus::DELIVERED,
            MessageDeliveryStatus::READ,
            MessageDeliveryStatus::FAILED,
        ], MessageDeliveryStatus::all());
    }
}
