<?php

namespace App\Services\Appointments;

use App\Models\Appointment;
use App\Models\AppointmentService;
use App\Models\AppointmentSetting;
use App\Models\AppointmentStaffProfile;
use App\Models\AppointmentTimeOff;
use App\Models\AppointmentWorkingHour;
use App\Models\Company;
use App\Support\AppointmentStatus;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class AppointmentAvailabilityService
{
    /**
     * @return array{
     *   date: string,
     *   timezone: string,
     *   service: array{id:int,name:string,duration_minutes:int,buffer_before_minutes:int,buffer_after_minutes:int,max_bookings_per_slot:int},
     *   staff: array<int, array{staff_profile_id:int,user_id:int,staff_name:string,slot_interval_minutes:int,slots:array<int, array{starts_at:string,ends_at:string,starts_at_local:string,ends_at_local:string}>}>
     * }
     */
    public function listAvailableSlots(
        Company $company,
        int $serviceId,
        CarbonInterface|string $date,
        ?int $staffProfileId = null
    ): array {
        $settings = $this->resolveSettings((int) $company->id);
        $timezone = $this->resolveTimezone($settings);
        $targetDate = $this->parseDate($date, $timezone)->startOfDay();
        $service = $this->loadService((int) $company->id, $serviceId);

        $staffProfiles = AppointmentStaffProfile::query()
            ->where('company_id', (int) $company->id)
            ->where('is_bookable', true)
            ->when($staffProfileId, fn($query) => $query->where('id', $staffProfileId))
            ->with('user:id,name')
            ->orderBy('id')
            ->get();

        if ($staffProfiles->isEmpty()) {
            return [
                'date' => $targetDate->toDateString(),
                'timezone' => $timezone,
                'service' => $this->serializeService($service),
                'staff' => [],
            ];
        }

        $dayOfWeek = $targetDate->dayOfWeek;

        $workingHoursByStaff = AppointmentWorkingHour::query()
            ->where('company_id', (int) $company->id)
            ->whereIn('staff_profile_id', $staffProfiles->pluck('id')->all())
            ->where('day_of_week', $dayOfWeek)
            ->where('is_active', true)
            ->orderBy('start_time')
            ->get()
            ->groupBy('staff_profile_id');

        $staffAvailability = [];
        foreach ($staffProfiles as $staffProfile) {
            $staffAvailability[] = $this->buildStaffAvailability(
                $company,
                $targetDate,
                $timezone,
                $settings,
                $service,
                $staffProfile,
                $workingHoursByStaff->get((int) $staffProfile->id, collect())
            );
        }

        return [
            'date' => $targetDate->toDateString(),
            'timezone' => $timezone,
            'service' => $this->serializeService($service),
            'staff' => $staffAvailability,
        ];
    }

    /**
     * @return array{
     *   starts_at_utc: CarbonImmutable,
     *   ends_at_utc: CarbonImmutable,
     *   effective_start_at_utc: CarbonImmutable,
     *   effective_end_at_utc: CarbonImmutable,
     *   starts_at_local: CarbonImmutable,
     *   ends_at_local: CarbonImmutable,
     *   timezone: string,
     *   slot_interval_minutes: int
     * }
     */
    public function assertSlotIsAvailable(
        Company $company,
        AppointmentService $service,
        AppointmentStaffProfile $staffProfile,
        CarbonInterface|string $startsAt,
        bool $lockConflicts = false
    ): array {
        if (! $staffProfile->is_bookable) {
            throw ValidationException::withMessages([
                'staff_profile_id' => ['Atendente indisponivel para agendamento.'],
            ]);
        }

        $settings = $this->resolveSettings((int) $company->id);
        $timezone = $this->resolveTimezone($settings);
        $startLocal = $this->parseDateTime($startsAt, $timezone);
        $endLocal = $startLocal->addMinutes((int) $service->duration_minutes);
        $slotInterval = $this->resolveSlotIntervalForSlot(
            (int) $company->id,
            $settings,
            $staffProfile,
            $startLocal,
            $endLocal
        );
        $minNoticeMinutes = $this->resolveMinNoticeMinutes($settings, $staffProfile);
        $maxAdvanceDays = $this->resolveMaxAdvanceDays($settings, $staffProfile);

        $effectiveStartLocal = $startLocal->subMinutes((int) $service->buffer_before_minutes);
        $effectiveEndLocal = $endLocal->addMinutes((int) $service->buffer_after_minutes);

        $this->assertRespectsNotice($startLocal, $timezone, $minNoticeMinutes);
        $this->assertRespectsAdvanceLimit($startLocal, $timezone, $maxAdvanceDays);
        $this->assertInsideWorkingHours((int) $company->id, $staffProfile, $startLocal, $endLocal);

        $startUtc = $startLocal->setTimezone('UTC');
        $endUtc = $endLocal->setTimezone('UTC');
        $effectiveStartUtc = $effectiveStartLocal->setTimezone('UTC');
        $effectiveEndUtc = $effectiveEndLocal->setTimezone('UTC');

        $hasTimeOff = AppointmentTimeOff::query()
            ->where('company_id', (int) $company->id)
            ->where(function ($query) use ($staffProfile) {
                $query->whereNull('staff_profile_id')
                    ->orWhere('staff_profile_id', (int) $staffProfile->id);
            })
            ->where('starts_at', '<', $endUtc)
            ->where('ends_at', '>', $startUtc)
            ->exists();

        if ($hasTimeOff) {
            throw ValidationException::withMessages([
                'starts_at' => ['Horario indisponivel por bloqueio da agenda.'],
            ]);
        }

        $overlapQuery = Appointment::query()
            ->where('company_id', (int) $company->id)
            ->where('staff_profile_id', (int) $staffProfile->id)
            ->whereIn('status', AppointmentStatus::blocking())
            ->where('effective_start_at', '<', $effectiveEndUtc)
            ->where('effective_end_at', '>', $effectiveStartUtc);

        $maxBookingsPerSlot = max(1, (int) $service->max_bookings_per_slot);
        if ($lockConflicts) {
            $overlapCount = $overlapQuery
                ->select(['id'])
                ->limit($maxBookingsPerSlot)
                ->lockForUpdate()
                ->get()
                ->count();
        } else {
            $overlapCount = $overlapQuery->count();
        }
        if ($overlapCount >= $maxBookingsPerSlot) {
            throw ValidationException::withMessages([
                'starts_at' => ['Horario indisponivel. Escolha outro horario.'],
            ]);
        }

        return [
            'starts_at_utc' => $startUtc,
            'ends_at_utc' => $endUtc,
            'effective_start_at_utc' => $effectiveStartUtc,
            'effective_end_at_utc' => $effectiveEndUtc,
            'starts_at_local' => $startLocal,
            'ends_at_local' => $endLocal,
            'timezone' => $timezone,
            'slot_interval_minutes' => $slotInterval,
        ];
    }

    /**
     * Returns a flat list of available slots across multiple days using exactly 6 DB queries,
     * regardless of the number of days or slots (bulk-loads conflicts then filters in-memory).
     *
     * @return array<int, array{starts_at:string,ends_at:string,starts_at_local:string,ends_at_local:string,staff_profile_id:int,staff_name:string,date:string}>
     */
    public function listAvailableSlotsMultiDay(
        Company $company,
        int $serviceId,
        CarbonInterface|string $fromDate,
        CarbonInterface|string $toDate,
        ?int $staffProfileId = null,
        int $limit = PHP_INT_MAX
    ): array {
        $settings = $this->resolveSettings((int) $company->id);
        $timezone = $this->resolveTimezone($settings);
        $from = $this->parseDate($fromDate, $timezone)->startOfDay();
        $to = $this->parseDate($toDate, $timezone)->endOfDay();
        $service = $this->loadService((int) $company->id, $serviceId);

        $staffProfiles = AppointmentStaffProfile::query()
            ->where('company_id', (int) $company->id)
            ->where('is_bookable', true)
            ->when($staffProfileId, fn($q) => $q->where('id', $staffProfileId))
            ->with('user:id,name')
            ->orderBy('id')
            ->get();

        if ($staffProfiles->isEmpty()) {
            return [];
        }

        $staffIds = $staffProfiles->pluck('id')->all();

        // 1 query — all working hours for all staff, all days of week
        $allWorkingHours = AppointmentWorkingHour::query()
            ->where('company_id', (int) $company->id)
            ->whereIn('staff_profile_id', $staffIds)
            ->where('is_active', true)
            ->orderBy('start_time')
            ->get()
            ->groupBy('staff_profile_id');

        $rangeStartUtc = $from->setTimezone('UTC');
        $rangeEndUtc = $to->setTimezone('UTC');

        // 1 query — time-offs overlapping the range
        $timeOffs = AppointmentTimeOff::query()
            ->where('company_id', (int) $company->id)
            ->where(function ($q) use ($staffIds) {
                $q->whereNull('staff_profile_id')
                    ->orWhereIn('staff_profile_id', $staffIds);
            })
            ->where('starts_at', '<', $rangeEndUtc)
            ->where('ends_at', '>', $rangeStartUtc)
            ->get(['staff_profile_id', 'starts_at', 'ends_at'])
            ->map(fn($tf) => [
                'staff_profile_id' => $tf->staff_profile_id !== null ? (int) $tf->staff_profile_id : null,
                'starts_ts' => CarbonImmutable::parse($tf->starts_at)->timestamp,
                'ends_ts' => CarbonImmutable::parse($tf->ends_at)->timestamp,
            ]);

        // 1 query — blocking appointments overlapping the range
        $existingAppointments = Appointment::query()
            ->where('company_id', (int) $company->id)
            ->whereIn('staff_profile_id', $staffIds)
            ->whereIn('status', AppointmentStatus::blocking())
            ->where('effective_start_at', '<', $rangeEndUtc)
            ->where('effective_end_at', '>', $rangeStartUtc)
            ->get(['staff_profile_id', 'effective_start_at', 'effective_end_at'])
            ->map(fn($appt) => [
                'staff_profile_id' => (int) $appt->staff_profile_id,
                'effective_start_ts' => CarbonImmutable::parse($appt->effective_start_at)->timestamp,
                'effective_end_ts' => CarbonImmutable::parse($appt->effective_end_at)->timestamp,
            ]);

        $maxBookingsPerSlot = max(1, (int) $service->max_bookings_per_slot);
        $durationMinutes = (int) $service->duration_minutes;
        $bufferBefore = (int) $service->buffer_before_minutes;
        $bufferAfter = (int) $service->buffer_after_minutes;
        $now = CarbonImmutable::now($timezone);

        $result = [];
        $date = $from->startOfDay();

        while ($date->lte($to) && count($result) < $limit) {
            $dayOfWeek = $date->dayOfWeek;

            foreach ($staffProfiles as $staffProfile) {
                if (count($result) >= $limit) {
                    break;
                }

                $staffId = (int) $staffProfile->id;
                $minNotice = $this->resolveMinNoticeMinutes($settings, $staffProfile);
                $maxAdvance = $this->resolveMaxAdvanceDays($settings, $staffProfile);

                if (! $this->isDateWithinAdvanceWindow($date, $timezone, $maxAdvance)) {
                    continue;
                }

                $staffWorkingHours = ($allWorkingHours->get($staffId) ?? collect())
                    ->filter(fn($wh) => (int) $wh->day_of_week === $dayOfWeek);

                foreach ($staffWorkingHours as $wh) {
                    if (count($result) >= $limit) {
                        break;
                    }

                    $slotInterval = $this->resolveSlotInterval($settings, $staffProfile, $wh);
                    $segments = $this->resolveWorkingSegments($date, $timezone, $wh);

                    foreach ($segments as [$segStart, $segEnd]) {
                        if ($segEnd->lte($segStart)) {
                            continue;
                        }

                        $cursor = $this->roundUpToSlot(
                            max($segStart->timestamp, $now->addMinutes($minNotice)->timestamp),
                            $timezone,
                            $slotInterval
                        );

                        while ($cursor->lt($segEnd) && count($result) < $limit) {
                            $slotEnd = $cursor->addMinutes($durationMinutes);
                            if ($slotEnd->gt($segEnd)) {
                                break;
                            }

                            $slotStartTs = $cursor->setTimezone('UTC')->timestamp;
                            $slotEndTs = $slotEnd->setTimezone('UTC')->timestamp;
                            $effectiveStartTs = $cursor->subMinutes($bufferBefore)->setTimezone('UTC')->timestamp;
                            $effectiveEndTs = $slotEnd->addMinutes($bufferAfter)->setTimezone('UTC')->timestamp;

                            $blockedByTimeOff = $timeOffs->contains(function ($tf) use ($slotStartTs, $slotEndTs, $staffId) {
                                if ($tf['staff_profile_id'] !== null && $tf['staff_profile_id'] !== $staffId) {
                                    return false;
                                }
                                return $tf['starts_ts'] < $slotEndTs && $tf['ends_ts'] > $slotStartTs;
                            });

                            if (! $blockedByTimeOff) {
                                $conflicts = $existingAppointments->filter(function ($appt) use ($effectiveStartTs, $effectiveEndTs, $staffId) {
                                    return $appt['staff_profile_id'] === $staffId
                                        && $appt['effective_start_ts'] < $effectiveEndTs
                                        && $appt['effective_end_ts'] > $effectiveStartTs;
                                })->count();

                                if ($conflicts < $maxBookingsPerSlot) {
                                    $result[] = [
                                        'starts_at' => $cursor->setTimezone('UTC')->toIso8601String(),
                                        'ends_at' => $slotEnd->setTimezone('UTC')->toIso8601String(),
                                        'starts_at_local' => $cursor->toIso8601String(),
                                        'ends_at_local' => $slotEnd->toIso8601String(),
                                        'staff_profile_id' => $staffId,
                                        'staff_name' => trim((string) ($staffProfile->display_name ?: $staffProfile->user?->name ?: 'Atendente')),
                                        'date' => $date->toDateString(),
                                    ];
                                    $cursor = $cursor->addMinutes($durationMinutes + $slotInterval);
                                    continue;
                                }
                            }

                            $cursor = $cursor->addMinutes($slotInterval);
                        }
                    }
                }
            }

            $date = $date->addDay();
        }

        return $result;
    }

    private function buildStaffAvailability(
        Company $company,
        CarbonImmutable $targetDate,
        string $timezone,
        AppointmentSetting $settings,
        AppointmentService $service,
        AppointmentStaffProfile $staffProfile,
        Collection $workingHours
    ): array {
        $slotInterval = $this->resolveSlotInterval($settings, $staffProfile);
        $minNoticeMinutes = $this->resolveMinNoticeMinutes($settings, $staffProfile);
        $maxAdvanceDays = $this->resolveMaxAdvanceDays($settings, $staffProfile);

        if (! $this->isDateWithinAdvanceWindow($targetDate, $timezone, $maxAdvanceDays)) {
            return $this->emptyStaffAvailability($staffProfile, $slotInterval);
        }

        $availableSlots = [];

        foreach ($workingHours as $workingHour) {
            $windowInterval = $this->resolveSlotInterval($settings, $staffProfile, $workingHour);
            $windowStart = CarbonImmutable::parse(
                $targetDate->toDateString() . ' ' . (string) $workingHour->start_time,
                $timezone
            );
            $windowEnd = CarbonImmutable::parse(
                $targetDate->toDateString() . ' ' . (string) $workingHour->end_time,
                $timezone
            );

            if ($windowEnd->lte($windowStart)) {
                continue;
            }

            $segments = $this->resolveWorkingSegments($targetDate, $timezone, $workingHour);
            foreach ($segments as [$segmentStart, $segmentEnd]) {
                if ($segmentEnd->lte($segmentStart)) {
                    continue;
                }

                $earliestAllowed = $this->roundUpToSlot(
                    max($segmentStart->timestamp, now($timezone)->addMinutes($minNoticeMinutes)->timestamp),
                    $timezone,
                    $windowInterval
                );
                $cursor = $earliestAllowed->gt($segmentStart) ? $earliestAllowed : $segmentStart;
                $cursor = $this->roundUpToSlot($cursor->timestamp, $timezone, $windowInterval);

                while ($cursor->lt($segmentEnd)) {
                    $slotEnd = $cursor->addMinutes((int) $service->duration_minutes);
                    if ($slotEnd->gt($segmentEnd)) {
                        break;
                    }

                    $addedSlot = false;
                    try {
                        $slot = $this->assertSlotIsAvailable(
                            $company,
                            $service,
                            $staffProfile,
                            $cursor,
                            false
                        );

                        $availableSlots[] = [
                            'starts_at' => $slot['starts_at_utc']->toIso8601String(),
                            'ends_at' => $slot['ends_at_utc']->toIso8601String(),
                            'starts_at_local' => $slot['starts_at_local']->toIso8601String(),
                            'ends_at_local' => $slot['ends_at_local']->toIso8601String(),
                        ];
                        $addedSlot = true;
                    } catch (ValidationException) {
                        // Slot indisponivel pelas regras do motor de agenda.
                    }

                    if ($addedSlot) {
                        $cursor = $cursor->addMinutes((int) $service->duration_minutes + $windowInterval);
                        continue;
                    }

                    $cursor = $cursor->addMinutes($windowInterval);
                }
            }
        }

        return [
            'staff_profile_id' => (int) $staffProfile->id,
            'user_id' => (int) $staffProfile->user_id,
            'staff_name' => trim((string) ($staffProfile->display_name ?: $staffProfile->user?->name ?: 'Atendente')),
            'slot_interval_minutes' => $slotInterval,
            'slots' => $availableSlots,
        ];
    }

    private function assertSlotMatchesInterval(CarbonImmutable $startsAt, int $interval): void
    {
        $minutesOfDay = ((int) $startsAt->hour * 60) + (int) $startsAt->minute;
        $matchesMinute = $minutesOfDay % $interval === 0;
        if (! $matchesMinute || (int) $startsAt->second !== 0) {
            throw ValidationException::withMessages([
                'starts_at' => ["Horario inválido. Use intervalos de {$interval} minutos."],
            ]);
        }
    }

    private function assertRespectsNotice(CarbonImmutable $startsAt, string $timezone, int $minNoticeMinutes): void
    {
        $minimumAllowed = now($timezone)->addMinutes($minNoticeMinutes);
        if ($startsAt->lt($minimumAllowed)) {
            throw ValidationException::withMessages([
                'starts_at' => ['Horario inválido por antecedencia minima de agendamento.'],
            ]);
        }
    }

    private function assertRespectsAdvanceLimit(CarbonImmutable $startsAt, string $timezone, int $maxAdvanceDays): void
    {
        $maximumAllowed = now($timezone)->addDays($maxAdvanceDays)->endOfDay();
        if ($startsAt->gt($maximumAllowed)) {
            throw ValidationException::withMessages([
                'starts_at' => ['Horario fora da janela maxima de agendamento.'],
            ]);
        }
    }

    private function assertInsideWorkingHours(
        int $companyId,
        AppointmentStaffProfile $staffProfile,
        CarbonImmutable $startLocal,
        CarbonImmutable $endLocal
    ): void {
        $workingHours = AppointmentWorkingHour::query()
            ->where('company_id', $companyId)
            ->where('staff_profile_id', (int) $staffProfile->id)
            ->where('day_of_week', $startLocal->dayOfWeek)
            ->where('is_active', true)
            ->orderBy('start_time')
            ->get();

        $isInsideAnyWindow = $workingHours->contains(function (AppointmentWorkingHour $window) use ($startLocal, $endLocal) {
            $windowStart = CarbonImmutable::parse(
                $startLocal->toDateString() . ' ' . (string) $window->start_time,
                $startLocal->timezone
            );
            $windowEnd = CarbonImmutable::parse(
                $startLocal->toDateString() . ' ' . (string) $window->end_time,
                $startLocal->timezone
            );

            $insideWindow = $startLocal->gte($windowStart) && $endLocal->lte($windowEnd);
            if (! $insideWindow) {
                return false;
            }

            if (! $window->break_start_time || ! $window->break_end_time) {
                return true;
            }

            $breakStart = CarbonImmutable::parse(
                $startLocal->toDateString() . ' ' . (string) $window->break_start_time,
                $startLocal->timezone
            );
            $breakEnd = CarbonImmutable::parse(
                $startLocal->toDateString() . ' ' . (string) $window->break_end_time,
                $startLocal->timezone
            );

            return ! ($startLocal->lt($breakEnd) && $endLocal->gt($breakStart));
        });

        if (! $isInsideAnyWindow) {
            throw ValidationException::withMessages([
                'starts_at' => ['Horario fora da jornada configurada para o atendente.'],
            ]);
        }
    }

    private function resolveSettings(int $companyId): AppointmentSetting
    {
        return AppointmentSetting::query()->firstOrCreate(
            ['company_id' => $companyId],
            [
                'timezone' => 'America/Sao_Paulo',
                'slot_interval_minutes' => 15,
                'booking_min_notice_minutes' => 120,
                'booking_max_advance_days' => 30,
                'cancellation_min_notice_minutes' => 120,
                'reschedule_min_notice_minutes' => 120,
                'allow_customer_choose_staff' => true,
            ]
        );
    }

    private function loadService(int $companyId, int $serviceId): AppointmentService
    {
        $service = AppointmentService::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->find($serviceId);

        if (! $service) {
            throw ValidationException::withMessages([
                'service_id' => ['Serviço não encontrado ou inativo para esta empresa.'],
            ]);
        }

        return $service;
    }

    private function resolveTimezone(AppointmentSetting $settings): string
    {
        return trim((string) $settings->timezone) !== ''
            ? (string) $settings->timezone
            : 'America/Sao_Paulo';
    }

    private function resolveSlotInterval(
        AppointmentSetting $settings,
        AppointmentStaffProfile $staffProfile,
        ?AppointmentWorkingHour $workingHour = null
    ): int
    {
        $value = (int) (
            $workingHour?->slot_interval_minutes
            ?: $staffProfile->slot_interval_minutes
            ?: $settings->slot_interval_minutes
            ?: 15
        );

        return max(5, $value);
    }

    private function resolveSlotIntervalForSlot(
        int $companyId,
        AppointmentSetting $settings,
        AppointmentStaffProfile $staffProfile,
        CarbonImmutable $startLocal,
        CarbonImmutable $endLocal
    ): int {
        $workingHour = AppointmentWorkingHour::query()
            ->where('company_id', $companyId)
            ->where('staff_profile_id', (int) $staffProfile->id)
            ->where('day_of_week', $startLocal->dayOfWeek)
            ->where('is_active', true)
            ->where('start_time', '<=', $startLocal->format('H:i:s'))
            ->where('end_time', '>=', $endLocal->format('H:i:s'))
            ->orderBy('start_time')
            ->first();

        return $this->resolveSlotInterval($settings, $staffProfile, $workingHour);
    }

    private function resolveMinNoticeMinutes(AppointmentSetting $settings, AppointmentStaffProfile $staffProfile): int
    {
        return max(0, (int) ($staffProfile->booking_min_notice_minutes ?? $settings->booking_min_notice_minutes ?? 0));
    }

    private function resolveMaxAdvanceDays(AppointmentSetting $settings, AppointmentStaffProfile $staffProfile): int
    {
        return max(0, (int) ($staffProfile->booking_max_advance_days ?? $settings->booking_max_advance_days ?? 0));
    }

    /**
     * @return array<int, array{0:CarbonImmutable,1:CarbonImmutable}>
     */
    private function resolveWorkingSegments(
        CarbonImmutable $targetDate,
        string $timezone,
        AppointmentWorkingHour $workingHour
    ): array {
        $windowStart = CarbonImmutable::parse(
            $targetDate->toDateString() . ' ' . (string) $workingHour->start_time,
            $timezone
        );
        $windowEnd = CarbonImmutable::parse(
            $targetDate->toDateString() . ' ' . (string) $workingHour->end_time,
            $timezone
        );

        if (! $workingHour->break_start_time || ! $workingHour->break_end_time) {
            return [[$windowStart, $windowEnd]];
        }

        $breakStart = CarbonImmutable::parse(
            $targetDate->toDateString() . ' ' . (string) $workingHour->break_start_time,
            $timezone
        );
        $breakEnd = CarbonImmutable::parse(
            $targetDate->toDateString() . ' ' . (string) $workingHour->break_end_time,
            $timezone
        );

        if ($breakStart->lte($windowStart) || $breakEnd->gte($windowEnd) || $breakEnd->lte($breakStart)) {
            return [[$windowStart, $windowEnd]];
        }

        return [
            [$windowStart, $breakStart],
            [$breakEnd, $windowEnd],
        ];
    }

    private function parseDate(CarbonInterface|string $date, string $timezone): CarbonImmutable
    {
        if ($date instanceof CarbonInterface) {
            return CarbonImmutable::instance($date)->setTimezone($timezone);
        }

        return CarbonImmutable::parse($date, $timezone);
    }

    private function parseDateTime(CarbonInterface|string $dateTime, string $timezone): CarbonImmutable
    {
        if ($dateTime instanceof CarbonInterface) {
            return CarbonImmutable::instance($dateTime)->setTimezone($timezone)->second(0);
        }

        return CarbonImmutable::parse($dateTime, $timezone)->second(0);
    }

    private function roundUpToSlot(int $timestamp, string $timezone, int $interval): CarbonImmutable
    {
        $dateTime = CarbonImmutable::createFromTimestamp($timestamp, $timezone)->second(0);
        $minutesOfDay = ((int) $dateTime->hour * 60) + (int) $dateTime->minute;
        $remainder = $minutesOfDay % $interval;
        if ($remainder === 0) {
            return $dateTime;
        }

        return $dateTime->addMinutes($interval - $remainder);
    }

    private function isDateWithinAdvanceWindow(CarbonImmutable $targetDate, string $timezone, int $maxAdvanceDays): bool
    {
        $maximumAllowedDate = now($timezone)->addDays($maxAdvanceDays)->endOfDay();

        return $targetDate->lte($maximumAllowedDate);
    }

    /**
     * @return array{staff_profile_id:int,user_id:int,staff_name:string,slot_interval_minutes:int,slots:array<int, array{starts_at:string,ends_at:string,starts_at_local:string,ends_at_local:string}>}
     */
    private function emptyStaffAvailability(AppointmentStaffProfile $staffProfile, int $slotInterval): array
    {
        return [
            'staff_profile_id' => (int) $staffProfile->id,
            'user_id' => (int) $staffProfile->user_id,
            'staff_name' => trim((string) ($staffProfile->display_name ?: $staffProfile->user?->name ?: 'Atendente')),
            'slot_interval_minutes' => $slotInterval,
            'slots' => [],
        ];
    }

    /**
     * @return array{id:int,name:string,duration_minutes:int,buffer_before_minutes:int,buffer_after_minutes:int,max_bookings_per_slot:int}
     */
    private function serializeService(AppointmentService $service): array
    {
        return [
            'id' => (int) $service->id,
            'name' => (string) $service->name,
            'duration_minutes' => (int) $service->duration_minutes,
            'buffer_before_minutes' => (int) $service->buffer_before_minutes,
            'buffer_after_minutes' => (int) $service->buffer_after_minutes,
            'max_bookings_per_slot' => max(1, (int) $service->max_bookings_per_slot),
        ];
    }
}
