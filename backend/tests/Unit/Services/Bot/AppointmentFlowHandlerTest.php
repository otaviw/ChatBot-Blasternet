<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Bot;

use App\Services\Appointments\AppointmentAvailabilityService;
use App\Services\Appointments\AppointmentBookingService;
use App\Services\Bot\BotFlowRegistry;
use App\Services\Bot\Handlers\AppointmentCancellationFlowHandler;
use App\Services\Bot\Handlers\AppointmentFlowHandler;
use App\Services\Bot\Handlers\AppointmentFlowMessageBuilder;
use Mockery;
use Tests\TestCase;

class AppointmentFlowHandlerTest extends TestCase
{
    public function test_parses_natural_time_inputs_for_slots(): void
    {
        $handler = $this->makeHandler();

        $parseTime = new \ReflectionMethod(AppointmentFlowHandler::class, 'parseTimeInput');
        $parseTime->setAccessible(true);
        $parseAfter = new \ReflectionMethod(AppointmentFlowHandler::class, 'parseSlotAfterTimeInput');
        $parseAfter->setAccessible(true);

        $this->assertSame('16:00', $parseTime->invoke($handler, 'marcar horario amanha as 16'));
        $this->assertSame('16:00', $parseTime->invoke($handler, 'marcar horario amanha às 16'));
        $this->assertSame('15:00', $parseAfter->invoke($handler, 'depois das 15 horas tem horario?'));
        $this->assertSame('15:30', $parseAfter->invoke($handler, 'a partir das 15:30'));
    }

    public function test_filters_slots_by_late_afternoon_and_after_time(): void
    {
        $handler = $this->makeHandler();

        $parsePeriod = new \ReflectionMethod(AppointmentFlowHandler::class, 'parseRequestedSlotPeriod');
        $parsePeriod->setAccessible(true);
        $filter = new \ReflectionMethod(AppointmentFlowHandler::class, 'filterSlotsForCurrentPeriod');
        $filter->setAccessible(true);

        $slots = [
            ['starts_at_local' => '2026-05-22 12:00:00'],
            ['starts_at_local' => '2026-05-22 14:40:00'],
            ['starts_at_local' => '2026-05-22 15:00:00'],
            ['starts_at_local' => '2026-05-22 16:20:00'],
        ];

        $this->assertSame('late_afternoon', $parsePeriod->invoke($handler, 'final da tarde'));

        [$lateAfternoonSlots] = $filter->invoke(
            $handler,
            $slots,
            ['slot_period' => 'late_afternoon'],
            'America/Sao_Paulo'
        );
        $this->assertCount(2, $lateAfternoonSlots);
        $this->assertSame('2026-05-22 15:00:00', $lateAfternoonSlots[0]['starts_at_local']);

        [$afterSlots] = $filter->invoke(
            $handler,
            $slots,
            ['slot_after_time' => '15:00'],
            'America/Sao_Paulo'
        );
        $this->assertCount(2, $afterSlots);
        $this->assertSame('2026-05-22 16:20:00', $afterSlots[1]['starts_at_local']);
    }

    private function makeHandler(): AppointmentFlowHandler
    {
        $registry = Mockery::mock(BotFlowRegistry::class);
        $availability = Mockery::mock(AppointmentAvailabilityService::class);
        $booking = Mockery::mock(AppointmentBookingService::class);

        return new AppointmentFlowHandler(
            $registry,
            $availability,
            $booking,
            new AppointmentFlowMessageBuilder(),
            new AppointmentCancellationFlowHandler($registry),
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
