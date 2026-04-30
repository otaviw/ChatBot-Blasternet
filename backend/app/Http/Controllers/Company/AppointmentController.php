<?php

declare(strict_types=1);


namespace App\Http\Controllers\Company;

use App\Actions\Appointment\BookAppointmentAction;
use App\Actions\Appointment\CancelAppointmentAction;
use App\Actions\Appointment\CheckAppointmentAvailabilityAction;
use App\Actions\Appointment\ListAppointmentsAction;
use App\Actions\Appointment\ReplaceWorkingHoursAction;
use App\Actions\Appointment\RescheduleAppointmentAction;
use App\Exceptions\AppointmentBusinessException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Company\AppointmentAvailabilityRequest;
use App\Http\Requests\Company\ListAppointmentTimeOffsRequest;
use App\Http\Requests\Company\ListAppointmentsRequest;
use App\Http\Requests\Company\ReplaceAppointmentWorkingHoursRequest;
use App\Http\Requests\Company\StoreAppointmentRequest;
use App\Http\Requests\Company\StoreAppointmentServiceRequest;
use App\Http\Requests\Company\StoreAppointmentTimeOffRequest;
use App\Http\Requests\Company\UpdateAppointmentServiceRequest;
use App\Http\Requests\Company\UpdateAppointmentSettingsRequest;
use App\Http\Requests\Company\UpdateAppointmentStaffRequest;
use App\Http\Requests\Company\UpdateAppointmentStatusRequest;
use App\Models\Appointment;
use App\Models\AppointmentService;
use App\Models\AppointmentSetting;
use App\Models\AppointmentStaffProfile;
use App\Models\AppointmentTimeOff;
use App\Models\Company;
use App\Models\User;
use App\Support\AppointmentStatus;
use App\Support\Appointments\AppointmentSerializer;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;


class AppointmentController extends Controller
{
    public function __construct(
        private readonly CheckAppointmentAvailabilityAction $checkAvailabilityAction,
        private readonly BookAppointmentAction $bookAppointmentAction,
        private readonly CancelAppointmentAction $cancelAppointmentAction,
        private readonly RescheduleAppointmentAction $rescheduleAppointmentAction,
        private readonly ReplaceWorkingHoursAction $replaceWorkingHoursAction,
        private readonly ListAppointmentsAction $listAppointmentsAction,
        private readonly AppointmentSerializer $serializer,
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
            'settings' => $this->serializer->serializeSettings($settings),
            'actor_id' => (int) $actor->id,
        ]);
    }

    public function updateSettings(UpdateAppointmentSettingsRequest $request): JsonResponse
    {
        [, $company, $errorResponse] = $this->resolveActorAndCompany($request, true);
        if ($errorResponse) {
            return $errorResponse;
        }

        $validated = $request->validated();

        $settings = AppointmentSetting::query()->firstOrCreate(['company_id' => (int) $company->id]);
        $settings->fill($validated);
        $settings->save();

        return response()->json([
            'ok' => true,
            'settings' => $this->serializer->serializeSettings($settings),
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
            'services' => $services->map(fn(AppointmentService $service) => $this->serializer->serializeService($service))->values(),
        ]);
    }

    public function createService(StoreAppointmentServiceRequest $request): JsonResponse
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

        $validated = $request->validated();

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
            'service' => $this->serializer->serializeService($service),
        ], 201);
    }

    public function updateService(UpdateAppointmentServiceRequest $request, AppointmentService $service): JsonResponse
    {
        [, $company, $errorResponse] = $this->resolveActorAndCompany($request, true);
        if ($errorResponse) {
            return $errorResponse;
        }

        if ((int) $service->company_id !== (int) $company->id) {
            return response()->json(['message' => 'Serviço não pertence a empresa.'], 404);
        }

        $validated = $request->validated();

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
            'service' => $this->serializer->serializeService($service),
        ]);
    }

    public function disableService(Request $request, AppointmentService $service): JsonResponse
    {
        [, $company, $errorResponse] = $this->resolveActorAndCompany($request, true);
        if ($errorResponse) {
            return $errorResponse;
        }

        if ((int) $service->company_id !== (int) $company->id) {
            return response()->json(['message' => 'Serviço não pertence a empresa.'], 404);
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
            $profiles[] = $this->serializer->serializeStaffProfile($profile, $user);
        }

        return response()->json([
            'staff' => $profiles,
        ]);
    }

    public function updateStaff(UpdateAppointmentStaffRequest $request, AppointmentStaffProfile $staffProfile): JsonResponse
    {
        [, $company, $errorResponse] = $this->resolveActorAndCompany($request, true);
        if ($errorResponse) {
            return $errorResponse;
        }

        if ((int) $staffProfile->company_id !== (int) $company->id) {
            return response()->json(['message' => 'Atendente não pertence a empresa.'], 404);
        }

        $validated = $request->validated();

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
            'staff' => $this->serializer->serializeStaffProfile($staffProfile, $staffProfile->user),
        ]);
    }

    public function replaceWorkingHours(ReplaceAppointmentWorkingHoursRequest $request, AppointmentStaffProfile $staffProfile): JsonResponse
    {
        [, $company, $errorResponse] = $this->resolveActorAndCompany($request, true);
        if ($errorResponse) {
            return $errorResponse;
        }

        if ((int) $staffProfile->company_id !== (int) $company->id) {
            return response()->json(['message' => 'Atendente não pertence a empresa.'], 404);
        }

        $validated = $request->validated();

        $this->replaceWorkingHoursAction->handle($company, $staffProfile, $validated['hours']);

        $staffProfile->load(['user:id,name,email', 'workingHours']);

        return response()->json([
            'ok' => true,
            'staff' => $this->serializer->serializeStaffProfile($staffProfile, $staffProfile->user),
        ]);
    }

    public function listTimeOffs(ListAppointmentTimeOffsRequest $request): JsonResponse
    {
        [, $company, $errorResponse] = $this->resolveActorAndCompany($request);
        if ($errorResponse) {
            return $errorResponse;
        }

        $validated = $request->validated();

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
            'time_offs' => $rows->map(fn(AppointmentTimeOff $item) => $this->serializer->serializeTimeOff($item))->values(),
        ]);
    }

    public function createTimeOff(StoreAppointmentTimeOffRequest $request): JsonResponse
    {
        [$actor, $company, $errorResponse] = $this->resolveActorAndCompany($request, true);
        if ($errorResponse) {
            return $errorResponse;
        }

        $validated = $request->validated();

        if (! empty($validated['staff_profile_id'])) {
            $staffExists = AppointmentStaffProfile::query()
                ->where('company_id', (int) $company->id)
                ->whereKey((int) $validated['staff_profile_id'])
                ->exists();
            if (! $staffExists) {
                return response()->json(['message' => 'Atendente não pertence a empresa.'], 404);
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
            'time_off' => $this->serializer->serializeTimeOff($timeOff),
        ], 201);
    }

    public function deleteTimeOff(Request $request, AppointmentTimeOff $timeOff): JsonResponse
    {
        [, $company, $errorResponse] = $this->resolveActorAndCompany($request, true);
        if ($errorResponse) {
            return $errorResponse;
        }

        if ((int) $timeOff->company_id !== (int) $company->id) {
            return response()->json(['message' => 'Bloqueio não pertence a empresa.'], 404);
        }

        $timeOff->delete();

        return response()->json(['ok' => true]);
    }

    public function availability(AppointmentAvailabilityRequest $request): JsonResponse
    {
        [, $company, $errorResponse] = $this->resolveActorAndCompany($request);
        if ($errorResponse) {
            return $errorResponse;
        }

        $validated = $request->validated();

        $payload = $this->checkAvailabilityAction->handle(
            $company,
            (int) $validated['service_id'],
            (string) $validated['date'],
            isset($validated['staff_profile_id']) ? (int) $validated['staff_profile_id'] : null
        );

        return response()->json($payload);
    }

    public function listAppointments(ListAppointmentsRequest $request): JsonResponse
    {
        [, $company, $errorResponse] = $this->resolveActorAndCompany($request);
        if ($errorResponse) {
            return $errorResponse;
        }

        return response()->json($this->listAppointmentsAction->handle($company, $request->validated()));
    }

    public function createAppointment(StoreAppointmentRequest $request): JsonResponse
    {
        [$actor, $company, $errorResponse] = $this->resolveActorAndCompany($request, true);
        if ($errorResponse) {
            return $errorResponse;
        }

        $validated = $request->validated();

        $appointment = $this->bookAppointmentAction->handle($company, $validated, $actor);

        return response()->json([
            'ok' => true,
            'appointment' => $this->serializer->serializeAppointment($appointment),
        ], 201);
    }

    public function updateStatus(UpdateAppointmentStatusRequest $request, Appointment $appointment): JsonResponse
    {
        [$actor, $company, $errorResponse] = $this->resolveActorAndCompany($request, true);
        if ($errorResponse) {
            return $errorResponse;
        }

        $validated = $request->validated();
        $newStatus = (string) $validated['status'];

        try {
            if ($newStatus === AppointmentStatus::CANCELLED) {
                $appointment = $this->cancelAppointmentAction->handle(
                    $company,
                    $appointment,
                    $actor,
                    isset($validated['reason']) ? (string) $validated['reason'] : null,
                    (bool) ($validated['notify_customer'] ?? true)
                );
            } else {
                $appointment = $this->rescheduleAppointmentAction->handle(
                    $company,
                    $appointment,
                    $actor,
                    $newStatus,
                    isset($validated['reason']) ? (string) $validated['reason'] : null
                );
            }
        } catch (AppointmentBusinessException $exception) {
            return response()->json(['message' => $exception->getMessage()], $exception->status());
        }

        $appointment->load(['service:id,name', 'staffProfile.user:id,name,email']);

        return response()->json(['ok' => true, 'appointment' => $this->serializer->serializeAppointment($appointment)]);
    }

    public function deleteAppointment(Request $request, Appointment $appointment): JsonResponse
    {
        [, $company, $errorResponse] = $this->resolveActorAndCompany($request, true);
        if ($errorResponse) {
            return $errorResponse;
        }

        if ((int) $appointment->company_id !== (int) $company->id) {
            return response()->json(['message' => 'Agendamento não pertence a empresa.'], 404);
        }

        $appointment->delete();

        return response()->json(['ok' => true]);
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
                'message' => 'Somente usuários da empresa podem acessar agendamentos.',
            ], 403)];
        }

        if ($requiresManagePermission && ! $actor->isCompanyAdmin() && ! $actor->isAgent()) {
            return [$actor, null, response()->json([
                'authenticated' => true,
                'message' => 'Usuário sem permissão para alterar agendamentos.',
            ], 403)];
        }

        $company = Company::query()->find((int) $actor->company_id);
        if (! $company) {
            return [$actor, null, response()->json([
                'message' => 'Empresa não encontrada.',
            ], 404)];
        }

        return [$actor, $company, null];
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

