<?php

declare(strict_types=1);


namespace App\Services\Bot\Handlers;

use App\Models\AppointmentService;
use App\Models\AppointmentSetting;
use App\Models\AppointmentStaffProfile;
use App\Models\Company;
use App\Models\Conversation;
use App\Services\Appointments\AppointmentAvailabilityService;
use App\Services\Appointments\AppointmentBookingService;
use App\Services\Bot\BotFlowRegistry;
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
        private AppointmentFlowMessageBuilder $messageBuilder,
        private AppointmentCancellationFlowHandler $cancellationHandler,
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

        $service                     = $services[0];
        $context                     = $this->appointmentContext($conversation);
        $context['service_id']       = (int) $service['id'];
        $context['service_name']     = (string) $service['name'];
        $context['target_area_name'] = trim((string) ($action['target_area_name'] ?? BotFlowRegistry::AREA_ATTENDANCE));

        $settings         = $this->appointmentSettings($companyEntity);
        $staffProfiles    = $this->activeAppointmentStaff($companyEntity);
        $allowChooseStaff = (bool) ($settings?->allow_customer_choose_staff ?? true);
        $hasMoreThanOne   = count($staffProfiles) > 1;

        $context['has_staff_choice'] = $allowChooseStaff && $hasMoreThanOne;

        if (! $allowChooseStaff || ! $hasMoreThanOne) {
            $this->setSingleStaffContext($context, $staffProfiles);

            return $this->replyWithAppointmentDayMenu($companyEntity, $context);
        }

        $context['staff_options'] = $this->messageBuilder->enumerateStaff($staffProfiles);
        $staffText = $this->messageBuilder->appointmentStaffMenuText($context['staff_options']);

        return $this->botStateResult(
            $staffText,
            [
                'flow'    => BotFlow::APPOINTMENTS->value,
                'step'    => 'staff_select',
                'context' => ['appointment' => $context],
            ],
            $this->messageBuilder->buildAppointmentStaffListMessage($context['staff_options'], $staffText)
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
            'service_select' => $this->handleAppointmentServiceSelection($companyEntity, $conversation, $normalizedText, $context),
            'staff_select'   => $this->handleAppointmentStaffSelection($companyEntity, $conversation, $normalizedText, $context),
            'day_select'     => $this->handleAppointmentDaySelection($companyEntity, $conversation, $normalizedText, $context),
            'slot_select'    => $this->handleAppointmentSlotSelection($companyEntity, $conversation, $normalizedText, $context),
            'nearest_select' => $this->handleAppointmentNearestSelection($companyEntity, $conversation, $normalizedText, $context),
            'collect_email'  => $this->handleAppointmentEmailCollection($companyEntity, $conversation, $normalizedText, $context),
            'confirm'        => $this->handleAppointmentConfirmation($companyEntity, $conversation, $normalizedText, $context),
            'collect_customer_name' => $this->handleAppointmentCustomerNameCollection($companyEntity, $conversation, $normalizedText, $context),
            default          => $this->buildInitialMenuResponse($this->registry->definitionForCompany($companyEntity)),
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function startCancellation(?Company $company, Conversation $conversation): array
    {
        return $this->cancellationHandler->startCancellation($company, $conversation);
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
        return $this->cancellationHandler->handleCancellation($company, $conversation, $step, $normalizedText);
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

        $serviceOptions = $this->messageBuilder->enumerateServices($services);
        if (! isset($serviceOptions[$normalizedText])) {
            $serviceText = $this->messageBuilder->appointmentServiceMenuText($serviceOptions, true);

            return $this->botStateResult(
                $serviceText,
                [
                    'flow'    => BotFlow::APPOINTMENTS->value,
                    'step'    => 'service_select',
                    'context' => ['appointment' => array_merge($context, ['service_options' => $serviceOptions])],
                ],
                $this->messageBuilder->buildAppointmentServiceListMessage($serviceOptions, $serviceText)
            );
        }

        $selectedService         = $serviceOptions[$normalizedText];
        $context['service_id']   = (int) $selectedService['id'];
        $context['service_name'] = (string) $selectedService['name'];
        unset($context['selected_date'], $context['slot_page'], $context['slot_starts_at'], $context['slot_ends_at']);

        $settings         = $this->appointmentSettings($company);
        $staffProfiles    = $this->activeAppointmentStaff($company);
        $allowChooseStaff = (bool) ($settings?->allow_customer_choose_staff ?? true);
        $hasMoreThanOne   = count($staffProfiles) > 1;

        if (! $allowChooseStaff || ! $hasMoreThanOne) {
            $this->setSingleStaffContext($context, $staffProfiles);
            $context['week_start'] = $this->currentWeekStart($settings?->timezone)->toDateString();

            return $this->replyWithAppointmentDayMenu($company, $context);
        }

        $context['staff_options'] = $this->messageBuilder->enumerateStaff($staffProfiles);
        $staffText = $this->messageBuilder->appointmentStaffMenuText($context['staff_options']);

        return $this->botStateResult(
            $staffText,
            [
                'flow'    => BotFlow::APPOINTMENTS->value,
                'step'    => 'staff_select',
                'context' => ['appointment' => $context],
            ],
            $this->messageBuilder->buildAppointmentStaffListMessage($context['staff_options'], $staffText)
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
            : $this->messageBuilder->enumerateStaff($this->activeAppointmentStaff($company));

        if ($normalizedText === '8') {
            $context['staff_profile_id'] = null;
            $context['staff_name']       = null;
            unset($context['last_day_key'], $context['last_day_date'], $context['selected_date'], $context['slot_page']);

            return $this->replyWithAppointmentDayMenu($company, $context);
        }

        if (! isset($staffOptions[$normalizedText])) {
            $staffText = $this->messageBuilder->appointmentStaffMenuText($staffOptions, true);

            return $this->botStateResult(
                $staffText,
                [
                    'flow'    => BotFlow::APPOINTMENTS->value,
                    'step'    => 'staff_select',
                    'context' => ['appointment' => array_merge($context, ['staff_options' => $staffOptions])],
                ],
                $this->messageBuilder->buildAppointmentStaffListMessage($staffOptions, $staffText)
            );
        }

        $selectedStaff               = $staffOptions[$normalizedText];
        $context['staff_profile_id'] = (int) $selectedStaff['id'];
        $context['staff_name']       = (string) $selectedStaff['name'];
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
            $context['staff_options'] = $this->messageBuilder->enumerateStaff($staffProfiles);
            unset($context['last_day_key'], $context['last_day_date'], $context['selected_date'], $context['slot_page']);
            $staffText = $this->messageBuilder->appointmentStaffMenuText($context['staff_options']);

            return $this->botStateResult(
                $staffText,
                [
                    'flow'    => BotFlow::APPOINTMENTS->value,
                    'step'    => 'staff_select',
                    'context' => ['appointment' => $context],
                ],
                $this->messageBuilder->buildAppointmentStaffListMessage($context['staff_options'], $staffText)
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
                $noSlotMsg .= "\n\n" . $this->messageBuilder->appointmentDayPromptText($context);

                return $this->botStateResult($noSlotMsg, [
                    'flow'    => BotFlow::APPOINTMENTS->value,
                    'step'    => 'day_select',
                    'context' => ['appointment' => $context],
                ], $this->messageBuilder->buildAppointmentDayListMessage($context, $noSlotMsg));
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
            $maxDaysText = "Não é possível agendar além de {$maxDays} dias. Escolha um dia mais próximo.\n\n" .
                $this->messageBuilder->appointmentDayPromptText($context);

            return $this->botStateResult(
                $maxDaysText,
                [
                    'flow'    => BotFlow::APPOINTMENTS->value,
                    'step'    => 'day_select',
                    'context' => ['appointment' => $context],
                ],
                $this->messageBuilder->buildAppointmentDayListMessage($context, $maxDaysText)
            );
        }

        $context['last_day_key']  = $parsed['key'];
        $context['last_day_date'] = $selectedDate;

        $selectedDt = CarbonImmutable::parse($selectedDate, $timezone);
        $slots      = $this->appointmentSlotsForDate($company, $context, $selectedDate);

        if ($slots === []) {
            $noSlotMsg  = "Não há horários disponíveis em " . $selectedDt->translatedFormat('D d/m') . ".";
            $noSlotMsg .= "\n\n" . $this->messageBuilder->appointmentDayPromptText($context);

            return $this->botStateResult(
                $noSlotMsg,
                [
                    'flow'    => BotFlow::APPOINTMENTS->value,
                    'step'    => 'day_select',
                    'context' => ['appointment' => $context],
                ],
                $this->messageBuilder->buildAppointmentDayListMessage($context, $noSlotMsg)
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

        $selectedSlot                = $slotOptions[$normalizedText];
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
            $nearestInvalidText = "Opção inválida. Escolha um número da lista... ou \"menu\" para voltar ao menu principal.\n\n" .
                $this->messageBuilder->appointmentNearestSlotsText($slots, $timezone, $hasStaffChoice);

            return $this->botStateResult(
                $nearestInvalidText,
                [
                    'flow'    => BotFlow::APPOINTMENTS->value,
                    'step'    => 'nearest_select',
                    'context' => ['appointment' => $context],
                ],
                $this->messageBuilder->buildAppointmentNearestSlotsListMessage($slots, $timezone, $nearestInvalidText)
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

        $confirmText = $this->messageBuilder->appointmentConfirmText($context, $timezone);

        return $this->botStateResult(
            $confirmText,
            [
                'flow'    => BotFlow::APPOINTMENTS->value,
                'step'    => 'confirm',
                'context' => ['appointment' => $context],
            ],
            $this->messageBuilder->buildAppointmentConfirmButtonMessage($confirmText)
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
            $confirmText = "Opção inválida. Responda com 1, 2 ou 9... ou \"menu\" para voltar ao menu principal.\n\n" .
                $this->messageBuilder->appointmentConfirmText($context, $timezone);

            return $this->botStateResult(
                $confirmText,
                [
                    'flow'    => BotFlow::APPOINTMENTS->value,
                    'step'    => 'confirm',
                    'context' => ['appointment' => $context],
                ],
                $this->messageBuilder->buildAppointmentConfirmButtonMessage($confirmText)
            );
        }

        return $this->botStateResult(
            'Perfeito. Agora me informe o nome do cliente que vai ser atendido no agendamento.',
            [
                'flow'    => BotFlow::APPOINTMENTS->value,
                'step'    => 'collect_customer_name',
                'context' => ['appointment' => $context],
            ]
        );
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function handleAppointmentCustomerNameCollection(
        ?Company $company,
        Conversation $conversation,
        string $normalizedText,
        array $context
    ): array {
        $companyEntity = $this->resolveCompany($company, $conversation);
        $customerName  = trim($normalizedText);

        if (mb_strlen($customerName) < 2) {
            return $this->botStateResult(
                'Nome invalido. Informe o nome do cliente para concluir o agendamento.',
                [
                    'flow'    => BotFlow::APPOINTMENTS->value,
                    'step'    => 'collect_customer_name',
                    'context' => ['appointment' => $context],
                ]
            );
        }

        $context['customer_name'] = $customerName;

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
                    'customer_name'    => (string) ($context['customer_name'] ?? $conversation->customer_name),
                    'customer_phone'   => $conversation->customer_phone,
                    'customer_email'   => $this->nullableContextEmail($context['customer_email'] ?? null),
                    'source'           => 'whatsapp',
                    'meta'             => ['bot_flow' => 'stateful_appointments'],
                ],
                null
            );
        } catch (ValidationException $exception) {
            $message        = collect($exception->errors())->flatten()->first() ?: 'Não consegui confirmar esse horário.';
            $validationText = "{$message}\n\n" . $this->messageBuilder->appointmentDayPromptText($context);

            return $this->botStateResult(
                $validationText,
                [
                    'flow'    => BotFlow::APPOINTMENTS->value,
                    'step'    => 'day_select',
                    'context' => ['appointment' => $context],
                ],
                $this->messageBuilder->buildAppointmentDayListMessage($context, $validationText)
            );
        }

        $timezone     = $this->appointmentSettings($companyEntity)?->timezone ?: 'America/Sao_Paulo';
        $startsAt     = $appointment->starts_at?->setTimezone($timezone);
        $dayName      = $startsAt?->translatedFormat('l') ?? '';
        $startsAtDate = $startsAt?->format('d/m/Y') ?? '';
        $startsAtTime = $startsAt?->format('H:i') ?? '';
        $serviceName  = (string) ($context['service_name'] ?? 'serviço');
        $staffName    = trim((string) ($context['staff_name'] ?? ''));
        $staffPart    = $staffName !== '' ? "\nAtendente: {$staffName}" : '';
        $replyText    = "✅ Agendamento confirmado!\n\nData: {$dayName}, {$startsAtDate}\nHorário: {$startsAtTime}\nServiço: {$serviceName}{$staffPart}\n\nAté lá! 😊";

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
        $parts[] = $this->messageBuilder->appointmentDayPromptText($context);
        $text    = implode("\n\n", $parts);

        return $this->botStateResult(
            $text,
            [
                'flow'    => BotFlow::APPOINTMENTS->value,
                'step'    => 'day_select',
                'context' => ['appointment' => $context],
            ],
            $this->messageBuilder->buildAppointmentDayListMessage($context, $text)
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
        $slotMenuText         = $prefix . $this->messageBuilder->appointmentSlotMenuText($selectedDate, $pageSlots, $hasMore, $timezone, $hasStaffChoice);

        return $this->botStateResult(
            $slotMenuText,
            [
                'flow'    => BotFlow::APPOINTMENTS->value,
                'step'    => 'slot_select',
                'context' => ['appointment' => $context],
            ],
            $this->messageBuilder->buildAppointmentSlotListMessage($pageSlots, $hasMore, $timezone, $hasStaffChoice, $slotMenuText)
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
        $nearestText              = $this->messageBuilder->appointmentNearestSlotsText($slots, $timezone, $hasStaffChoice);

        return $this->botStateResult(
            $nearestText,
            [
                'flow'    => BotFlow::APPOINTMENTS->value,
                'step'    => 'nearest_select',
                'context' => ['appointment' => $context],
            ],
            $this->messageBuilder->buildAppointmentNearestSlotsListMessage($slots, $timezone, $nearestText)
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
                    'starts_at'        => (string) ($slot['starts_at'] ?? ''),
                    'ends_at'          => (string) ($slot['ends_at'] ?? ''),
                    'starts_at_local'  => (string) ($slot['starts_at_local'] ?? ''),
                    'ends_at_local'    => (string) ($slot['ends_at_local'] ?? ''),
                    'staff_profile_id' => $staffProfileId,
                    'staff_name'       => $staffName,
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
                'timezone'                        => 'America/Sao_Paulo',
                'slot_interval_minutes'           => 15,
                'booking_min_notice_minutes'      => 120,
                'booking_max_advance_days'        => 30,
                'cancellation_min_notice_minutes' => 120,
                'reschedule_min_notice_minutes'   => 120,
                'allow_customer_choose_staff'     => true,
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

    /**
     * @param array<string, mixed> $context
     * @param array<int, array{id:int,name:string}> $staffProfiles
     */
    private function setSingleStaffContext(array &$context, array $staffProfiles): void
    {
        $staffProfile                = count($staffProfiles) === 1 ? $staffProfiles[0] : null;
        $context['staff_profile_id'] = $staffProfile !== null ? (int) $staffProfile['id'] : null;
        $context['staff_name']       = $staffProfile !== null ? (string) $staffProfile['name'] : null;
    }
}
