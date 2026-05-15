<?php

namespace Tests\Unit\Services;

use App\Services\MetaNumberObservabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class MetaNumberObservabilityServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_fallback_rate_and_invalid_contact_rate_are_computed(): void
    {
        Cache::flush();
        $service = app(MetaNumberObservabilityService::class);

        $service->logSendResolution(10, 100, 200, null, 300, false);
        $service->logSendResolution(10, 101, 201, null, 301, true);
        $service->logInvalidContactNumber(10, 102, 202, null);

        $this->assertEquals(50.0, $service->fallbackRate(10));
        $this->assertEquals(50.0, $service->invalidContactRate(10));
    }

    public function test_alerts_are_logged_for_company_without_active_number_and_failure_spike(): void
    {
        Cache::flush();
        Log::spy();
        $service = app(MetaNumberObservabilityService::class);

        $service->alertCompanyWithoutActiveNumber(20);

        for ($i = 0; $i < 20; $i++) {
            $service->logInvalidContactNumber(20, 1000 + $i, null, 77);
        }

        Log::shouldHaveReceived('warning')->with(
            'meta_number.company_without_active_numbers',
            \Mockery::type('array')
        )->atLeast()->once();

        Log::shouldHaveReceived('error')->with(
            'meta_number.no_active_number_failure_spike',
            \Mockery::type('array')
        )->atLeast()->once();
    }
}

