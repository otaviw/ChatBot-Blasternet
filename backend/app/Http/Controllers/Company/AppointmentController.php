<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\AppointmentService;
use App\Models\AppointmentSetting;
use App\Models\AppointmentStaffProfile;
use App\Models\AppointmentTimeOff;
use App\Models\AppointmentWorkingHour;
use App\Models\Company;
use App\Models\User;
use App\Services\Appointments\AppointmentAvailabilityService;
use App\Services\Appointments\AppointmentBookingService;
use App\Support\AppointmentSource;
use App\Support\AppointmentStatus;
use App\Support\PhoneNumberNormalizer;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AppointmentController extends Controller
{
    public function __construct(
        private AppointmentAvailabilityService $availabilityService,
        private AppointmentBookingService $bookingService
    ) {}

    public function settings(Request $request): JsonResponse
    {
        [$actor, $company, $errorResponse] = $this->resolveActorAndCompany($request);
        if ($errorResponse) {
            return $errorResponse;
        }

        $settings = AppointmentSetting::query()->firstOrCreate(
            ['company_id' => (int) $company->id],
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

        return response()->json([
            'authenticated' => true,
            'company_id' => (int) $company->id,
            'settings' => $this->serializeSettings($settings),
            'actor_id' => (int) $actor->id,
        ]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        [, $company, $errorResponse] = $this->resolveActorAndCompany($request, true);
        if ($errorResponse) {
            return $errorResponse;
        }

        $validated = $request->validate([
            'timezone' => ['required', 'string', 'max:64'],
            'slot_interval_minutes' => ['required', 'integer', 'min:5', 'max:120'],
            'booking_min_notice_minutes' => ['required', 'integer', 'min:0', 'max:10080'],
            'booking_max_advance_days' => ['required', 'integer', 'min:0', 'max:365'],
            'cancellation_min_notice_minutes' => ['required', 'integer', 'min:0', 'max:10080'],
            'reschedule_min_notice_minutes' => ['required', 'integer', 'min:0', 'max:10080'],
            'allow_customer_choose_staff' => ['required', 'boolean'],
        ]);

        $settings = AppointmentSetting::query()->firstOrCreate(['company_id' => (int) $company->id]);
        $settings->fill($validated);
        $settings->save();

        return response()->json([
            'ok' => true,
            'settings' => $this->serializeSettings($settings),
        ]);
    }

    public function listServices(Request $request): JsonResponse
    {
        [, $company, $errorResponse] = $this->resolveActorAndCompany($request);
        if ($errorResponse) {
            return $errorResponse;
        }

        $services = AppointmentService::query()
            ->where('company_id', (int) $company->id)
            ->orderBy('name')
            ->get();

        return response()->json([
            'services' => $services->map(fn(AppointmentService $service) => $this->serializeService($service))->values(),
        ]);
    }

    public function createService(Request $request): JsonResponse
    {
        [, $company, $errorResponse] = $this->resolveActorAndCompany($request, true);
        if ($errorResponse) {
            return $errorResponse;
        }

        $existingServiceCount = AppointmentService::query()
            ->where('company_id', (int) $company->id)
            ->count();
        if ($existingServiceCount >= 1) {
            return response()->json([
                'message' => 'A empresa pode configurar apenas um serviço de agendamento.',
            ], 422);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            'duration_minutes' => ['required', 'integer', 'min:5', 'max:600'],
            'buffer_before_minutes' => ['sometimes', 'integer', 'min:0', 'max:240'],
            'buffer_after_minutes' => ['sometimes', 'integer', 'min:0', 'max:240'],
            'max_bookings_per_slot' => ['sometimes', 'integer', 'min:1', 'max:10'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $service = AppointmentService::create([
            'company_id' => (int) $company->id,
            'name' => trim((string) $validated['name']),
            'description' => $this->nullableTrim($validated['description'] ?? null),
            'duration_minutes' => (int) $validated['duration_minutes'],
            'buffer_before_minutes' => (int) ($validated['buffer_before_minutes'] ?? 0),
            'buffer_after_minutes' => (int) ($validated['buffer_after_minutes'] ?? 0),
            'max_bookings_per_slot' => (int) ($validated['max_bookings_per_slot'] ?? 1),
            'is_active' => (bool) ($validated['is_active'] ?? true),
        ]);

        return response()->json([
            'ok' => true,
            'service' => $this->serializeService($service),
        ], 201);
    }

    public function updateService(Request $request, AppointmentService $service): JsonResponse
    {
        [, $company, $errorResponse] = $this->resolveActorAndCompany($request, true);
        if ($errorResponse) {
            return $errorResponse;
        }

        if ((int) $service->company_id !== (int) $company->id) {
            return response()->json(['message' => 'Servico nao pertence a empresa.'], 404);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            'duration_minutes' => ['required', 'integer', 'min:5', 'max:600'],
            'buffer_before_minutes' => ['required', 'integer', 'min:0', 'max:240'],
            'buffer_after_minutes' => ['required', 'integer', 'min:0', 'max:240'],
            'max_bookings_per_slot' => ['required', 'integer', 'min:1', 'max:10'],
            'is_active' => ['required', 'boolean'],
        ]);

        $service->fill([
            'name' => trim((string) $validated['name']),
            'description' => $this->nullableTrim($validated['description'] ?? null),
            'duration_minutes' => (int) $validated['duration_minutes'],
            'buffer_before_minutes' => (int) $validated['buffer_before_minutes'],
            'buffer_after_minutes' => (int) $validated['buffer_after_minutes'],
            'max_bookings_per_slot' => (int) $validated['max_bookings_per_slot'],
            'is_active' => (bool) $validated['is_active'],
        ]);
        $service->save();

        return response()->json([
            'ok' => true,
            'service' => $this->serializeService($service),
        ]);
    }

    public function disableService(Request $request, AppointmentService $service): JsonResponse
    {
        [, $company, $errorResponse] = $this->resolveActorAndCompany($request, true);
        if ($errorResponse) {
            return $errorResponse;
        }

        if ((int) $service->company_id !== (int) $company->id) {
            return response()->json(['message' => 'Servico nao pertence a empresa.'], 404);
        }

        $service->is_active = false;
        $service->save();

        return response()->json(['ok' => true]);
    }

    public function listStaff(Request $request): JsonResponse
    {
        [, $company, $errorResponse] = $this->resolveActorAndCompany($request);
        if ($errorResponse) {
            return $errorResponse;
        }

        $users = User::query()
            ->where('company_id', (int) $company->id)
            ->whereIn('role', User::companyRoleValues())
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

        $profiles = [];
        foreach ($users as $user) {
            $profile = AppointmentStaffProfile::query()->firstOrCreate(
                [
                    'company_id' => (int) $company->id,
                    'user_id' => (int) $user->id,
                ],
                [
                    'display_name' => $user->name,
                    'is_bookable' => true,
                ]
            );
            $profile->load(['workingHours']);
            $profiles[] = $this->serializeStaffProfile($profile, $user);
        }

        return response()->json([
            'staff' => $profiles,
        ]);
    }

    public function updateStaff(Request $request, AppointmentStaffProfile $staffProfile): JsonResponse
    {
        [, $company, $errorResponse] = $this->resolveActorAndCompany($request, true);
        if ($errorResponse) {
            return $errorResponse;
        }

        if ((int) $staffProfile->company_id !== (int) $company->id) {
            return response()->json(['message' => 'Atendente nao pertence a empresa.'], 404);
        }

        $validated = $request->validate([
            'display_name' => ['nullable', 'string', 'max:120'],
            'is_bookable' => ['required', 'boolean'],
            'slot_interval_minutes' => ['nullable', 'integer', 'min:5', 'max:120'],
            'booking_min_notice_minutes' => ['nullable', 'integer', 'min:0', 'max:10080'],
            'booking_max_advance_days' => ['nullable', 'integer', 'min:0', 'max:365'],
        ]);

        $staffProfile->fill([
            'display_name' => $this->nullableTrim($validated['display_name'] ?? null),
            'is_bookable' => (bool) $validated['is_bookable'],
            'slot_interval_minutes' => $validated['slot_interval_minutes'] ?? null,
            'booking_min_notice_minutes' => $validated['booking_min_notice_minutes'] ?? null,
            'booking_max_advance_days' => $validated['booking_max_advance_days'] ?? null,
        ]);
        $staffProfile->save();
        $staffProfile->load(['user:id,name,email', 'workingHours']);

        return response()->json([
            'ok' => true,
            'staff' => $this->serializeStaffProfile($staffProfile, $staffProfile->user),
        ]);
    }

    public function replaceWorkingHours(Request $request, AppointmentStaffProfile $staffProfile): JsonResponse
    {
        [, $company, $errorResponse] = $this->resolveActorAndCompany($request, true);
        if ($errorResponse) {
            return $errorResponse;
        }

        if ((int) $staffProfile->company_id !== (int) $company->id) {
            return response()->json(['message' => 'Atendente nao pertence a empresa.'], 404);
        }

        $validated = $request->validate([
            'hours' => ['required', 'array', 'max:70'],
            'hours.*.day_of_week' => ['required', 'integer', 'min:0', 'max:6'],
            'hours.*.start_time' => ['required', 'date_format:H:i'],
            'hours.*.break_start_time' => ['nullable', 'date_format:H:i'],
            'hours.*.break_end_time' => ['nullable', 'date_format:H:i'],
            'hours.*.end_time' => ['required', 'date_format:H:i'],
            'hours.*.slot_interval_minutes' => ['nullable', 'integer', 'min:5', 'max:120'],
            'hours.*.is_active' => ['sometimes', 'boolean'],
        ]);

        AppointmentWorkingHour::query()
            ->where('company_id', (int) $company->id)
            ->where('staff_profile_id', (int) $staffProfile->id)
            ->delete();

        foreach ($validated['hours'] as $item) {
            if ((string) $item['start_time'] >= (string) $item['end_time']) {
                throw ValidationException::withMessages([
                    'hours' => ['Cada janela de jornada deve ter inicio menor que fim.'],
                ]);
            }

            $breakStart = isset($item['break_start_time']) ? trim((string) $item['break_start_time']) : '';
            $breakEnd = isset($item['break_end_time']) ? trim((string) $item['break_end_time']) : '';
            if (($breakStart === '') !== ($breakEnd === '')) {
                throw ValidationException::withMessages([
                    'hours' => ['Informe inicio e fim da pausa, ou deixe ambos vazios.'],
                ]);
            }
            if ($breakStart !== '' && $breakEnd !== '') {
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

            AppointmentWorkingHour::create([
                'company_id' => (int) $company->id,
                'staff_profile_id' => (int) $staffProfile->id,
                'day_of_week' => (int) $item['day_of_week'],
                'start_time' => (string) $item['start_time'] . ':00',
                'break_start_time' => $breakStart !== '' ? $breakStart . ':00' : null,
                'break_end_time' => $breakEnd !== '' ? $breakEnd . ':00' : null,
                'end_time' => (string) $item['end_time'] . ':00',
                'slot_interval_minutes' => isset($item['slot_interval_minutes'])
                    ? (int) $item['slot_interval_minutes']
                    : null,
                'is_active' => (bool) ($item['is_active'] ?? true),
            ]);
        }

        $staffProfile->load(['user:id,name,email', 'workingHours']);

        return response()->json([
            'ok' => true,
            'staff' => $this->serializeStaffProfile($staffProfile, $staffProfile->user),
        ]);
    }

    public function listTimeOffs(Request $request): JsonResponse
    {
        [, $company, $errorResponse] = $this->resolveActorAndCompany($request);
        if ($errorResponse) {
            return $errorResponse;
        }

        $validated = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'staff_profile_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $dateFrom = isset($validated['date_from'])
            ? CarbonImmutable::parse((string) $validated['date_from'])->startOfDay()
            : now()->startOfDay();
        $dateTo = isset($validated['date_to'])
            ? CarbonImmutable::parse((string) $validated['date_to'])->endOfDay()
            : now()->addDays(30)->endOfDay();

        $query = AppointmentTimeOff::query()
            ->where('company_id', (int) $company->id)
            ->where('starts_at', '<=', $dateTo->setTimezone('UTC'))
            ->where('ends_at', '>=', $dateFrom->setTimezone('UTC'))
            ->with(['staffProfile.user:id,name,email', 'createdBy:id,name,email'])
            ->orderBy('starts_at');

        if (! empty($validated['staff_profile_id'])) {
            $query->where(function ($subQuery) use ($validated) {
                $subQuery->where('staff_profile_id', (int) $validated['staff_profile_id'])
                    ->orWhereNull('staff_profile_id');
            });
        }

        $rows = $query->get();

        return response()->json([
            'time_offs' => $rows->map(fn(AppointmentTimeOff $item) => $this->serializeTimeOff($item))->values(),
        ]);
    }

    public function createTimeOff(Request $request): JsonResponse
    {
        [$actor, $company, $errorResponse] = $this->resolveActorAndCompany($request, true);
        if ($errorResponse) {
            return $errorResponse;
        }

        $validated = $request->validate([
            'staff_profile_id' => ['nullable', 'integer', 'min:1'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'is_all_day' => ['sometimes', 'boolean'],
            'reason' => ['nullable', 'string', 'max:191'],
            'source' => ['sometimes', 'string', Rule::in(['manual', 'system'])],
        ]);

        if (! empty($validated['staff_profile_id'])) {
            $staffExists = AppointmentStaffProfile::query()
                ->where('company_id', (int) $company->id)
                ->whereKey((int) $validated['staff_profile_id'])
                ->exists();
            if (! $staffExists) {
                return response()->json(['message' => 'Atendente nao pertence a empresa.'], 404);
            }
        }

        $timeOff = AppointmentTimeOff::create([
            'company_id' => (int) $company->id,
            'staff_profile_id' => isset($validated['staff_profile_id']) ? (int) $validated['staff_profile_id'] : null,
            'starts_at' => CarbonImmutable::parse((string) $validated['starts_at'])->setTimezone('UTC'),
            'ends_at' => CarbonImmutable::parse((string) $validated['ends_at'])->setTimezone('UTC'),
            'is_all_day' => (bool) ($validated['is_all_day'] ?? false),
            'reason' => $this->nullableTrim($validated['reason'] ?? null),
            'source' => (string) ($validated['source'] ?? 'manual'),
            'created_by_user_id' => (int) $actor->id,
        ]);

        $timeOff->load(['staffProfile.user:id,name,email', 'createdBy:id,name,email']);

        return response()->json([
            'ok' => true,
            'time_off' => $this->serializeTimeOff($timeOff),
        ], 201);
    }

    public function deleteTimeOff(Request $request, AppointmentTimeOff $timeOff): JsonResponse
    {
        [, $company, $errorResponse] = $this->resolveActorAndCompany($request, true);
        if ($errorResponse) {
            return $errorResponse;
        }

        if ((int) $timeOff->company_id !== (int) $company->id) {
            return response()->json(['message' => 'Bloqueio nao pertence a empresa.'], 404);
        }

        $timeOff->delete();

        return response()->json(['ok' => true]);
    }

    public function availability(Request $request): JsonResponse
    {
        [, $company, $errorResponse] = $this->resolveActorAndCompany($request);
        if ($errorResponse) {
            return $errorResponse;
        }

        $validated = $request->validate([
            'service_id' => ['required', 'integer', 'min:1'],
            'date' => ['required', 'date'],
            'staff_profile_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $payload = $this->availabilityService->listAvailableSlots(
            $company,
            (int) $validated['service_id'],
            (string) $validated['date'],
            isset($validated['staff_profile_id']) ? (int) $validated['staff_profile_id'] : null
        );

        return response()->json($payload);
    }

    public function listAppointments(Request $request): JsonResponse
    {
        [, $company, $errorResponse] = $this->resolveActorAndCompany($request);
        if ($errorResponse) {
            return $errorResponse;
        }

        $validated = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'staff_profile_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', Rule::in(AppointmentStatus::all())],
            'customer_phone' => ['nullable', 'string', 'max:40'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $from = isset($validated['date_from'])
            ? CarbonImmutable::parse((string) $validated['date_from'])->startOfDay()
            : now()->startOfDay();
        $to = isset($validated['date_to'])
            ? CarbonImmutable::parse((string) $validated['date_to'])->endOfDay()
            : now()->addDays(14)->endOfDay();
        $perPage = (int) ($validated['per_page'] ?? 50);
        $phoneVariants = [];
        if (! empty($validated['customer_phone'])) {
            $phoneVariants = PhoneNumberNormalizer::variantsForLookup((string) $validated['customer_phone']);
        }

        $query = Appointment::query()
            ->where('company_id', (int) $company->id)
            ->where('starts_at', '>=', $from->setTimezone('UTC'))
            ->where('starts_at', '<=', $to->setTimezone('UTC'))
            ->with(['service:id,name', 'staffProfile.user:id,name,email'])
            ->orderBy('starts_at');

        if (! empty($validated['staff_profile_id'])) {
            $query->where('staff_profile_id', (int) $validated['staff_profile_id']);
        }

        if (! empty($validated['status'])) {
            $query->where('status', (string) $validated['status']);
        }

        if ($phoneVariants !== []) {
            $query->whereIn('customer_phone', $phoneVariants);
        }

        $rows = $query->paginate($perPage);

        return response()->json([
            'items' => collect($rows->items())->map(fn(Appointment $appointment) => $this->serializeAppointment($appointment))->values(),
            'pagination' => [
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
                'per_page' => $rows->perPage(),
                'total' => $rows->total(),
            ],
        ]);
    }

    public function createAppointment(Request $request): JsonResponse
    {
        [$actor, $company, $errorResponse] = $this->resolveActorAndCompany($request, true);
        if ($errorResponse) {
            return $errorResponse;
        }

        $validated = $request->validate([
            'service_id' => ['required', 'integer', 'min:1'],
            'staff_profile_id' => ['required', 'integer', 'min:1'],
            'starts_at' => ['required', 'date'],
            'customer_name' => ['nullable', 'string', 'max:191'],
            'customer_phone' => ['required', 'string', 'max:40'],
            'customer_email' => ['nullable', 'email', 'max:191'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'source' => ['sometimes', Rule::in(AppointmentSource::all())],
            'meta' => ['sometimes', 'array'],
        ]);

        $appointment = $this->bookingService->createAppointment($company, $validated, $actor);

        return response()->json([
            'ok' => true,
            'appointment' => $this->serializeAppointment($appointment),
        ], 201);
    }

    /**
     * @return array{0:?User,1:?Company,2:?JsonResponse}
     */
    private function resolveActorAndCompany(Request $request, bool $requiresManagePermission = false): array
    {
        $actor = $request->user();
        if (! $actor) {
            return [null, null, response()->json([
                'authenticated' => false,
                'redirect' => '/entrar',
            ], 403)];
        }

        if (! $actor->isCompanyUser()) {
            return [$actor, null, response()->json([
                'authenticated' => true,
                'message' => 'Somente usuarios da empresa podem acessar agendamentos.',
            ], 403)];
        }

        if ($requiresManagePermission && ! $actor->isCompanyAdmin() && ! $actor->isAgent()) {
            return [$actor, null, response()->json([
                'authenticated' => true,
                'message' => 'Usuario sem permissao para alterar agendamentos.',
            ], 403)];
        }

        $company = Company::query()->find((int) $actor->company_id);
        if (! $company) {
            return [$actor, null, response()->json([
                'message' => 'Empresa nao encontrada.',
            ], 404)];
        }

        return [$actor, $company, null];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeSettings(AppointmentSetting $settings): array
    {
        return [
            'id' => (int) $settings->id,
            'company_id' => (int) $settings->company_id,
            'timezone' => (string) $settings->timezone,
            'slot_interval_minutes' => (int) $settings->slot_interval_minutes,
            'booking_min_notice_minutes' => (int) $settings->booking_min_notice_minutes,
            'booking_max_advance_days' => (int) $settings->booking_max_advance_days,
            'cancellation_min_notice_minutes' => (int) $settings->cancellation_min_notice_minutes,
            'reschedule_min_notice_minutes' => (int) $settings->reschedule_min_notice_minutes,
            'allow_customer_choose_staff' => (bool) $settings->allow_customer_choose_staff,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeService(AppointmentService $service): array
    {
        return [
            'id' => (int) $service->id,
            'company_id' => (int) $service->company_id,
            'name' => (string) $service->name,
            'description' => $service->description,
            'duration_minutes' => (int) $service->duration_minutes,
            'buffer_before_minutes' => (int) $service->buffer_before_minutes,
            'buffer_after_minutes' => (int) $service->buffer_after_minutes,
            'max_bookings_per_slot' => (int) $service->max_bookings_per_slot,
            'is_active' => (bool) $service->is_active,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeStaffProfile(AppointmentStaffProfile $profile, ?User $user): array
    {
        return [
            'id' => (int) $profile->id,
            'company_id' => (int) $profile->company_id,
            'user_id' => (int) $profile->user_id,
            'user_name' => (string) ($user?->name ?? ''),
            'user_email' => (string) ($user?->email ?? ''),
            'display_name' => $profile->display_name,
            'is_bookable' => (bool) $profile->is_bookable,
            'slot_interval_minutes' => $profile->slot_interval_minutes !== null ? (int) $profile->slot_interval_minutes : null,
            'booking_min_notice_minutes' => $profile->booking_min_notice_minutes !== null ? (int) $profile->booking_min_notice_minutes : null,
            'booking_max_advance_days' => $profile->booking_max_advance_days !== null ? (int) $profile->booking_max_advance_days : null,
            'working_hours' => $profile->workingHours
                ->map(fn(AppointmentWorkingHour $hour) => [
                    'id' => (int) $hour->id,
                    'day_of_week' => (int) $hour->day_of_week,
                    'start_time' => mb_substr((string) $hour->start_time, 0, 5),
                    'break_start_time' => $hour->break_start_time
                        ? mb_substr((string) $hour->break_start_time, 0, 5)
                        : null,
                    'break_end_time' => $hour->break_end_time
                        ? mb_substr((string) $hour->break_end_time, 0, 5)
                        : null,
                    'end_time' => mb_substr((string) $hour->end_time, 0, 5),
                    'slot_interval_minutes' => $hour->slot_interval_minutes !== null
                        ? (int) $hour->slot_interval_minutes
                        : null,
                    'is_active' => (bool) $hour->is_active,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeTimeOff(AppointmentTimeOff $timeOff): array
    {
        return [
            'id' => (int) $timeOff->id,
            'company_id' => (int) $timeOff->company_id,
            'staff_profile_id' => $timeOff->staff_profile_id ? (int) $timeOff->staff_profile_id : null,
            'staff_name' => $timeOff->staffProfile?->display_name ?: $timeOff->staffProfile?->user?->name,
            'starts_at' => $timeOff->starts_at?->toIso8601String(),
            'ends_at' => $timeOff->ends_at?->toIso8601String(),
            'is_all_day' => (bool) $timeOff->is_all_day,
            'reason' => $timeOff->reason,
            'source' => (string) $timeOff->source,
            'created_by_user_id' => $timeOff->created_by_user_id ? (int) $timeOff->created_by_user_id : null,
            'created_by_name' => $timeOff->createdBy?->name,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeAppointment(Appointment $appointment): array
    {
        return [
            'id' => (int) $appointment->id,
            'company_id' => (int) $appointment->company_id,
            'service_id' => $appointment->service_id ? (int) $appointment->service_id : null,
            'service_name' => $appointment->service?->name,
            'staff_profile_id' => (int) $appointment->staff_profile_id,
            'staff_name' => $appointment->staffProfile?->display_name ?: $appointment->staffProfile?->user?->name,
            'customer_name' => $appointment->customer_name,
            'customer_phone' => (string) $appointment->customer_phone,
            'customer_email' => $appointment->customer_email,
            'starts_at' => $appointment->starts_at?->toIso8601String(),
            'ends_at' => $appointment->ends_at?->toIso8601String(),
            'effective_start_at' => $appointment->effective_start_at?->toIso8601String(),
            'effective_end_at' => $appointment->effective_end_at?->toIso8601String(),
            'status' => (string) $appointment->status,
            'source' => (string) $appointment->source,
            'notes' => $appointment->notes,
            'created_at' => $appointment->created_at?->toIso8601String(),
        ];
    }

    /**
     * @param mixed $value
     */
    private function nullableTrim($value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
