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

    private const MAX_ASSIST_ATTEMPTS = 3;

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
        $timezone         = (string) ($settings?->timezone ?: 'America/Sao_Paulo');
        $initialMessage   = trim((string) ($action['initial_message_text'] ?? ''));
        $initialHasDay    = $initialMessage !== ''
            && $this->parseFlexibleDayInput($initialMessage, $timezone) !== null;

        $context['has_staff_choice'] = $allowChooseStaff && $hasMoreThanOne;

        if ($initialHasDay) {
            if (! $allowChooseStaff || ! $hasMoreThanOne) {
                $this->setSingleStaffContext($context, $staffProfiles);
            } else {
                $context['staff_profile_id'] = null;
                $context['staff_name']       = null;
            }

            return $this->handleAppointmentDaySelection($companyEntity, $conversation, $initialMessage, $context);
        }

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

        if ($normalizedText === '9' || ($this->appointmentStepAllowsTextHandoff($step) && $this->isAttendantRequest($normalizedText))) {
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

        $serviceOptions     = $this->messageBuilder->enumerateServices($services);
        $resolvedServiceKey = $this->resolveNamedOptionKey($normalizedText, $serviceOptions);
        if ($resolvedServiceKey === null) {
            $context = $this->trackInvalidAttempt($context, 'service_select');
            if ($this->shouldHandoffAfterAssistAttempts($context)) {
                return $this->handoffAfterAssistLimit($company, $conversation, $context);
            }

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

        $context                 = $this->resetAssistAttempt($context);
        $selectedService         = $serviceOptions[$resolvedServiceKey];
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

        if ($normalizedText === '8' || $this->isAnyStaffInput($normalizedText)) {
            $context = $this->resetAssistAttempt($context);
            $context['staff_profile_id'] = null;
            $context['staff_name']       = null;
            unset($context['last_day_key'], $context['last_day_date'], $context['selected_date'], $context['slot_page']);

            return $this->replyWithAppointmentDayMenu($company, $context);
        }

        $resolvedStaffKey = $this->resolveNamedOptionKey($normalizedText, $staffOptions);
        if ($resolvedStaffKey === null) {
            $timezone = (string) ($this->appointmentSettings($company)?->timezone ?: 'America/Sao_Paulo');
            if ($this->parseFlexibleDayInput($normalizedText, $timezone) !== null) {
                $context = $this->resetAssistAttempt($context);
                $context['staff_profile_id'] = null;
                $context['staff_name']       = null;
                unset($context['last_day_key'], $context['last_day_date'], $context['selected_date'], $context['slot_page']);

                return $this->handleAppointmentDaySelection($company, $conversation, $normalizedText, $context);
            }

            $context = $this->trackInvalidAttempt($context, 'staff_select');
            if ($this->shouldHandoffAfterAssistAttempts($context)) {
                return $this->handoffAfterAssistLimit($company, $conversation, $context);
            }

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

        $context                     = $this->resetAssistAttempt($context);
        $selectedStaff               = $staffOptions[$resolvedStaffKey];
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
            $context = $this->resetAssistAttempt($context);
            $context['selected_date'] = $selectedDate;
            $context['slot_page']     = 0;

            return $this->replyWithAppointmentSlotMenu($company, $context);
        }

        if ($normalizedText === '8') {
            return $this->replyWithNearestSlots($company, $context);
        }

        $preferredTime = $this->parseTimeInput($normalizedText);
        $requestedPeriod = $this->parseRequestedSlotPeriod($normalizedText);
        $context = $this->applyRequestedSlotPeriod($context, $requestedPeriod);
        $parsed        = $this->parseFlexibleDayInput($normalizedText, $timezone);
        if ($parsed === null) {
            $context = $this->trackInvalidAttempt($context, 'day_select');
            if ($this->shouldHandoffAfterAssistAttempts($context)) {
                return $this->handoffAfterAssistLimit($company, $conversation, $context);
            }

            return $this->replyWithAppointmentDayMenu($company, $context, true, '');
        }

        $fromDate     = $parsed['type'] === 'weekday'
            ? CarbonImmutable::now($timezone)->startOfDay()->toDateString()
            : $parsed['date'];
        $selectedDate = $this->nextOccurrenceOfDay($parsed['day_of_week'], $fromDate, $timezone);
        $maxDate      = CarbonImmutable::now($timezone)->addDays($maxDays)->toDateString();

        if ($selectedDate > $maxDate) {
            $context = $this->trackInvalidAttempt($context, 'day_select');
            if ($this->shouldHandoffAfterAssistAttempts($context)) {
                return $this->handoffAfterAssistLimit($company, $conversation, $context);
            }

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

        $context = $this->resetAssistAttempt($context);
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

        if ($preferredTime !== null) {
            $selectedSlot = $this->findSlotByTime($slots, $preferredTime, $timezone);
            if ($selectedSlot !== null) {
                return $this->continueWithSelectedAppointmentSlot($context, $selectedSlot);
            }

            return $this->replyWithAppointmentSlotMenu(
                $company,
                $context,
                false,
                "Entendi o dia, mas nao encontrei horario disponivel as {$preferredTime}. Escolha um dos horarios abaixo."
            );
        }

        return $this->replyWithAppointmentSlotMenu(
            $company,
            $context,
            false,
            $this->buildAppointmentSlotIntro($parsed, $requestedPeriod)
        );
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
                    $context = $this->resetAssistAttempt($context);
                    $context['selected_date'] = $nextWeek;
                    $context['last_day_date'] = $nextWeek;
                    $context['slot_page']     = 0;

                    return $this->replyWithAppointmentSlotMenu($company, $context);
                }
            }

            $context = $this->trackInvalidAttempt($context, 'slot_select');
            if ($this->shouldHandoffAfterAssistAttempts($context)) {
                return $this->handoffAfterAssistLimit($company, $conversation, $context);
            }

            return $this->replyWithAppointmentSlotMenu($company, $context, true);
        }

        if ($normalizedText === '8') {
            $context = $this->resetAssistAttempt($context);
            $context['slot_page'] = (int) ($context['slot_page'] ?? 0) + 1;

            return $this->replyWithAppointmentSlotMenu($company, $context);
        }

        $selectedDate = trim((string) ($context['selected_date'] ?? ''));
        if ($selectedDate === '') {
            return $this->replyWithAppointmentDayMenu($company, $context);
        }

        $settings = $this->appointmentSettings($company);
        $timezone = (string) ($settings?->timezone ?: 'America/Sao_Paulo');
        $slots = $this->appointmentSelectableSlotsForDate($company, $context, $selectedDate, $timezone);
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

        $selectedSlot = $slotOptions[$normalizedText] ?? null;
        if ($selectedSlot === null) {
            $preferredTime = $this->parseTimeInput($normalizedText);
            if ($preferredTime !== null) {
                $selectedSlot = $this->findSlotByTime($slots, $preferredTime, $timezone);
                if ($selectedSlot === null) {
                    $context = $this->trackInvalidAttempt($context, 'slot_select');
                    if ($this->shouldHandoffAfterAssistAttempts($context)) {
                        return $this->handoffAfterAssistLimit($company, $conversation, $context);
                    }

                    return $this->replyWithAppointmentSlotMenu(
                        $company,
                        $context,
                        false,
                        "Nao encontrei horario disponivel as {$preferredTime}. Escolha um numero da lista."
                    );
                }
            }
        }

        if ($selectedSlot === null) {
            $context = $this->trackInvalidAttempt($context, 'slot_select');
            if ($this->shouldHandoffAfterAssistAttempts($context)) {
                return $this->handoffAfterAssistLimit($company, $conversation, $context);
            }

            return $this->replyWithAppointmentSlotMenu($company, $context, true);
        }

        return $this->continueWithSelectedAppointmentSlot($context, $selectedSlot);
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

        $timezone     = (string) ($this->appointmentSettings($company)?->timezone ?: 'America/Sao_Paulo');
        $selectedSlot = $slotOptions[$normalizedText] ?? null;
        if ($selectedSlot === null) {
            $preferredTime = $this->parseTimeInput($normalizedText);
            if ($preferredTime !== null) {
                $selectedSlot = $this->findSlotByTime($slots, $preferredTime, $timezone);
            }
        }

        if ($selectedSlot === null) {
            $context = $this->trackInvalidAttempt($context, 'nearest_select');
            if ($this->shouldHandoffAfterAssistAttempts($context)) {
                return $this->handoffAfterAssistLimit($company, $conversation, $context);
            }

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

        return $this->continueWithSelectedAppointmentSlot($context, $selectedSlot);
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

        $email = $this->extractEmailInput($normalizedText);
        if ($email !== null) {
            $context['customer_email'] = $email;
        } elseif (! $this->isSkipEmailInput($normalizedText)) {
            $context = $this->trackInvalidAttempt($context, 'collect_email');
            if ($this->shouldHandoffAfterAssistAttempts($context)) {
                return $this->handoffAfterAssistLimit($company, $conversation, $context);
            }

            return $this->botStateResult(
                "E-mail invalido. Informe um e-mail valido ou responda *pular* para continuar sem.",
                [
                    'flow'    => BotFlow::APPOINTMENTS->value,
                    'step'    => 'collect_email',
                    'context' => ['appointment' => $context],
                ]
            );
        }

        $context     = $this->resetAssistAttempt($context);
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
        $confirmationChoice = $this->resolveConfirmationChoice($normalizedText);
        if ($confirmationChoice === 'reschedule') {
            $context = $this->resetAssistAttempt($context);
            return $this->replyWithAppointmentSlotMenu($companyEntity, $context);
        }

        $timezone = $this->appointmentSettings($companyEntity)?->timezone ?: 'America/Sao_Paulo';

        if ($confirmationChoice !== 'confirm') {
            $context = $this->trackInvalidAttempt($context, 'confirm');
            if ($this->shouldHandoffAfterAssistAttempts($context)) {
                return $this->handoffAfterAssistLimit($companyEntity, $conversation, $context);
            }

            $confirmText = "Opcao invalida. Responda com 1 para confirmar, 2 para escolher outro horario ou 9 para falar com atendente.\n\n" .
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

        $context = $this->resetAssistAttempt($context);
        if (trim((string) ($context['customer_name'] ?? '')) === '') {
            $conversationName = trim((string) ($conversation->customer_name ?? ''));
            if ($conversationName !== '') {
                $context['customer_name'] = $conversationName;
            }
        }

        return $this->confirmAppointment($companyEntity, $conversation, $context);
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
            $context = $this->trackInvalidAttempt($context, 'collect_customer_name');
            if ($this->shouldHandoffAfterAssistAttempts($context)) {
                return $this->handoffAfterAssistLimit($companyEntity, $conversation, $context);
            }

            return $this->botStateResult(
                'Nome inválido. Informe o nome do cliente para concluir o agendamento.',
                [
                    'flow'    => BotFlow::APPOINTMENTS->value,
                    'step'    => 'collect_customer_name',
                    'context' => ['appointment' => $context],
                ]
            );
        }

        $context['customer_name'] = $customerName;
        $context = $this->resetAssistAttempt($context);

        return $this->confirmAppointment($companyEntity, $conversation, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function confirmAppointment(
        ?Company $companyEntity,
        Conversation $conversation,
        array $context
    ): array {
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
        bool $invalidOption = false,
        string $extraMessage = ''
    ): array {
        $selectedDate = trim((string) ($context['selected_date'] ?? ''));
        if ($selectedDate === '') {
            return $this->replyWithAppointmentDayMenu($company, $context);
        }

        $timezone = (string) ($this->appointmentSettings($company)?->timezone ?: 'America/Sao_Paulo');
        $rawSlots = $this->appointmentSlotsForDate($company, $context, $selectedDate);
        if ($rawSlots === []) {
            return $this->replyWithAppointmentDayMenu($company, $context);
        }

        [$slots, $context, $periodFallbackMessage] = $this->filterSlotsForCurrentPeriod($rawSlots, $context, $timezone);
        if ($periodFallbackMessage !== '') {
            $extraMessage = $extraMessage !== ''
                ? "{$extraMessage}\n\n{$periodFallbackMessage}"
                : $periodFallbackMessage;
        }

        $page   = max(0, (int) ($context['slot_page'] ?? 0));
        $offset = $page * 6;
        if ($offset >= count($slots)) {
            $page   = 0;
            $offset = 0;
        }

        $context['slot_page'] = $page;
        $pageSlots            = array_slice($slots, $offset, 6);
        $parts                = [];
        if ($invalidOption) {
            $parts[] = "Opcao invalida. Escolha um numero da lista... ou \"menu\" para voltar ao menu principal.";
        }
        if ($extraMessage !== '') {
            $parts[] = $extraMessage;
        }
        $hasStaffChoice       = (bool) ($context['has_staff_choice'] ?? false);
        $hasMore              = ($offset + 6) < count($slots);
        $parts[]              = $this->messageBuilder->appointmentSlotMenuText($selectedDate, $pageSlots, $hasMore, $timezone, $hasStaffChoice);
        $slotMenuText         = implode("\n\n", $parts);

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

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $selectedSlot
     * @return array<string, mixed>
     */
    private function continueWithSelectedAppointmentSlot(array $context, array $selectedSlot): array
    {
        $context = $this->resetAssistAttempt($context);

        $selectedDate = trim((string) ($selectedSlot['date'] ?? ''));
        if ($selectedDate === '') {
            $selectedDate = trim((string) ($context['selected_date'] ?? ''));
        }
        if ($selectedDate === '') {
            $selectedDate = $this->slotStartDate($selectedSlot, 'America/Sao_Paulo') ?? '';
        }

        if ($selectedDate !== '') {
            $context['selected_date'] = $selectedDate;
        }

        $context['slot_starts_at']   = (string) ($selectedSlot['starts_at_local'] ?? $selectedSlot['starts_at'] ?? '');
        $context['slot_ends_at']     = (string) ($selectedSlot['ends_at_local'] ?? $selectedSlot['ends_at'] ?? '');
        $context['staff_profile_id'] = (int) ($selectedSlot['staff_profile_id'] ?? ($context['staff_profile_id'] ?? 0));
        $context['staff_name']       = (string) ($selectedSlot['staff_name'] ?? ($context['staff_name'] ?? ''));
        unset($context['nearest_slots']);

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
     * @param  array<int, array<string, mixed>>  $slots
     * @return array<string, mixed>|null
     */
    private function findSlotByTime(array $slots, string $preferredTime, string $timezone): ?array
    {
        foreach ($slots as $slot) {
            if (! is_array($slot)) {
                continue;
            }

            if ($this->slotStartTime($slot, $timezone) === $preferredTime) {
                return $slot;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $slot */
    private function slotStartTime(array $slot, string $timezone): ?string
    {
        $candidate = trim((string) ($slot['starts_at_local'] ?? $slot['starts_at'] ?? ''));
        if ($candidate === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($candidate, $timezone)->setTimezone($timezone)->format('H:i');
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param array<string, mixed> $slot */
    private function slotStartDate(array $slot, string $timezone): ?string
    {
        $candidate = trim((string) ($slot['starts_at_local'] ?? $slot['starts_at'] ?? ''));
        if ($candidate === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($candidate, $timezone)->setTimezone($timezone)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, array{id:int,name:string}>  $options
     */
    private function resolveNamedOptionKey(string $input, array $options): ?string
    {
        $trimmed = trim($input);
        if ($trimmed === '') {
            return null;
        }

        foreach ($options as $key => $option) {
            if ((string) $key === $trimmed) {
                return (string) $key;
            }
        }

        $inputSlug   = $this->slugifyLabel($trimmed);
        $inputLookup = $this->normalizeLookupText($trimmed);

        foreach ($options as $key => $option) {
            $name = trim((string) ($option['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $optionSlug = $this->slugifyLabel($name);
            if ($optionSlug !== '' && $optionSlug === $inputSlug) {
                return (string) $key;
            }

            $optionLookup = $this->normalizeLookupText($name);
            if ($optionLookup === '') {
                continue;
            }

            if (
                $optionLookup === $inputLookup
                || (mb_strlen($optionLookup) >= 3 && str_contains($inputLookup, $optionLookup))
                || (mb_strlen($inputLookup) >= 3 && str_contains($optionLookup, $inputLookup))
            ) {
                return (string) $key;
            }
        }

        return null;
    }

    /**
     * @return array{type:string,day_of_week:int,date:string,key:string}|null
     */
    private function parseFlexibleDayInput(string $text, string $timezone): ?array
    {
        $withoutTime = trim($this->removeTimeFromInput($text));
        $direct      = $this->parseDayInput($withoutTime, $timezone);
        if ($direct !== null) {
            return $direct;
        }

        if (preg_match('/\b(\d{1,2}\/\d{1,2}(?:\/\d{2,4})?)\b/u', $withoutTime, $matches)) {
            return $this->parseDayInput((string) $matches[1], $timezone);
        }

        $lookup = $this->normalizeLookupText($withoutTime);
        if ($lookup === '') {
            return null;
        }

        $aliases = [
            'segunda feira' => 'segunda',
            'terca feira' => 'terca',
            'quarta feira' => 'quarta',
            'quinta feira' => 'quinta',
            'sexta feira' => 'sexta',
            'domingo' => 'domingo',
            'segunda' => 'segunda',
            'terca' => 'terca',
            'quarta' => 'quarta',
            'quinta' => 'quinta',
            'sexta' => 'sexta',
            'sabado' => 'sabado',
            'amanha' => 'amanha',
            'hoje' => 'hoje',
            'dom' => 'dom',
            'seg' => 'seg',
            'ter' => 'ter',
            'qua' => 'qua',
            'qui' => 'qui',
            'sex' => 'sex',
            'sab' => 'sab',
        ];

        foreach ($aliases as $alias => $canonical) {
            if (preg_match('/(^| )' . preg_quote($alias, '/') . '($| )/', $lookup) === 1) {
                return $this->parseDayInput($canonical, $timezone);
            }
        }

        return null;
    }

    private function parseTimeInput(string $text): ?string
    {
        $normalized = $this->normalizeText($text);
        if (
            preg_match(
                '/\b([01]?\d|2[0-3])\s*(?:horas?|hrs?|h|:)\s*([0-5]\d)?\b/u',
                $normalized,
                $matches
            ) !== 1
        ) {
            return null;
        }

        $hour   = (int) $matches[1];
        $minute = isset($matches[2]) && $matches[2] !== '' ? (int) $matches[2] : 0;

        return sprintf('%02d:%02d', $hour, $minute);
    }

    private function removeTimeFromInput(string $text): string
    {
        $withoutTime = preg_replace(
            '/\b([01]?\d|2[0-3])\s*(?:horas?|hrs?|h|:)\s*([0-5]\d)?\b/iu',
            ' ',
            $text
        ) ?? $text;

        return trim((string) preg_replace('/\s+/', ' ', $withoutTime));
    }

    private function parseRequestedSlotPeriod(string $text): ?string
    {
        $normalized = $this->normalizeLookupText($text);
        if ($normalized === '') {
            return null;
        }

        if (
            preg_match('/(^| )(de |da |pela |a )?manha($| )/', $normalized) === 1
            || str_contains($normalized, 'matutino')
        ) {
            return 'morning';
        }

        if (
            preg_match('/(^| )(de |da |pela |a )?tarde($| )/', $normalized) === 1
            || str_contains($normalized, 'vespertino')
        ) {
            return 'afternoon';
        }

        if (
            preg_match('/(^| )(de |da |pela |a )?noite($| )/', $normalized) === 1
            || str_contains($normalized, 'noturno')
        ) {
            return 'night';
        }

        return null;
    }

    /** @param array<string, mixed> $context */
    private function applyRequestedSlotPeriod(array $context, ?string $period): array
    {
        if ($period === null) {
            unset($context['slot_period'], $context['slot_period_label']);

            return $context;
        }

        $context['slot_period'] = $period;
        $context['slot_period_label'] = $this->slotPeriodPhrase($period);

        return $context;
    }

    /**
     * @param array<int, array<string, mixed>> $slots
     * @param array<string, mixed> $context
     * @return array{0: array<int, array<string, mixed>>, 1: array<string, mixed>, 2: string}
     */
    private function filterSlotsForCurrentPeriod(array $slots, array $context, string $timezone): array
    {
        $period = $this->currentSlotPeriod($context);
        if ($period === null) {
            return [$slots, $context, ''];
        }

        $filtered = array_values(array_filter(
            $slots,
            fn (array $slot): bool => $this->slotMatchesPeriod($slot, $period, $timezone)
        ));

        if ($filtered !== []) {
            return [$filtered, $context, ''];
        }

        unset($context['slot_period'], $context['slot_period_label']);

        return [
            $slots,
            $context,
            'Nao encontrei horarios ' . $this->slotPeriodFallbackPhrase($period) .
                '. Vou mostrar todos os horarios disponiveis para esse dia.',
        ];
    }

    /**
     * @param array<string, mixed> $context
     * @return array<int, array<string, mixed>>
     */
    private function appointmentSelectableSlotsForDate(
        ?Company $company,
        array $context,
        string $date,
        string $timezone
    ): array {
        $slots = $this->appointmentSlotsForDate($company, $context, $date);
        [$filtered] = $this->filterSlotsForCurrentPeriod($slots, $context, $timezone);

        return $filtered;
    }

    /** @param array<string, mixed> $slot */
    private function slotMatchesPeriod(array $slot, string $period, string $timezone): bool
    {
        $time = $this->slotStartTime($slot, $timezone);
        if ($time === null) {
            return false;
        }

        [$hour, $minute] = array_map('intval', explode(':', $time));
        $minutes = ($hour * 60) + $minute;

        return match ($period) {
            'morning' => $minutes < 12 * 60,
            'afternoon' => $minutes >= 12 * 60 && $minutes < 18 * 60,
            'night' => $minutes >= 18 * 60,
            default => true,
        };
    }

    /** @param array<string, mixed> $context */
    private function currentSlotPeriod(array $context): ?string
    {
        $period = trim((string) ($context['slot_period'] ?? ''));

        return in_array($period, ['morning', 'afternoon', 'night'], true) ? $period : null;
    }

    private function slotPeriodPhrase(string $period): string
    {
        return match ($period) {
            'morning' => 'de manha',
            'afternoon' => 'a tarde',
            'night' => 'a noite',
            default => '',
        };
    }

    private function slotPeriodFallbackPhrase(string $period): string
    {
        return match ($period) {
            'morning' => 'no periodo da manha',
            'afternoon' => 'no periodo da tarde',
            'night' => 'no periodo da noite',
            default => 'nesse periodo',
        };
    }

    /**
     * @param array{type:string,day_of_week:int,date:string,key:string} $parsed
     */
    private function buildAppointmentSlotIntro(array $parsed, ?string $period): string
    {
        $dayReference = $this->appointmentDayReferenceText($parsed);
        $periodText = $period !== null ? ' ' . $this->slotPeriodPhrase($period) : '';

        return "Vou te passar os horarios {$dayReference}{$periodText} disponiveis:";
    }

    /**
     * @param array{type:string,day_of_week:int,date:string,key:string} $parsed
     */
    private function appointmentDayReferenceText(array $parsed): string
    {
        $key = trim((string) ($parsed['key'] ?? ''));
        if ($key === 'hoje') {
            return 'de hoje';
        }

        if ($key === 'amanha') {
            return 'de amanha';
        }

        $date = trim((string) ($parsed['date'] ?? ''));
        if ($date !== '') {
            try {
                return 'de ' . CarbonImmutable::parse($date)->format('d/m');
            } catch (\Throwable) {
                // Fall through to the key-based label.
            }
        }

        return $key !== '' ? 'de ' . str_replace('-', ' ', $key) : 'desse dia';
    }

    private function extractEmailInput(string $input): ?string
    {
        $trimmed = trim($input);
        if ($trimmed !== '' && filter_var($trimmed, FILTER_VALIDATE_EMAIL)) {
            return $trimmed;
        }

        if (preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $input, $matches) !== 1) {
            return null;
        }

        $email = (string) $matches[0];

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    private function isSkipEmailInput(string $input): bool
    {
        $normalized = $this->normalizeLookupText($input);

        return in_array($normalized, ['pular', 'skip', 'nao', 'n', 'sem email'], true)
            || str_contains($normalized, 'sem email')
            || str_contains($normalized, 'nao tenho email')
            || str_contains($normalized, 'nao possuo email')
            || str_contains($normalized, 'continuar sem');
    }

    private function resolveConfirmationChoice(string $input): ?string
    {
        $normalized = $this->normalizeLookupText($input);
        if (in_array($normalized, ['2', 'nao', 'n', 'outro horario', 'trocar horario', 'escolher outro horario'], true)) {
            return 'reschedule';
        }

        if (
            str_contains($normalized, 'outro horario')
            || str_contains($normalized, 'trocar')
            || str_contains($normalized, 'escolher outro')
            || str_contains($normalized, 'mudar horario')
            || str_contains($normalized, 'remarcar')
            || preg_match('/(^| )(nao|n)($| )/', $normalized) === 1
        ) {
            return 'reschedule';
        }

        if (in_array($normalized, [
            '1',
            'sim',
            's',
            'confirmar',
            'confirmo',
            'ok',
            'certo',
            'isso',
            'isso mesmo',
            'perfeito',
            'correto',
            'fechado',
            'combinado',
            'pode ser',
            'ta certo',
            'esta certo',
            'tudo certo',
            'pode confirmar',
        ], true)) {
            return 'confirm';
        }

        if (str_contains($normalized, 'confirm') || preg_match('/(^| )(sim|ok)($| )/', $normalized) === 1) {
            return 'confirm';
        }

        foreach ([
            'isso mesmo',
            'pode confirmar',
            'pode finalizar',
            'ta certo',
            'esta certo',
            'tudo certo',
            'esta correto',
            'correto',
            'perfeito',
            'fechado',
            'combinado',
            'pode ser',
        ] as $confirmationPhrase) {
            if (str_contains($normalized, $confirmationPhrase)) {
                return 'confirm';
            }
        }

        if (preg_match('/(^| )(certo|isso|perfeito|correto|fechado|combinado)($| )/', $normalized) === 1) {
            return 'confirm';
        }

        return null;
    }

    private function isAnyStaffInput(string $input): bool
    {
        $normalized = $this->normalizeLookupText($input);

        return $normalized === '8'
            || str_contains($normalized, 'qualquer')
            || str_contains($normalized, 'tanto faz')
            || str_contains($normalized, 'disponivel');
    }

    private function appointmentStepAllowsTextHandoff(string $step): bool
    {
        return in_array($step, ['service_select', 'staff_select', 'day_select', 'slot_select', 'nearest_select', 'confirm'], true);
    }

    private function isAttendantRequest(string $input): bool
    {
        $normalized = $this->normalizeLookupText($input);

        return str_contains($normalized, 'falar com atendente')
            || str_contains($normalized, 'quero atendente')
            || str_contains($normalized, 'chamar atendente')
            || str_contains($normalized, 'atendimento humano')
            || str_contains($normalized, 'falar com humano')
            || str_contains($normalized, 'pessoa real');
    }

    /** @param array<string, mixed> $context */
    private function trackInvalidAttempt(array $context, string $step): array
    {
        $current = is_array($context['assist_attempt'] ?? null) ? $context['assist_attempt'] : [];
        $count   = (string) ($current['step'] ?? '') === $step
            ? max(0, (int) ($current['count'] ?? 0)) + 1
            : 1;

        $context['assist_attempt'] = [
            'step' => $step,
            'count' => $count,
        ];

        return $context;
    }

    /** @param array<string, mixed> $context */
    private function resetAssistAttempt(array $context): array
    {
        unset($context['assist_attempt']);

        return $context;
    }

    /** @param array<string, mixed> $context */
    private function shouldHandoffAfterAssistAttempts(array $context): bool
    {
        $attempt = is_array($context['assist_attempt'] ?? null) ? $context['assist_attempt'] : [];

        return max(0, (int) ($attempt['count'] ?? 0)) >= self::MAX_ASSIST_ATTEMPTS;
    }

    /** @param array<string, mixed> $context */
    private function handoffAfterAssistLimit(?Company $company, Conversation $conversation, array $context): array
    {
        $targetAreaName = trim((string) ($context['target_area_name'] ?? BotFlowRegistry::AREA_ATTENDANCE));
        if ($targetAreaName === '') {
            $targetAreaName = BotFlowRegistry::AREA_ATTENDANCE;
        }

        return $this->handoffResult(
            $company,
            $conversation,
            'Não consegui concluir pelo atendimento automático. Vou te encaminhar para um atendente.',
            $targetAreaName
        );
    }

    private function normalizeText(string $value): string
    {
        $normalized = strtr(mb_strtolower(trim($value)), $this->accentMap());

        return preg_replace('/\s+/', ' ', $normalized) ?? $normalized;
    }

    private function normalizeLookupText(string $value): string
    {
        $normalized = preg_replace('/[^a-z0-9]+/', ' ', $this->normalizeText($value)) ?? '';

        return trim((string) preg_replace('/\s+/', ' ', $normalized));
    }

    /**
     * @return array<string, string>
     */
    private function accentMap(): array
    {
        return [
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
            'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'ç' => 'c', 'ñ' => 'n',
        ];
    }


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
