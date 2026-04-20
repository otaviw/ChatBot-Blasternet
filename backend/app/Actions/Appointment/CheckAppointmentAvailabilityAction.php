<?php

namespace App\Actions\Appointment;

use App\Models\Company;
use App\Services\Appointments\AppointmentAvailabilityService;

class CheckAppointmentAvailabilityAction
{
    public function __construct(
        private readonly AppointmentAvailabilityService $availabilityService
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(Company $company, int $serviceId, string $date, ?int $staffProfileId): array
    {
        return $this->availabilityService->listAvailableSlots(
            $company,
            $serviceId,
            $date,
            $staffProfileId
        );
    }
}

