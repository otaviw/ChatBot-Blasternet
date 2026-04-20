<?php

namespace App\Actions\Appointment;

use App\Models\Appointment;
use App\Models\Company;
use App\Models\User;
use App\Services\Appointments\AppointmentBookingService;

class BookAppointmentAction
{
    public function __construct(
        private readonly AppointmentBookingService $bookingService
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(Company $company, array $payload, ?User $actor): Appointment
    {
        return $this->bookingService->createAppointment($company, $payload, $actor);
    }
}

