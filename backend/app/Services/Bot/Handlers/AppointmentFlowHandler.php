<?php

namespace App\Services\Bot\Handlers;

use App\Models\Appointment;
use App\Models\AppointmentEvent;
use App\Models\AppointmentService;
use App\Models\AppointmentSetting;
use App\Models\AppointmentStaffProfile;
use App\Models\Company;
use App\Models\Conversation;
use App\Services\Appointments\AppointmentAvailabilityService;
use App\Services\Appointments\AppointmentBookingService;
use App\Services\Bot\BotFlowRegistry;
use App\Support\AppointmentStatus;
use App\Support\Enums\BotFlow;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class AppointmentFlowHandler
{
    use BotHandlerHelpers;

    public function __construct(
        private BotFlowRegistry $registry,
        private AppointmentAvailabilityService $appointmentAvailability,
        private AppointmentBookingService $appointmentBooking,
    ) {}

    /**
     * @param  array<string, mixed>  $action
     * @return array<string, mixed>
     */
    public function start(?Company $company, Conversation $conversation, array $action): array
    {
        $companyEntity = $this->resolveCompany($company, $conversation);
        $services      = $this->activeAppointmentServices($companyEntity);

        if ($services === []) {
            $targetAreaName = trim((string) ($action['target_area_name'] ?? BotFlowRegistry::AREA_ATTENDANCE));
            $replyText      = trim((string) ($action['reply_text'] ?? ''));
            if ($replyText === '') {
                $replyText = 'No momento não há agenda automática disponível. Vou te encaminhar para um atendente.';
            }

            return $this->handoffResult($companyEntity, $conversation, $replyText, $targetAreaName);
        }

        $service                    = $services[0];
        $context                    = $this->appointmentContext($conversation);
        $context['service_id']      = (int) $service['id'];
        $context['service_name']    = (string) $service['name'];
        $context['target_area_name'] = trim((string) ($action['target_area_name'] ?? BotFlowRegistry::AREA_ATTENDANCE));

        $settings           = $this->appointmentSettings($companyEntity);
        $staffProfiles      = $this->activeAppointmentStaff($companyEntity);
        $allowChooseStaff   = (bool) ($settings?->allow_customer_choose_staff ?? true);
        $hasMoreThanOne     = count($staffProfiles) > 1;

        $context['has_staff_choice'] = $allowChooseStaff && $hasMoreThanOne;

        if (! $allowChooseStaff || ! $hasMoreThanOne) {
            $context['staff_profile_id'] = count($staffProfiles) === 1 ? (int) $staffProfiles[0]['id'] : null;
            $context['staff_name']       = count($staffProfiles) === 1 ? (string) $staffProfiles[0]['name'] : null;

            return $this->replyWithAppointmentDayMenu($companyEntity, $context);
        }

        $context['staff_options'] = $this->enumerateStaff($staffProfiles);
        $staffText = $this->appointmentStaffMenuText($context['staff_options']);

        return $this->botStateResult(
            $staffText,
            [
                'flow'    => BotFlow::APPOINTMENTS->value,
                'step'    => 'staff_select',
                'context' => ['appointment' => $context],
            ],
            $this->buildAppointmentStaffListMessage($context['staff_options'], $staffText)
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function handle(
        ?Company $company,
        Conversation $conversation,
        string $step,
        string $normalizedText
    ): array {
        $companyEntity = $this->resolveCompany($company, $conversation);
        $context       = $this->appointmentContext($conversation);

        if ($normalizedText === '9') {
            $targetAreaName = trim((string) ($context['target_area_name'] ?? BotFlowRegistry::AREA_ATTENDANCE));

            return $this->handoffResult(
                $companyEntity,
                $conversation,
                'Certo. Vou te encaminhar para um atendente.',
                $targetAreaName
            );
        }

        return match ($step) {
            'service_select'  => $this->handleAppointmentServiceSelection($companyEntity, $conversation, $normalizedText, $context),
            'staff_select'    => $this->handleAppointmentStaffSelection($companyEntity, $conversation, $normalizedText, $context),
            'day_select'      => $this->handleAppointmentDaySelection($companyEntity, $conversation, $normalizedText, $context),
            'slot_select'     => $this->handleAppointmentSlotSelection($companyEntity, $conversation, $normalizedText, $context),
            'nearest_select'  => $this->handleAppointmentNearestSelection($companyEntity, $conversation, $normalizedText, $context),
            'collect_email'   => $this->handleAppointmentEmailCollection($companyEntity, $conversation, $normalizedText, $context),
            'confirm'         => $this->handleAppointmentConfirmation($companyEntity, $conversation, $normalizedText, $context),
            default           => $this->buildInitialMenuResponse($this->registry->definitionForCompany($companyEntity)),
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function startCancellation(?Company $company, Conversation $conversation): array
    {
        $companyEntity      = $this->resolveCompany($company, $conversation);
        $settings           = $this->appointmentSettings($companyEntity);
        $timezone           = (string) ($settings?->timezone ?: 'America/Sao_Paulo');
        $minNoticeMinutes   = (int) ($settings?->cancellation_min_notice_minutes ?? 120);

        $phone         = (string) $conversation->customer_phone;
        $phoneVariants = [$phone];
        if (strlen($phone) === 13 && str_starts_with($phone, '55')) {
            $phoneVariants[] = substr($phone, 0, 4) . substr($phone, 5);
        } elseif (strlen($phone) === 12 && str_starts_with($phone, '55')) {
            $phoneVariants[] = substr($phone, 0, 4) . '9' . substr($phone, 4);
        }

        $appointment = Appointment::query()
            ->where('company_id', (int) ($companyEntity?->id ?? 0))
            ->whereIn('customer_phone', $phoneVariants)
            ->whereIn('status', [AppointmentStatus::PENDING, AppointmentStatus::CONFIRMED])
            ->where('starts_at', '>', now())
            ->orderBy('starts_at')
            ->first();

        $menuState     = $this->buildInitialMenuResponse($this->registry->definitionForCompany($companyEntity));
        $mainMenuState = $menuState['new_state'] ?? ['flow' => BotFlow::MAIN->value, 'step' => 'menu', 'context' => []];

        if (! $appointment) {
            return $this->botStateResult(
                'Não encontrei nenhum agendamento ativo para o seu número.',
                $mainMenuState
            );
        }

        $startsAt = $appointment->starts_at->setTimezone($timezone);
        $cutoff   = CarbonImmutable::now($timezone)->addMinutes($minNoticeMinutes);

        if ($startsAt->lte($cutoff)) {
            $limitHours = (int) round($minNoticeMinutes / 60);

            return $this->botStateResult(
                "Seu agendamento é dia {$startsAt->format('d/m/Y')} às {$startsAt->format('H:i')}.\n" .
                "Cancelamentos só são permitidos com pelo menos {$limitHours}h de antecedência.\n" .
                "Para cancelar entre em contato com um atendente.\n\n9 - Falar com atendente",
                $mainMenuState
            );
        }

        $staffName = $appointment->staffProfile?->display_name
            ?: $appointment->staffProfile?->user?->name
            ?: '';
        $staffLine = $staffName !== '' ? "\nAtendente: {$staffName}" : '';

        return $this->botStateResult(
            "Seu agendamento:\nData: {$startsAt->translatedFormat('l')}, {$startsAt->format('d/m/Y')}\nHorário: {$startsAt->format('H:i')}{$staffLine}\n\nDeseja cancelar?\n1 - Sim, cancelar\n2 - Não, manter",
            [
                'flow'    => BotFlow::CANCEL_APPOINTMENT->value,
                'step'    => 'confirm',
                'context' => ['cancel_appointment' => ['appointment_id' => (int) $appointment->id]],
            ]
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function handleCancellation(
        ?Company $company,
        Conversation $conversation,
        string $step,
        string $normalizedText
    ): array {
        unset($step);
        $companyEntity  = $this->resolveCompany($company, $conversation);
        $menuState      = $this->buildInitialMenuResponse($this->registry->definitionForCompany($companyEntity));
        $mainMenuState  = $menuState['new_state'] ?? ['flow' => BotFlow::MAIN->value, 'step' => 'menu', 'context' => []];

        $rawContext     = is_array($conversation->bot_context ?? null) ? $conversation->bot_context : [];
        $cancelContext  = is_array($rawContext['cancel_appointment'] ?? null) ? $rawContext['cancel_appointment'] : [];
        $appointmentId  = (int) ($cancelContext['appointment_id'] ?? 0);

        if ($normalizedText === '2' || $appointmentId === 0) {
            return $this->botStateResult('Ok, seu agendamento foi mantido. Até logo!', $mainMenuState);
        }

        if ($normalizedText !== '1') {
            $appointment = $appointmentId > 0 ? Appointment::query()->find($appointmentId) : null;
            if ($appointment) {
                $settings  = $this->appointmentSettings($companyEntity);
                $timezone  = (string) ($settings?->timezone ?: 'America/Sao_Paulo');
                $startsAt  = $appointment->starts_at->setTimezone($timezone);
                $staffName = $appointment->staffProfile?->display_name ?: $appointment->staffProfile?->user?->name ?: '';
                $staffLine = $staffName !== '' ? "\nAtendente: {$staffName}" : '';

                return $this->botStateResult(
                    "Opção inválida. Responda com 1 ou 2.\n\nSeu agendamento:\nData: {$startsAt->format('d/m/Y')}\nHorário: {$startsAt->format('H:i')}{$staffLine}\n\n1 - Sim, cancelar\n2 - Não, manter",
                    [
                        'flow'    => BotFlow::CANCEL_APPOINTMENT->value,
                        'step'    => 'confirm',
                        'context' => ['cancel_appointment' => $cancelContext],
                    ]
                );
            }

            return $this->botStateResult('Ok, até logo!', $mainMenuState);
        }

        $appointment = Appointment::query()
            ->where('company_id', (int) ($companyEntity?->id ?? 0))
            ->find($appointmentId);

        if (! $appointment || ! in_array((string) $appointment->status, [AppointmentStatus::PENDING, AppointmentStatus::CONFIRMED], true)) {
            return $this->botStateResult(
                'Não foi possível cancelar: agendamento não encontrado ou já cancelado.',
                $mainMenuState
            );
        }

        $oldStatus                    = (string) $appointment->status;
        $appointment->status          = AppointmentStatus::CANCELLED;
        $appointment->cancelled_at    = now();
        $appointment->cancelled_reason = 'Cancelado pelo cliente via WhatsApp';
        $appointment->save();

        AppointmentEvent::create([
            'company_id'           => (int) $appointment->company_id,
            'appointment_id'       => (int) $appointment->id,
            'event_type'           => 'status_changed',
            'performed_by_user_id' => null,
            'payload'              => [
                'from'    => $oldStatus,
                'to'      => AppointmentStatus::CANCELLED,
                'reason'  => 'Cancelado pelo cliente via WhatsApp',
                'channel' => 'whatsapp_bot',
            ],
        ]);

        return $this->botStateResult('✅ Agendamento cancelado com sucesso!', $mainMenuState);
    }

    // -------------------------------------------------------------------------
    // Step handlers (private)
    // -------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function handleAppointmentServiceSelection(
        ?Company $company,
        Conversation $conversation,
        string $normalizedText,
        array $context
    ): array {
        $services = $this->activeAppointmentServices($company);
        if ($services === []) {
            return $this->handoffResult(
                $company,
                $conversation,
                'No momento não há agenda automática disponível. Vou te encaminhar para um atendente.',
                (string) ($context['target_area_name'] ?? BotFlowRegistry::AREA_ATTENDANCE)
            );
        }

        $serviceOptions = $this->enumerateServices($services);
        if (! isset($serviceOptions[$normalizedText])) {
            $serviceText = $this->appointmentServiceMenuText($serviceOptions, true);

            return $this->botStateResult(
                $serviceText,
                [
                    'flow'    => BotFlow::APPOINTMENTS->value,
                    'step'    => 'service_select',
                    'context' => ['appointment' => array_merge($context, ['service_options' => $serviceOptions])],
                ],
                $this->buildAppointmentServiceListMessage($serviceOptions, $serviceText)
            );
        }

        $selectedService            = $serviceOptions[$normalizedText];
        $context['service_id']      = (int) $selectedService['id'];
        $context['service_name']    = (string) $selectedService['name'];
        unset($context['selected_date'], $context['slot_page'], $context['slot_starts_at'], $context['slot_ends_at']);

        $settings         = $this->appointmentSettings($company);
        $staffProfiles    = $this->activeAppointmentStaff($company);
        $allowChooseStaff = (bool) ($settings?->allow_customer_choose_staff ?? true);
        $hasMoreThanOne   = count($staffProfiles) > 1;

        if (! $allowChooseStaff || ! $hasMoreThanOne) {
            $context['staff_profile_id'] = count($staffProfiles) === 1 ? (int) $staffProfiles[0]['id'] : null;
            $context['staff_name']       = count($staffProfiles) === 1 ? (string) $staffProfiles[0]['name'] : null;
            $context['week_start']       = $this->currentWeekStart($settings?->timezone)->toDateString();

            return $this->replyWithAppointmentDayMenu($company, $context);
        }

        $context['staff_options'] = $this->enumerateStaff($staffProfiles);
        $staffText = $this->appointmentStaffMenuText($context['staff_options']);

        return $this->botStateResult(
            $staffText,
            [
                'flow'    => BotFlow::APPOINTMENTS->value,
                'step'    => 'staff_select',
                'context' => ['appointment' => $context],
            ],
            $this->buildAppointmentStaffListMessage($context['staff_options'], $staffText)
        );
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function handleAppointmentStaffSelection(
        ?Company $company,
        Conversation $conversation,
        string $normalizedText,
        array $context
    ): array {
        $staffOptions = is_array($context['staff_options'] ?? null)
            ? $context['staff_options']
            : $this->enumerateStaff($this->activeAppointmentStaff($company));

        if ($normalizedText === '8') {
            $context['staff_profile_id'] = null;
            $context['staff_name']       = null;
            unset($context['last_day_key'], $context['last_day_date'], $context['selected_date'], $context['slot_page']);

            return $this->replyWithAppointmentDayMenu($company, $context);
        }

        if (! isset($staffOptions[$normalizedText])) {
            $staffText = $this->appointmentStaffMenuText($staffOptions, true);

            return $this->botStateResult(
                $staffText,
                [
                    'flow'    => BotFlow::APPOINTMENTS->value,
                    'step'    => 'staff_select',
                    'context' => ['appointment' => array_merge($context, ['staff_options' => $staffOptions])],
                ],
                $this->buildAppointmentStaffListMessage($staffOptions, $staffText)
            );
        }

        $selectedStaff                = $staffOptions[$normalizedText];
        $context['staff_profile_id']  = (int) $selectedStaff['id'];
        $context['staff_name']        = (string) $selectedStaff['name'];
        unset($context['last_day_key'], $context['last_day_date'], $context['selected_date'], $context['slot_page']);

        return $this->replyWithAppointmentDayMenu($company, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function handleAppointmentDaySelection(
        ?Company $company,
        Conversation $conversation,
        string $normalizedText,
        array $context
    ): array {
        $settings = $this->appointmentSettings($company);
        $timezone = (string) ($settings?->timezone ?: 'America/Sao_Paulo');
        $maxDays  = (int) ($settings?->booking_max_advance_days ?? 30);

        if ($normalizedText === '0' && (bool) ($context['has_staff_choice'] ?? false)) {
            $staffProfiles            = $this->activeAppointmentStaff($company);
            $context['staff_options'] = $this->enumerateStaff($staffProfiles);
            unset($context['last_day_key'], $context['last_day_date'], $context['selected_date'], $context['slot_page']);
            $staffText = $this->appointmentStaffMenuText($context['staff_options']);

            return $this->botStateResult(
                $staffText,
                [
                    'flow'    => BotFlow::APPOINTMENTS->value,
                    'step'    => 'staff_select',
                    'context' => ['appointment' => $context],
                ],
                $this->buildAppointmentStaffListMessage($context['staff_options'], $staffText)
            );
        }

        $lastDayKey  = trim((string) ($context['last_day_key'] ?? ''));
        $lastDayDate = trim((string) ($context['last_day_date'] ?? ''));
        if ($normalizedText === '7' && $lastDayKey !== '' && $lastDayDate !== '') {
            $fromDate = CarbonImmutable::parse($lastDayDate, $timezone)->addWeek()->toDateString();
            $maxDate  = CarbonImmutable::now($timezone)->addDays($maxDays)->toDateString();
            if ($fromDate > $maxDate) {
                return $this->replyWithAppointmentDayMenu($company, $context, false,
                    "Não há semanas disponíveis dentro do limite de {$maxDays} dias.");
            }
            $lastDt                   = CarbonImmutable::parse($lastDayDate, $timezone);
            $selectedDate             = $this->nextOccurrenceOfDay((int) $lastDt->dayOfWeek, $fromDate, $timezone);
            $context['last_day_date'] = $selectedDate;
            $selectedDt               = CarbonImmutable::parse($selectedDate, $timezone);
            $slots                    = $this->appointmentSlotsForDate($company, $context, $selectedDate);
            if ($slots === []) {
                $noSlotMsg  = "Não há horários disponíveis em " . $selectedDt->translatedFormat('D d/m') . ".";
                $noSlotMsg .= "\n\n" . $this->appointmentDayPromptText($context);

                return $this->botStateResult($noSlotMsg, [
                    'flow'    => BotFlow::APPOINTMENTS->value,
                    'step'    => 'day_select',
                    'context' => ['appointment' => $context],
                ], $this->buildAppointmentDayListMessage($context, $noSlotMsg));
            }
            $context['selected_date'] = $selectedDate;
            $context['slot_page']     = 0;

            return $this->replyWithAppointmentSlotMenu($company, $context);
        }

        if ($normalizedText === '8') {
            return $this->replyWithNearestSlots($company, $context);
        }

        $parsed = $this->parseDayInput($normalizedText, $timezone);
        if ($parsed === null) {
            return $this->replyWithAppointmentDayMenu($company, $context, true, '');
        }

        $fromDate     = $parsed['type'] === 'weekday'
            ? CarbonImmutable::now($timezone)->startOfDay()->toDateString()
            : $parsed['date'];
        $selectedDate = $this->nextOccurrenceOfDay($parsed['day_of_week'], $fromDate, $timezone);
        $maxDate      = CarbonImmutable::now($timezone)->addDays($maxDays)->toDateString();

        if ($selectedDate > $maxDate) {
            $maxDaysText = "Não é possível agendar além de {$maxDays} dias. Escolha um dia mais próximo.\n\n" . $this->appointmentDayPromptText($context);

            return $this->botStateResult(
                $maxDaysText,
                [
                    'flow'    => BotFlow::APPOINTMENTS->value,
                    'step'    => 'day_select',
                    'context' => ['appointment' => $context],
                ],
                $this->buildAppointmentDayListMessage($context, $maxDaysText)
            );
        }

        $context['last_day_key']  = $parsed['key'];
        $context['last_day_date'] = $selectedDate;

        $selectedDt = CarbonImmutable::parse($selectedDate, $timezone);
        $slots      = $this->appointmentSlotsForDate($company, $context, $selectedDate);

        if ($slots === []) {
            $noSlotMsg  = "Não há horários disponíveis em " . $selectedDt->translatedFormat('D d/m') . ".";
            $noSlotMsg .= "\n\n" . $this->appointmentDayPromptText($context);

            return $this->botStateResult(
                $noSlotMsg,
                [
                    'flow'    => BotFlow::APPOINTMENTS->value,
                    'step'    => 'day_select',
                    'context' => ['appointment' => $context],
                ],
                $this->buildAppointmentDayListMessage($context, $noSlotMsg)
            );
        }

        $context['selected_date'] = $selectedDate;
        $context['slot_page']     = 0;

        return $this->replyWithAppointmentSlotMenu($company, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function handleAppointmentSlotSelection(
        ?Company $company,
        Conversation $conversation,
        string $normalizedText,
        array $context
    ): array {
        if ($normalizedText === '0') {
            return $this->replyWithAppointmentDayMenu($company, $context);
        }

        if ($normalizedText === '7') {
            $selectedDate = trim((string) ($context['selected_date'] ?? ''));
            $settings     = $this->appointmentSettings($company);
            $timezone     = (string) ($settings?->timezone ?: 'America/Sao_Paulo');
            $maxDays      = (int) ($settings?->booking_max_advance_days ?? 30);
            if ($selectedDate !== '') {
                $nextWeek = CarbonImmutable::parse($selectedDate, $timezone)->addWeek()->toDateString();
                $maxDate  = CarbonImmutable::now($timezone)->addDays($maxDays)->toDateString();
                if ($nextWeek <= $maxDate) {
                    $context['selected_date'] = $nextWeek;
                    $context['last_day_date'] = $nextWeek;
                    $context['slot_page']     = 0;

                    return $this->replyWithAppointmentSlotMenu($company, $context);
                }
            }

            return $this->replyWithAppointmentSlotMenu($company, $context, true);
        }

        if ($normalizedText === '8') {
            $context['slot_page'] = (int) ($context['slot_page'] ?? 0) + 1;

            return $this->replyWithAppointmentSlotMenu($company, $context);
        }

        $selectedDate = trim((string) ($context['selected_date'] ?? ''));
        if ($selectedDate === '') {
            return $this->replyWithAppointmentDayMenu($company, $context);
        }

        $slots = $this->appointmentSlotsForDate($company, $context, $selectedDate);
        if ($slots === []) {
            return $this->replyWithAppointmentDayMenu($company, $context);
        }

        $page      = max(0, (int) ($context['slot_page'] ?? 0));
        $offset    = $page * 6;
        $pageSlots = array_slice($slots, $offset, 6);
        $slotOptions = [];
        foreach ($pageSlots as $index => $slot) {
            $slotOptions[(string) ($index + 1)] = $slot;
        }

        if (! isset($slotOptions[$normalizedText])) {
            return $this->replyWithAppointmentSlotMenu($company, $context, true);
        }

        $selectedSlot                  = $slotOptions[$normalizedText];
        $context['slot_starts_at']     = (string) ($selectedSlot['starts_at_local'] ?? $selectedSlot['starts_at'] ?? '');
        $context['slot_ends_at']       = (string) ($selectedSlot['ends_at_local'] ?? $selectedSlot['ends_at'] ?? '');
        $context['staff_profile_id']   = (int) ($selectedSlot['staff_profile_id'] ?? ($context['staff_profile_id'] ?? 0));
        $context['staff_name']         = (string) ($selectedSlot['staff_name'] ?? ($context['staff_name'] ?? ''));

        return $this->botStateResult(
            "Para enviarmos a confirmação por e-mail, informe seu endereço de e-mail ou responda *pular* para continuar sem.",
            [
                'flow'    => BotFlow::APPOINTMENTS->value,
                'step'    => 'collect_email',
                'context' => ['appointment' => $context],
            ]
        );
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function handleAppointmentNearestSelection(
        ?Company $company,
        Conversation $conversation,
        string $normalizedText,
        array $context
    ): array {
        if ($normalizedText === '0') {
            return $this->replyWithAppointmentDayMenu($company, $context);
        }

        $slots = $context['nearest_slots'] ?? [];
        if (! is_array($slots) || $slots === []) {
            return $this->replyWithNearestSlots($company, $context);
        }

        $slotOptions = [];
        foreach (array_slice($slots, 0, 7) as $index => $slot) {
            $slotOptions[(string) ($index + 1)] = $slot;
        }

        if (! isset($slotOptions[$normalizedText])) {
            $timezone         = (string) ($this->appointmentSettings($company)?->timezone ?: 'America/Sao_Paulo');
            $hasStaffChoice   = (bool) ($context['has_staff_choice'] ?? false);
            $nearestInvalidText = "Opção inválida. Escolha um número da lista... ou \"menu\" para voltar ao menu principal.\n\n" . $this->appointmentNearestSlotsText($slots, $timezone, $hasStaffChoice);

            return $this->botStateResult(
                $nearestInvalidText,
                [
                    'flow'    => BotFlow::APPOINTMENTS->value,
                    'step'    => 'nearest_select',
                    'context' => ['appointment' => $context],
                ],
                $this->buildAppointmentNearestSlotsListMessage($slots, $timezone, $nearestInvalidText)
            );
        }

        $selectedSlot                = $slotOptions[$normalizedText];
        $context['selected_date']    = (string) ($selectedSlot['date'] ?? '');
        $context['slot_starts_at']   = (string) ($selectedSlot['starts_at_local'] ?? $selectedSlot['starts_at'] ?? '');
        $context['slot_ends_at']     = (string) ($selectedSlot['ends_at_local'] ?? $selectedSlot['ends_at'] ?? '');
        $context['staff_profile_id'] = (int) ($selectedSlot['staff_profile_id'] ?? ($context['staff_profile_id'] ?? 0));
        $context['staff_name']       = (string) ($selectedSlot['staff_name'] ?? ($context['staff_name'] ?? ''));

        return $this->botStateResult(
            "Para enviarmos a confirmação por e-mail, informe seu endereço de e-mail ou responda *pular* para continuar sem.",
            [
                'flow'    => BotFlow::APPOINTMENTS->value,
                'step'    => 'collect_email',
                'context' => ['appointment' => $context],
            ]
        );
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function handleAppointmentEmailCollection(
        ?Company $company,
        Conversation $conversation,
        string $normalizedText,
        array $context
    ): array {
        $timezone = $this->appointmentSettings($company)?->timezone ?: 'America/Sao_Paulo';

        if (mb_strtolower(trim($normalizedText)) !== 'pular' && filter_var($normalizedText, FILTER_VALIDATE_EMAIL)) {
            $context['customer_email'] = trim($normalizedText);
        } elseif (mb_strtolower(trim($normalizedText)) !== 'pular') {
            return $this->botStateResult(
                "E-mail inválido. Por favor, informe um e-mail válido ou responda *pular* para continuar sem.",
                [
                    'flow'    => BotFlow::APPOINTMENTS->value,
                    'step'    => 'collect_email',
                    'context' => ['appointment' => $context],
                ]
            );
        }

        $confirmText = $this->appointmentConfirmText($context, $timezone);

        return $this->botStateResult(
            $confirmText,
            [
                'flow'    => BotFlow::APPOINTMENTS->value,
                'step'    => 'confirm',
                'context' => ['appointment' => $context],
            ],
            $this->buildAppointmentConfirmButtonMessage($confirmText)
        );
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function handleAppointmentConfirmation(
        ?Company $company,
        Conversation $conversation,
        string $normalizedText,
        array $context
    ): array {
        $companyEntity = $this->resolveCompany($company, $conversation);
        if ($normalizedText === '2') {
            return $this->replyWithAppointmentSlotMenu($companyEntity, $context);
        }

        $timezone = $this->appointmentSettings($companyEntity)?->timezone ?: 'America/Sao_Paulo';

        if ($normalizedText !== '1') {
            $confirmText = "Opção inválida. Responda com 1, 2 ou 9... ou \"menu\" para voltar ao menu principal.\n\n" . $this->appointmentConfirmText($context, $timezone);

            return $this->botStateResult(
                $confirmText,
                [
                    'flow'    => BotFlow::APPOINTMENTS->value,
                    'step'    => 'confirm',
                    'context' => ['appointment' => $context],
                ],
                $this->buildAppointmentConfirmButtonMessage($confirmText)
            );
        }

        if (! $companyEntity?->id) {
            return $this->handoffResult(
                $companyEntity,
                $conversation,
                'Não consegui validar a agenda automática agora. Vou te encaminhar para um atendente.',
                (string) ($context['target_area_name'] ?? BotFlowRegistry::AREA_ATTENDANCE)
            );
        }

        try {
            $appointment = $this->appointmentBooking->createAppointment(
                $companyEntity,
                [
                    'service_id'       => (int) ($context['service_id'] ?? 0),
                    'staff_profile_id' => (int) ($context['staff_profile_id'] ?? 0),
                    'starts_at'        => (string) ($context['slot_starts_at'] ?? ''),
                    'customer_name'    => $conversation->customer_name,
                    'customer_phone'   => $conversation->customer_phone,
                    'customer_email'   => $this->nullableContextEmail($context['customer_email'] ?? null),
                    'source'           => 'whatsapp',
                    'meta'             => ['bot_flow' => 'stateful_appointments'],
                ],
                null
            );
        } catch (ValidationException $exception) {
            $message       = collect($exception->errors())->flatten()->first() ?: 'Não consegui confirmar esse horário.';
            $validationText = "{$message}\n\n" . $this->appointmentDayPromptText($context);

            return $this->botStateResult(
                $validationText,
                [
                    'flow'    => BotFlow::APPOINTMENTS->value,
                    'step'    => 'day_select',
                    'context' => ['appointment' => $context],
                ],
                $this->buildAppointmentDayListMessage($context, $validationText)
            );
        }

        $startsAt      = $appointment->starts_at?->setTimezone($timezone);
        $dayName       = $startsAt?->translatedFormat('l') ?? '';
        $startsAtDate  = $startsAt?->format('d/m/Y') ?? '';
        $startsAtTime  = $startsAt?->format('H:i') ?? '';
        $serviceName   = (string) ($context['service_name'] ?? 'serviço');
        $staffName     = trim((string) ($context['staff_name'] ?? ''));
        $staffPart     = $staffName !== '' ? "\nAtendente: {$staffName}" : '';
        $replyText     = "✅ Agendamento confirmado!\n\nData: {$dayName}, {$startsAtDate}\nHorário: {$startsAtTime}\nServiço: {$serviceName}{$staffPart}\n\nAté lá! 😊";

        $menu = $this->buildInitialMenuResponse($this->registry->definitionForCompany($companyEntity));

        return $this->botStateResult(
            $replyText,
            $menu['new_state'] ?? [
                'flow'    => BotFlow::MAIN->value,
                'step'    => 'menu',
                'context' => ['last_menu_keys' => ['1', '2', '3']],
            ]
        );
    }

    // -------------------------------------------------------------------------
    // Reply builders (private)
    // -------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function replyWithAppointmentDayMenu(
        ?Company $company,
        array $context,
        bool $invalidOption = false,
        string $extraMessage = ''
    ): array {
        $parts = [];
        if ($invalidOption) {
            $parts[] = "Não entendi. Diga um dia como: segunda, terça, hoje, amanhã ou 15/04... ou \"menu\" para voltar ao menu principal.";
        }
        if ($extraMessage !== '') {
            $parts[] = $extraMessage;
        }
        $parts[] = $this->appointmentDayPromptText($context);
        $text    = implode("\n\n", $parts);

        return $this->botStateResult(
            $text,
            [
                'flow'    => BotFlow::APPOINTMENTS->value,
                'step'    => 'day_select',
                'context' => ['appointment' => $context],
            ],
            $this->buildAppointmentDayListMessage($context, $text)
        );
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function replyWithAppointmentSlotMenu(
        ?Company $company,
        array $context,
        bool $invalidOption = false
    ): array {
        $selectedDate = trim((string) ($context['selected_date'] ?? ''));
        if ($selectedDate === '') {
            return $this->replyWithAppointmentDayMenu($company, $context);
        }

        $slots = $this->appointmentSlotsForDate($company, $context, $selectedDate);
        if ($slots === []) {
            return $this->replyWithAppointmentDayMenu($company, $context);
        }

        $page   = max(0, (int) ($context['slot_page'] ?? 0));
        $offset = $page * 6;
        if ($offset >= count($slots)) {
            $page   = 0;
            $offset = 0;
        }

        $context['slot_page'] = $page;
        $pageSlots            = array_slice($slots, $offset, 6);
        $prefix               = $invalidOption ? "Opção inválida. Escolha um número da lista... ou \"menu\" para voltar ao menu principal.\n\n" : '';
        $timezone             = $this->appointmentSettings($company)?->timezone ?: 'America/Sao_Paulo';
        $hasStaffChoice       = (bool) ($context['has_staff_choice'] ?? false);
        $hasMore              = ($offset + 6) < count($slots);
        $slotMenuText         = $prefix . $this->appointmentSlotMenuText($selectedDate, $pageSlots, $hasMore, $timezone, $hasStaffChoice);

        return $this->botStateResult(
            $slotMenuText,
            [
                'flow'    => BotFlow::APPOINTMENTS->value,
                'step'    => 'slot_select',
                'context' => ['appointment' => $context],
            ],
            $this->buildAppointmentSlotListMessage($pageSlots, $hasMore, $timezone, $hasStaffChoice, $slotMenuText)
        );
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function replyWithNearestSlots(?Company $company, array $context): array
    {
        $slots = $this->appointmentNearestSlots($company, $context);

        if ($slots === []) {
            return $this->replyWithAppointmentDayMenu($company, $context, false,
                'Não há horários disponíveis nos próximos dias.');
        }

        $context['nearest_slots'] = $slots;
        $timezone                 = (string) ($this->appointmentSettings($company)?->timezone ?: 'America/Sao_Paulo');
        $hasStaffChoice           = (bool) ($context['has_staff_choice'] ?? false);
        $nearestText              = $this->appointmentNearestSlotsText($slots, $timezone, $hasStaffChoice);

        return $this->botStateResult(
            $nearestText,
            [
                'flow'    => BotFlow::APPOINTMENTS->value,
                'step'    => 'nearest_select',
                'context' => ['appointment' => $context],
            ],
            $this->buildAppointmentNearestSlotsListMessage($slots, $timezone, $nearestText)
        );
    }

    // -------------------------------------------------------------------------
    // Data helpers (private)
    // -------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $context
     * @return array<int, array<string, mixed>>
     */
    private function appointmentNearestSlots(?Company $company, array $context, int $limit = 7): array
    {
        if (! $company?->id) {
            return [];
        }

        $serviceId = (int) ($context['service_id'] ?? 0);
        if ($serviceId <= 0) {
            return [];
        }

        $staffId  = isset($context['staff_profile_id']) && (int) $context['staff_profile_id'] > 0
            ? (int) $context['staff_profile_id']
            : null;

        $settings = $this->appointmentSettings($company);
        $timezone = (string) ($settings?->timezone ?: 'America/Sao_Paulo');
        $maxDays  = (int) ($settings?->booking_max_advance_days ?? 30);
        $from     = CarbonImmutable::now($timezone)->startOfDay();
        $to       = $from->addDays($maxDays);

        return $this->appointmentAvailability->listAvailableSlotsMultiDay(
            $company, $serviceId, $from, $to, $staffId, $limit
        );
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<int, array<string, mixed>>
     */
    private function appointmentSlotsForDate(?Company $company, array $context, string $date): array
    {
        if (! $company?->id) {
            return [];
        }

        $serviceId = (int) ($context['service_id'] ?? 0);
        if ($serviceId <= 0) {
            return [];
        }

        $staffId      = isset($context['staff_profile_id']) && (int) $context['staff_profile_id'] > 0
            ? (int) $context['staff_profile_id']
            : null;
        $availability = $this->appointmentAvailability->listAvailableSlots($company, $serviceId, $date, $staffId);
        $slots        = [];

        foreach (($availability['staff'] ?? []) as $staffAvailability) {
            $staffProfileId = (int) ($staffAvailability['staff_profile_id'] ?? 0);
            $staffName      = (string) ($staffAvailability['staff_name'] ?? '');
            foreach (($staffAvailability['slots'] ?? []) as $slot) {
                $slots[] = [
                    'starts_at'       => (string) ($slot['starts_at'] ?? ''),
                    'ends_at'         => (string) ($slot['ends_at'] ?? ''),
                    'starts_at_local' => (string) ($slot['starts_at_local'] ?? ''),
                    'ends_at_local'   => (string) ($slot['ends_at_local'] ?? ''),
                    'staff_profile_id' => $staffProfileId,
                    'staff_name'      => $staffName,
                ];
            }
        }

        usort($slots, fn($a, $b) => strcmp((string) $a['starts_at'], (string) $b['starts_at']));

        return $slots;
    }

    /**
     * @return array<int, array{id:int,name:string}>
     */
    private function activeAppointmentServices(?Company $company): array
    {
        if (! $company?->id) {
            return [];
        }

        return AppointmentService::query()
            ->where('company_id', (int) $company->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn(AppointmentService $service) => [
                'id'   => (int) $service->id,
                'name' => (string) $service->name,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{id:int,name:string}>
     */
    private function activeAppointmentStaff(?Company $company): array
    {
        if (! $company?->id) {
            return [];
        }

        return AppointmentStaffProfile::query()
            ->where('company_id', (int) $company->id)
            ->where('is_bookable', true)
            ->with('user:id,name')
            ->orderBy('id')
            ->get(['id', 'display_name', 'user_id'])
            ->map(fn(AppointmentStaffProfile $profile) => [
                'id'   => (int) $profile->id,
                'name' => trim((string) ($profile->display_name ?: $profile->user?->name ?: 'Atendente')),
            ])
            ->values()
            ->all();
    }

    private function appointmentSettings(?Company $company): ?AppointmentSetting
    {
        if (! $company?->id) {
            return null;
        }

        return AppointmentSetting::query()->firstOrCreate(
            ['company_id' => (int) $company->id],
            [
                'timezone'                       => 'America/Sao_Paulo',
                'slot_interval_minutes'          => 15,
                'booking_min_notice_minutes'     => 120,
                'booking_max_advance_days'       => 30,
                'cancellation_min_notice_minutes' => 120,
                'reschedule_min_notice_minutes'  => 120,
                'allow_customer_choose_staff'    => true,
            ]
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function appointmentContext(Conversation $conversation): array
    {
        $context            = is_array($conversation->bot_context ?? null) ? $conversation->bot_context : [];
        $appointmentContext = is_array($context['appointment'] ?? null) ? $context['appointment'] : [];

        return $appointmentContext;
    }

    private function currentWeekStart(?string $timezone): CarbonImmutable
    {
        $tz = $timezone ?: 'America/Sao_Paulo';

        return CarbonImmutable::now($tz)->startOfWeek(CarbonImmutable::MONDAY);
    }

    private function parseWeekStart(mixed $weekStart, ?string $timezone): CarbonImmutable
    {
        $tz    = $timezone ?: 'America/Sao_Paulo';
        $value = trim((string) $weekStart);
        if ($value === '') {
            return $this->currentWeekStart($tz);
        }

        try {
            return CarbonImmutable::parse($value, $tz)->startOfDay();
        } catch (\Throwable) {
            return $this->currentWeekStart($tz);
        }
    }

    /**
     * @return array{type:string,day_of_week:int,date:string,key:string}|null
     */
    private function parseDayInput(string $text, string $timezone): ?array
    {
        $tz      = $timezone ?: 'America/Sao_Paulo';
        $accents = [
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
            'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'ç' => 'c', 'ñ' => 'n',
        ];
        $n     = mb_strtolower(trim(strtr($text, $accents)));
        $today = CarbonImmutable::now($tz)->startOfDay();

        if ($n === 'hoje') {
            return ['type' => 'absolute', 'day_of_week' => (int) $today->dayOfWeek, 'date' => $today->toDateString(), 'key' => 'hoje'];
        }

        if ($n === 'amanha') {
            $tomorrow = $today->addDay();

            return ['type' => 'absolute', 'day_of_week' => (int) $tomorrow->dayOfWeek, 'date' => $tomorrow->toDateString(), 'key' => 'amanha'];
        }

        $weekdayMap = [
            'domingo' => 0, 'dom' => 0,
            'segunda' => 1, 'segunda-feira' => 1, 'seg' => 1,
            'terca' => 2, 'terca-feira' => 2, 'ter' => 2,
            'quarta' => 3, 'quarta-feira' => 3, 'qua' => 3,
            'quinta' => 4, 'quinta-feira' => 4, 'qui' => 4,
            'sexta' => 5, 'sexta-feira' => 5, 'sex' => 5,
            'sabado' => 6, 'sab' => 6,
        ];

        if (isset($weekdayMap[$n])) {
            return ['type' => 'weekday', 'day_of_week' => $weekdayMap[$n], 'date' => '', 'key' => $n];
        }

        if (preg_match('/^(\d{1,2})\/(\d{1,2})(?:\/(\d{2,4}))?$/', $n, $m)) {
            $day   = (int) $m[1];
            $month = (int) $m[2];
            $year  = isset($m[3]) && $m[3] !== '' ? (int) $m[3] : (int) $today->year;
            if ($year < 100) {
                $year += 2000;
            }
            try {
                $c = CarbonImmutable::createSafe($year, $month, $day, 0, 0, 0, $tz);
                if ($c === false) {
                    return null;
                }

                return ['type' => 'absolute', 'day_of_week' => (int) $c->dayOfWeek, 'date' => $c->toDateString(), 'key' => $c->toDateString()];
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    private function nextOccurrenceOfDay(int $dayOfWeek, string $fromDate, string $timezone): string
    {
        $tz   = $timezone ?: 'America/Sao_Paulo';
        $date = CarbonImmutable::parse($fromDate, $tz)->startOfDay();
        $diff = ($dayOfWeek - (int) $date->dayOfWeek + 7) % 7;

        return $date->addDays($diff)->toDateString();
    }

    // -------------------------------------------------------------------------
    // Text builders (private)
    // -------------------------------------------------------------------------

    /**
     * @param  array<string, array{id:int,name:string}>  $serviceOptions
     */
    private function appointmentServiceMenuText(array $serviceOptions, bool $invalid = false): string
    {
        $lines = [];
        if ($invalid) {
            $lines[] = 'Opção inválida.';
        }
        $lines[] = 'Agendamento: escolha o serviço:';
        foreach ($serviceOptions as $key => $service) {
            $lines[] = "{$key} - {$service['name']}";
        }
        $lines[] = '9 - Falar com atendente';

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, array{id:int,name:string}>  $staffOptions
     */
    private function appointmentStaffMenuText(array $staffOptions, bool $invalid = false): string
    {
        $lines = [];
        if ($invalid) {
            $lines[] = 'Opção inválida. Escolha um número da lista... ou "menu" para voltar ao menu principal.';
        }
        $lines[] = 'Escolha o atendente:';
        foreach ($staffOptions as $key => $staff) {
            $lines[] = "{$key} - {$staff['name']}";
        }
        $lines[] = '8 - Qualquer atendente';
        $lines[] = '9 - Falar com atendente';

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function appointmentDayPromptText(array $context): string
    {
        $staffName      = trim((string) ($context['staff_name'] ?? ''));
        $staffLine      = $staffName !== '' ? "Atendente: {$staffName}" : 'Atendente: qualquer disponível';
        $hasStaffChoice = (bool) ($context['has_staff_choice'] ?? false);
        $hasLastDay     = trim((string) ($context['last_day_date'] ?? '')) !== '';

        $lines = [
            $staffLine,
            '',
            'Qual dia você prefere?',
            'Digite: segunda, terça, quarta, hoje, amanhã...',
        ];
        if ($hasLastDay) {
            $lines[] = '7 - Próxima semana';
        }
        $lines[] = '8 - Próximos horários';
        $lines[] = '9 - Falar com atendente';
        if ($hasStaffChoice) {
            $lines[] = '0 - Trocar atendente';
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<int, array<string, mixed>>  $slots
     */
    private function appointmentSlotMenuText(
        string $selectedDate,
        array $slots,
        bool $hasMore,
        string $timezone = 'America/Sao_Paulo',
        bool $hasStaffChoice = false
    ): string {
        $tz    = $timezone ?: 'America/Sao_Paulo';
        $date  = CarbonImmutable::parse($selectedDate);
        $lines = ['Horários de ' . $date->translatedFormat('D d/m') . ':'];
        foreach ($slots as $index => $slot) {
            $candidate = (string) ($slot['starts_at_local'] ?? $slot['starts_at'] ?? '');
            $startsAt  = CarbonImmutable::parse($candidate)->setTimezone($tz);
            $timeLabel = $startsAt->format('H:i');
            $staffName = trim((string) ($slot['staff_name'] ?? ''));
            $suffix    = $staffName !== '' ? " ({$staffName})" : '';
            $lines[]   = ($index + 1) . " - {$timeLabel}{$suffix}";
        }
        $lines[] = '7 - Próxima semana';
        if ($hasMore) {
            $lines[] = '8 - Ver mais horários';
        }
        $lines[] = '0 - Voltar (trocar dia' . ($hasStaffChoice ? ' ou atendente' : '') . ')';
        $lines[] = '9 - Falar com atendente';

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function appointmentConfirmText(array $context, string $timezone = 'America/Sao_Paulo'): string
    {
        $tz          = $timezone ?: 'America/Sao_Paulo';
        $startsAt    = CarbonImmutable::parse((string) ($context['slot_starts_at'] ?? ''))->setTimezone($tz);
        $serviceName = (string) ($context['service_name'] ?? 'serviço');
        $staffName   = trim((string) ($context['staff_name'] ?? ''));
        $staffText   = $staffName !== '' ? "Atendente: {$staffName}\n" : '';
        $dayName     = $startsAt->translatedFormat('l');

        return "Confirma o agendamento?\nData: {$dayName}, {$startsAt->format('d/m/Y')}\nHora: {$startsAt->format('H:i')}\nServiço: {$serviceName}\n{$staffText}1 - Confirmar\n2 - Escolher outro horário\n9 - Falar com atendente";
    }

    /**
     * @param  array<int, array<string, mixed>>  $slots
     */
    private function appointmentNearestSlotsText(array $slots, string $timezone = 'America/Sao_Paulo', bool $hasStaffChoice = false): string
    {
        $tz    = $timezone ?: 'America/Sao_Paulo';
        $lines = ['Próximos horários disponíveis:'];
        foreach (array_slice($slots, 0, 7) as $index => $slot) {
            $candidate = (string) ($slot['starts_at_local'] ?? $slot['starts_at'] ?? '');
            $startsAt  = CarbonImmutable::parse($candidate)->setTimezone($tz);
            $dateLabel = $startsAt->translatedFormat('D d/m');
            $timeLabel = $startsAt->format('H:i');
            $staffName = trim((string) ($slot['staff_name'] ?? ''));
            $suffix    = $staffName !== '' ? " ({$staffName})" : '';
            $lines[]   = ($index + 1) . " - {$dateLabel} {$timeLabel}{$suffix}";
        }
        $lines[] = '0 - Voltar';
        $lines[] = '9 - Falar com atendente';

        return implode("\n", $lines);
    }

    // -------------------------------------------------------------------------
    // Enumeration helpers (private)
    // -------------------------------------------------------------------------

    /**
     * @param  array<int, array{id:int,name:string}>  $services
     * @return array<string, array{id:int,name:string}>
     */
    private function enumerateServices(array $services): array
    {
        $options = [];
        foreach (array_slice($services, 0, 8) as $index => $service) {
            $options[(string) ($index + 1)] = $service;
        }

        return $options;
    }

    /**
     * @param  array<int, array{id:int,name:string}>  $staffProfiles
     * @return array<string, array{id:int,name:string}>
     */
    private function enumerateStaff(array $staffProfiles): array
    {
        $options = [];
        foreach (array_slice($staffProfiles, 0, 7) as $index => $staff) {
            $options[(string) ($index + 1)] = $staff;
        }

        return $options;
    }

    // -------------------------------------------------------------------------
    // Interactive message builders (private)
    // -------------------------------------------------------------------------

    /** @param array<string, mixed> $context */
    private function buildAppointmentDayListMessage(array $context, string $bodyText = ''): array
    {
        $hasStaffChoice = (bool) ($context['has_staff_choice'] ?? false);
        $hasLastDay     = trim((string) ($context['last_day_date'] ?? '')) !== '';
        $staffName      = trim((string) ($context['staff_name'] ?? ''));
        $staffLine      = $staffName !== '' ? "Atendente: {$staffName}" : 'Atendente: qualquer disponível';

        if ($bodyText === '') {
            $bodyText = $staffLine . "\n\nQual dia você prefere?";
        }

        $rows = [
            ['id' => 'hoje',    'title' => 'Hoje',    'description' => ''],
            ['id' => 'amanha',  'title' => 'Amanhã',  'description' => ''],
            ['id' => 'segunda', 'title' => 'Segunda', 'description' => ''],
            ['id' => 'terca',   'title' => 'Terça',   'description' => ''],
            ['id' => 'quarta',  'title' => 'Quarta',  'description' => ''],
            ['id' => 'quinta',  'title' => 'Quinta',  'description' => ''],
            ['id' => 'sexta',   'title' => 'Sexta',   'description' => ''],
            ['id' => 'sabado',  'title' => 'Sábado',  'description' => ''],
        ];
        if ($hasLastDay) {
            $rows[] = ['id' => '7', 'title' => 'Próxima semana',    'description' => ''];
        }
        $rows[] = ['id' => '8', 'title' => 'Próximos horários',     'description' => ''];
        $rows[] = ['id' => '9', 'title' => 'Falar com atendente',   'description' => ''];
        if ($hasStaffChoice) {
            $rows[] = ['id' => '0', 'title' => 'Trocar atendente',  'description' => ''];
        }

        return [
            'type'         => 'interactive_list',
            'body_text'    => $bodyText,
            'header_text'  => '',
            'footer_text'  => '',
            'action_label' => 'Escolher dia',
            'rows'         => $rows,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $slots
     */
    private function buildAppointmentSlotListMessage(
        array $slots,
        bool $hasMore,
        string $timezone,
        bool $hasStaffChoice,
        string $bodyText = ''
    ): array {
        $tz   = $timezone ?: 'America/Sao_Paulo';
        $rows = [];
        foreach ($slots as $index => $slot) {
            $candidate = (string) ($slot['starts_at_local'] ?? $slot['starts_at'] ?? '');
            $startsAt  = CarbonImmutable::parse($candidate)->setTimezone($tz);
            $timeLabel = $startsAt->format('H:i');
            $staffName = trim((string) ($slot['staff_name'] ?? ''));
            $title     = $staffName !== '' ? "{$timeLabel} ({$staffName})" : $timeLabel;
            $rows[]    = ['id' => (string) ($index + 1), 'title' => mb_substr($title, 0, 24), 'description' => ''];
        }
        $rows[] = ['id' => '7', 'title' => 'Próxima semana',  'description' => ''];
        if ($hasMore) {
            $rows[] = ['id' => '8', 'title' => 'Ver mais horários', 'description' => ''];
        }
        $rows[] = ['id' => '0', 'title' => 'Voltar',              'description' => ''];
        $rows[] = ['id' => '9', 'title' => 'Falar com atendente', 'description' => ''];

        return [
            'type'         => 'interactive_list',
            'body_text'    => $bodyText,
            'header_text'  => '',
            'footer_text'  => '',
            'action_label' => 'Escolher horário',
            'rows'         => $rows,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $slots
     */
    private function buildAppointmentNearestSlotsListMessage(array $slots, string $timezone, string $bodyText = ''): array
    {
        $tz   = $timezone ?: 'America/Sao_Paulo';
        $rows = [];
        foreach (array_slice($slots, 0, 7) as $index => $slot) {
            $candidate = (string) ($slot['starts_at_local'] ?? $slot['starts_at'] ?? '');
            $startsAt  = CarbonImmutable::parse($candidate)->setTimezone($tz);
            $dateLabel = $startsAt->translatedFormat('D d/m');
            $timeLabel = $startsAt->format('H:i');
            $staffName = trim((string) ($slot['staff_name'] ?? ''));
            $suffix    = $staffName !== '' ? " ({$staffName})" : '';
            $title     = mb_substr("{$dateLabel} {$timeLabel}{$suffix}", 0, 24);
            $rows[]    = ['id' => (string) ($index + 1), 'title' => $title, 'description' => ''];
        }
        $rows[] = ['id' => '0', 'title' => 'Voltar',              'description' => ''];
        $rows[] = ['id' => '9', 'title' => 'Falar com atendente', 'description' => ''];

        return [
            'type'         => 'interactive_list',
            'body_text'    => $bodyText !== '' ? $bodyText : 'Próximos horários disponíveis:',
            'header_text'  => '',
            'footer_text'  => '',
            'action_label' => 'Escolher horário',
            'rows'         => $rows,
        ];
    }

    /**
     * @param array<string, array{id:int,name:string}> $serviceOptions
     */
    private function buildAppointmentServiceListMessage(array $serviceOptions, string $bodyText = ''): array
    {
        $body = $bodyText !== '' ? $bodyText : 'Agendamento: escolha o serviço:';
        $rows = [];
        foreach ($serviceOptions as $key => $service) {
            $rows[] = ['id' => (string) $key, 'title' => mb_substr((string) $service['name'], 0, 24), 'description' => ''];
        }
        $rows[] = ['id' => '9', 'title' => 'Falar com atendente', 'description' => ''];

        if (count($rows) <= 3) {
            return [
                'type'        => 'interactive_buttons',
                'body_text'   => $body,
                'header_text' => '',
                'footer_text' => '',
                'buttons'     => array_map(fn($r) => ['id' => $r['id'], 'title' => $r['title']], $rows),
            ];
        }

        return [
            'type'         => 'interactive_list',
            'body_text'    => $body,
            'header_text'  => '',
            'footer_text'  => '',
            'action_label' => 'Escolher serviço',
            'rows'         => $rows,
        ];
    }

    /**
     * @param array<string, array{id:int,name:string}> $staffOptions
     */
    private function buildAppointmentStaffListMessage(array $staffOptions, string $bodyText = ''): array
    {
        $body = $bodyText !== '' ? $bodyText : 'Escolha o atendente:';
        $rows = [];
        foreach ($staffOptions as $key => $staff) {
            $rows[] = ['id' => (string) $key, 'title' => mb_substr((string) $staff['name'], 0, 24), 'description' => ''];
        }
        $rows[] = ['id' => '8', 'title' => 'Qualquer atendente',  'description' => ''];
        $rows[] = ['id' => '9', 'title' => 'Falar com atendente', 'description' => ''];

        if (count($rows) <= 3) {
            return [
                'type'        => 'interactive_buttons',
                'body_text'   => $body,
                'header_text' => '',
                'footer_text' => '',
                'buttons'     => array_map(fn($r) => ['id' => $r['id'], 'title' => $r['title']], $rows),
            ];
        }

        return [
            'type'         => 'interactive_list',
            'body_text'    => $body,
            'header_text'  => '',
            'footer_text'  => '',
            'action_label' => 'Escolher atendente',
            'rows'         => $rows,
        ];
    }

    private function buildAppointmentConfirmButtonMessage(string $bodyText): array
    {
        return [
            'type'        => 'interactive_buttons',
            'body_text'   => $bodyText,
            'header_text' => '',
            'footer_text' => '',
            'buttons'     => [
                ['id' => '1', 'title' => 'Confirmar'],
                ['id' => '2', 'title' => 'Outro horário'],
                ['id' => '9', 'title' => 'Falar com atendente'],
            ],
        ];
    }
}
