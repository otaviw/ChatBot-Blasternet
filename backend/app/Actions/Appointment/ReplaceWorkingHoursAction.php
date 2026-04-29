<?php

namespace App\Actions\Appointment;

use App\Models\AppointmentStaffProfile;
use App\Models\AppointmentWorkingHour;
use App\Models\Company;
use Illuminate\Validation\ValidationException;

class ReplaceWorkingHoursAction
{
    /**
     * @param  array<int, array<string, mixed>>  $hours
     */
    public function handle(Company $company, AppointmentStaffProfile $staffProfile, array $hours): void
    {
        AppointmentWorkingHour::query()
            ->where('company_id', (int) $company->id)
            ->where('staff_profile_id', (int) $staffProfile->id)
            ->delete();

        foreach ($hours as $item) {
            $this->validateTimeWindow($item);

            $breakStart = isset($item['break_start_time']) ? trim((string) $item['break_start_time']) : '';
            $breakEnd   = isset($item['break_end_time']) ? trim((string) $item['break_end_time']) : '';
            $this->validateBreak($item, $breakStart, $breakEnd);

            AppointmentWorkingHour::create([
                'company_id'           => (int) $company->id,
                'staff_profile_id'     => (int) $staffProfile->id,
                'day_of_week'          => (int) $item['day_of_week'],
                'start_time'           => (string) $item['start_time'] . ':00',
                'break_start_time'     => $breakStart !== '' ? $breakStart . ':00' : null,
                'break_end_time'       => $breakEnd !== '' ? $breakEnd . ':00' : null,
                'end_time'             => (string) $item['end_time'] . ':00',
                'slot_interval_minutes' => isset($item['slot_interval_minutes'])
                    ? (int) $item['slot_interval_minutes']
                    : null,
                'is_active'            => (bool) ($item['is_active'] ?? true),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function validateTimeWindow(array $item): void
    {
        if ((string) $item['start_time'] >= (string) $item['end_time']) {
            throw ValidationException::withMessages([
                'hours' => ['Cada janela de jornada deve ter inicio menor que fim.'],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function validateBreak(array $item, string $breakStart, string $breakEnd): void
    {
        if (($breakStart === '') !== ($breakEnd === '')) {
            throw ValidationException::withMessages([
                'hours' => ['Informe inicio e fim da pausa, ou deixe ambos vazios.'],
            ]);
        }

        if ($breakStart === '' || $breakEnd === '') {
            return;
        }

        if ($breakStart >= $breakEnd) {
            throw ValidationException::withMessages([
                'hours' => ['A pausa deve ter inicio menor que fim.'],
            ]);
        }

        if ($breakStart <= (string) $item['start_time'] || $breakEnd >= (string) $item['end_time']) {
            throw ValidationException::withMessages([
                'hours' => ['A pausa deve ficar dentro da jornada (entre inicio e fim).'],
            ]);
        }
    }
}
