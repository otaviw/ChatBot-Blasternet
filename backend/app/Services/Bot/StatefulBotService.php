<?php

namespace App\Services\Bot;

use App\Models\AppointmentService;
use App\Models\AppointmentSetting;
use App\Models\AppointmentStaffProfile;
use App\Models\Area;
use App\Models\Company;
use App\Models\Conversation;
use App\Services\Appointments\AppointmentAvailabilityService;
use App\Services\Appointments\AppointmentBookingService;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class StatefulBotService
{
    public function __construct(
        private BotFlowRegistry $registry,
        private AppointmentAvailabilityService $appointmentAvailability,
        private AppointmentBookingService $appointmentBooking
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(
        ?Company $company,
        Conversation $conversation,
        string $inputText,
        bool $isFirstInboundMessage
    ): array {
        unset($isFirstInboundMessage);

        $definition = $this->registry->definitionForCompany($company);

        $normalizedText = trim($inputText);

        if ($this->isMenuCommand($normalizedText, $definition['commands'] ?? [])) {
            return $this->buildInitialMenuResponse($definition);
        }

        $flow = is_string($conversation->bot_flow) ? trim($conversation->bot_flow) : '';
        $step = is_string($conversation->bot_step) ? trim($conversation->bot_step) : '';
        if ($flow === '' || $step === '') {
            return $this->buildInitialMenuResponse($definition);
        }

        if ($flow === 'appointments') {
            return $this->handleAppointmentsFlow($company, $conversation, $step, $normalizedText);
        }

        $stateKey = $this->stateKey($flow, $step);
        $stepDefinition = is_array($definition['steps'][$stateKey] ?? null)
            ? $definition['steps'][$stateKey]
            : null;
        if (! is_array($stepDefinition)) {
            return $this->notHandled();
        }

        $stepType = (string) ($stepDefinition['type'] ?? '');
        if ($stepType === 'numeric_menu') {
            return $this->handleNumericMenuStep(
                $company,
                $conversation,
                $definition,
                $flow,
                $step,
                $normalizedText,
                $stepDefinition
            );
        }

        if ($stepType === 'free_text') {
            return $this->handleFreeTextStep(
                $company,
                $conversation,
                $definition,
                $normalizedText,
                $stepDefinition,
                $flow,
                $step
            );
        }

        return $this->notHandled();
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    private function buildInitialMenuResponse(array $definition): array
    {
        $initial = is_array($definition['initial'] ?? null) ? $definition['initial'] : null;
        if (! is_array($initial)) {
            return $this->notHandled();
        }

        $flow = trim((string) ($initial['flow'] ?? ''));
        $step = trim((string) ($initial['step'] ?? ''));
        if ($flow === '' || $step === '') {
            return $this->notHandled();
        }

        $stateKey = $this->stateKey($flow, $step);
        $initialStep = is_array($definition['steps'][$stateKey] ?? null)
            ? $definition['steps'][$stateKey]
            : null;
        if (! is_array($initialStep)) {
            return $this->notHandled();
        }

        $replyText = trim((string) ($initialStep['reply_text'] ?? ''));
        if ($replyText === '') {
            return $this->notHandled();
        }

        $context = [];
        if (($initialStep['type'] ?? null) === 'numeric_menu') {
            $context['last_menu_keys'] = array_map(
                static fn($value) => (string) $value,
                array_keys(is_array($initialStep['options'] ?? null) ? $initialStep['options'] : [])
            );
        }

        return $this->botStateResult($replyText, [
            'flow' => $flow,
            'step' => $step,
            'context' => $context,
        ]);
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>  $stepDefinition
     * @return array<string, mixed>
     */
    private function handleNumericMenuStep(
        ?Company $company,
        Conversation $conversation,
        array $definition,
        string $flow,
        string $step,
        string $normalizedText,
        array $stepDefinition
    ): array {
        $rawOptions = is_array($stepDefinition['options'] ?? null) ? $stepDefinition['options'] : [];
        $optionsByKey = [];
        foreach ($rawOptions as $optionKey => $optionDefinition) {
            if (! is_array($optionDefinition)) {
                continue;
            }
            $optionsByKey[(string) $optionKey] = $optionDefinition;
        }
        $expectedOptions = array_map(
            static fn($value) => (string) $value,
            array_keys($optionsByKey)
        );

        if (! in_array($normalizedText, $expectedOptions, true)) {
            $invalidOptionText = trim((string) ($stepDefinition['invalid_option_text'] ?? ''));
            if ($invalidOptionText === '') {
                $invalidOptionText = $this->registry->invalidOptionText($expectedOptions);
            }

            return $this->botStateResult(
                $invalidOptionText,
                [
                    'flow' => $flow,
                    'step' => $step,
                    'context' => [
                        'last_menu_keys' => $expectedOptions,
                    ],
                ]
            );
        }

        $selectedOption = is_array($optionsByKey[$normalizedText] ?? null) ? $optionsByKey[$normalizedText] : null;
        $action = is_array($selectedOption['action'] ?? null) ? $selectedOption['action'] : null;
        if (! is_array($action)) {
            return $this->notHandled();
        }

        return $this->executeAction(
            $company,
            $conversation,
            $definition,
            $action,
            ['selected_option' => $normalizedText]
        );
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>  $stepDefinition
     * @return array<string, mixed>
     */
    private function handleFreeTextStep(
        ?Company $company,
        Conversation $conversation,
        array $definition,
        string $normalizedText,
        array $stepDefinition,
        string $flow,
        string $step
    ): array {
        if ($normalizedText === '') {
            $emptyReply = trim((string) ($stepDefinition['empty_input_reply_text'] ?? ''));
            if ($emptyReply === '') {
                $emptyReply = trim((string) ($stepDefinition['reply_text'] ?? ''));
            }
            if ($emptyReply === '') {
                return $this->notHandled();
            }

            return $this->botStateResult($emptyReply, [
                'flow' => $flow,
                'step' => $step,
                'context' => [],
            ]);
        }

        $action = is_array($stepDefinition['on_text'] ?? null) ? $stepDefinition['on_text'] : null;
        if (! is_array($action)) {
            return $this->notHandled();
        }

        return $this->executeAction(
            $company,
            $conversation,
            $definition,
            $action,
            ['free_text_input' => $normalizedText]
        );
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>  $action
     * @param  array<string, mixed>  $extraContext
     * @return array<string, mixed>
     */
    private function executeAction(
        ?Company $company,
        Conversation $conversation,
        array $definition,
        array $action,
        array $extraContext = []
    ): array {
        $kind = trim((string) ($action['kind'] ?? ''));
        if ($kind === 'go_to') {
            $nextFlow = trim((string) ($action['flow'] ?? ''));
            $nextStep = trim((string) ($action['step'] ?? ''));
            if ($nextFlow === '' || $nextStep === '') {
                return $this->notHandled();
            }

            $nextStateKey = $this->stateKey($nextFlow, $nextStep);
            $nextStepDefinition = is_array($definition['steps'][$nextStateKey] ?? null)
                ? $definition['steps'][$nextStateKey]
                : null;
            if (! is_array($nextStepDefinition)) {
                return $this->notHandled();
            }

            $actionReply = trim((string) ($action['reply_text'] ?? ''));
            $defaultReply = trim((string) ($nextStepDefinition['reply_text'] ?? ''));
            $replyText = $actionReply !== '' ? $actionReply : $defaultReply;
            if ($replyText === '') {
                return $this->notHandled();
            }

            $context = $extraContext;
            if (($nextStepDefinition['type'] ?? null) === 'numeric_menu') {
                $context['last_menu_keys'] = array_map(
                    static fn($value) => (string) $value,
                    array_keys(is_array($nextStepDefinition['options'] ?? null) ? $nextStepDefinition['options'] : [])
                );
            }

            return $this->botStateResult($replyText, [
                'flow' => $nextFlow,
                'step' => $nextStep,
                'context' => $context,
            ]);
        }

        if ($kind === 'appointments_start') {
            return $this->startAppointmentsFlow($company, $conversation, $action);
        }

        if ($kind !== 'handoff') {
            return $this->notHandled();
        }

        $targetAreaName = trim((string) ($action['target_area_name'] ?? ''));
        if ($targetAreaName === '') {
            return $this->notHandled();
        }

        $replyText = trim((string) ($action['reply_text'] ?? ''));
        if ($replyText === '') {
            $replyText = "Certo. Vou te encaminhar para {$targetAreaName}.";
        }

        return $this->handoffResult($company, $conversation, $replyText, $targetAreaName);
    }

    /**
     * @return array<string, mixed>
     */
    private function handoffResult(
        ?Company $company,
        Conversation $conversation,
        string $replyText,
        string $targetAreaName
    ): array {
        $assignment = $this->resolveAreaAssignment($company, $conversation, $targetAreaName);

        return [
            'handled' => true,
            'not_handled' => false,
            'reply_text' => $replyText,
            'should_handoff' => true,
            'handoff_target' => $assignment['handoff_target'],
            'new_state' => null,
            'clear_state' => true,
            'set_handling_mode' => 'human',
            'set_assigned_type' => $assignment['set_assigned_type'],
            'set_assigned_id' => $assignment['set_assigned_id'],
            'set_current_area_id' => $assignment['set_current_area_id'],
        ];
    }

    /**
     * @param  array<string, mixed>  $newState
     * @return array<string, mixed>
     */
    private function botStateResult(string $replyText, array $newState): array
    {
        return [
            'handled' => true,
            'not_handled' => false,
            'reply_text' => $replyText,
            'should_handoff' => false,
            'handoff_target' => null,
            'new_state' => $newState,
            'clear_state' => false,
            'set_handling_mode' => 'bot',
            'set_assigned_type' => 'bot',
            'set_assigned_id' => null,
            'set_current_area_id' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $action
     * @return array<string, mixed>
     */
    private function startAppointmentsFlow(?Company $company, Conversation $conversation, array $action): array
    {
        $companyEntity = $this->resolveCompany($company, $conversation);
        $services = $this->activeAppointmentServices($companyEntity);
        if ($services === []) {
            $targetAreaName = trim((string) ($action['target_area_name'] ?? BotFlowRegistry::AREA_ATTENDANCE));
            $replyText = trim((string) ($action['reply_text'] ?? ''));
            if ($replyText === '') {
                $replyText = 'No momento não há agenda automática disponível. Vou te encaminhar para um atendente.';
            }

            return $this->handoffResult($companyEntity, $conversation, $replyText, $targetAreaName);
        }

        // vai direto para escolha de atendente
        $service = $services[0];
        $context = $this->appointmentContext($conversation);
        $context['service_id'] = (int) $service['id'];
        $context['service_name'] = (string) $service['name'];
        $context['target_area_name'] = trim((string) ($action['target_area_name'] ?? BotFlowRegistry::AREA_ATTENDANCE));

        $settings = $this->appointmentSettings($companyEntity);
        $staffProfiles = $this->activeAppointmentStaff($companyEntity);
        $allowChooseStaff = (bool) ($settings?->allow_customer_choose_staff ?? true);
        $hasMoreThanOne = count($staffProfiles) > 1;

        // guarda se o cliente pode trocar de atendente
        $context['has_staff_choice'] = $allowChooseStaff && $hasMoreThanOne;

        if (! $allowChooseStaff || ! $hasMoreThanOne) {
            $context['staff_profile_id'] = count($staffProfiles) === 1 ? (int) $staffProfiles[0]['id'] : null;
            $context['staff_name'] = count($staffProfiles) === 1 ? (string) $staffProfiles[0]['name'] : null;

            return $this->replyWithAppointmentDayMenu($companyEntity, $context);
        }

        $context['staff_options'] = $this->enumerateStaff($staffProfiles);

        return $this->botStateResult(
            $this->appointmentStaffMenuText($context['staff_options']),
            [
                'flow' => 'appointments',
                'step' => 'staff_select',
                'context' => ['appointment' => $context],
            ]
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function handleAppointmentsFlow(
        ?Company $company,
        Conversation $conversation,
        string $step,
        string $normalizedText
    ): array {
        $companyEntity = $this->resolveCompany($company, $conversation);
        $context = $this->appointmentContext($conversation);

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
            'staff_select' => $this->handleAppointmentStaffSelection($companyEntity, $conversation, $normalizedText, $context),
            'day_select' => $this->handleAppointmentDaySelection($companyEntity, $conversation, $normalizedText, $context),
            'slot_select' => $this->handleAppointmentSlotSelection($companyEntity, $conversation, $normalizedText, $context),
            'nearest_select' => $this->handleAppointmentNearestSelection($companyEntity, $conversation, $normalizedText, $context),
            'confirm' => $this->handleAppointmentConfirmation($companyEntity, $conversation, $normalizedText, $context),
            default => $this->buildInitialMenuResponse($this->registry->definitionForCompany($companyEntity)),
        };
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

        $serviceOptions = $this->enumerateServices($services);
        if (! isset($serviceOptions[$normalizedText])) {
            return $this->botStateResult(
                $this->appointmentServiceMenuText($serviceOptions, true),
                [
                    'flow' => 'appointments',
                    'step' => 'service_select',
                    'context' => ['appointment' => array_merge($context, ['service_options' => $serviceOptions])],
                ]
            );
        }

        $selectedService = $serviceOptions[$normalizedText];
        $context['service_id'] = (int) $selectedService['id'];
        $context['service_name'] = (string) $selectedService['name'];
        unset($context['selected_date'], $context['slot_page'], $context['slot_starts_at'], $context['slot_ends_at']);

        $settings = $this->appointmentSettings($company);
        $staffProfiles = $this->activeAppointmentStaff($company);
        $allowChooseStaff = (bool) ($settings?->allow_customer_choose_staff ?? true);
        $hasMoreThanOne = count($staffProfiles) > 1;

        if (! $allowChooseStaff || ! $hasMoreThanOne) {
            $context['staff_profile_id'] = count($staffProfiles) === 1 ? (int) $staffProfiles[0]['id'] : null;
            $context['staff_name'] = count($staffProfiles) === 1 ? (string) $staffProfiles[0]['name'] : null;
            $context['week_start'] = $this->currentWeekStart($settings?->timezone)->toDateString();

            return $this->replyWithAppointmentDayMenu($company, $context);
        }

        $context['staff_options'] = $this->enumerateStaff($staffProfiles);

        return $this->botStateResult(
            $this->appointmentStaffMenuText($context['staff_options']),
            [
                'flow' => 'appointments',
                'step' => 'staff_select',
                'context' => ['appointment' => $context],
            ]
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
            $context['staff_name'] = null;
            // reseta histórico de dia ao trocar para qualquer atendente
            unset($context['last_day_key'], $context['last_day_date'], $context['selected_date'], $context['slot_page']);

            return $this->replyWithAppointmentDayMenu($company, $context);
        }

        if (! isset($staffOptions[$normalizedText])) {
            return $this->botStateResult(
                $this->appointmentStaffMenuText($staffOptions, true),
                [
                    'flow' => 'appointments',
                    'step' => 'staff_select',
                    'context' => ['appointment' => array_merge($context, ['staff_options' => $staffOptions])],
                ]
            );
        }

        $selectedStaff = $staffOptions[$normalizedText];
        $context['staff_profile_id'] = (int) $selectedStaff['id'];
        $context['staff_name'] = (string) $selectedStaff['name'];
        // reseta histórico de dia ao trocar de atendente
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
        $maxDays = (int) ($settings?->booking_max_advance_days ?? 30);

        // 0: voltar para escolher atendente, só aparece quando há essa opção
        if ($normalizedText === '0' && (bool) ($context['has_staff_choice'] ?? false)) {
            $staffProfiles = $this->activeAppointmentStaff($company);
            $context['staff_options'] = $this->enumerateStaff($staffProfiles);
            unset($context['last_day_key'], $context['last_day_date'], $context['selected_date'], $context['slot_page']);

            return $this->botStateResult(
                $this->appointmentStaffMenuText($context['staff_options']),
                [
                    'flow' => 'appointments',
                    'step' => 'staff_select',
                    'context' => ['appointment' => $context],
                ]
            );
        }

        // 7: próxima semana, só aparece quando um dia já foi selecionado antes
        $lastDayKey = trim((string) ($context['last_day_key'] ?? ''));
        $lastDayDate = trim((string) ($context['last_day_date'] ?? ''));
        if ($normalizedText === '7' && $lastDayKey !== '' && $lastDayDate !== '') {
            $fromDate = CarbonImmutable::parse($lastDayDate, $timezone)->addWeek()->toDateString();
            $maxDate = CarbonImmutable::now($timezone)->addDays($maxDays)->toDateString();
            if ($fromDate > $maxDate) {
                return $this->replyWithAppointmentDayMenu($company, $context, false,
                    "Não há semanas disponíveis dentro do limite de {$maxDays} dias.");
            }
            // mantém o mesmo dia da semana do último acesso
            $lastDt = CarbonImmutable::parse($lastDayDate, $timezone);
            $selectedDate = $this->nextOccurrenceOfDay((int) $lastDt->dayOfWeek, $fromDate, $timezone);
            $context['last_day_date'] = $selectedDate;
            $selectedDt = CarbonImmutable::parse($selectedDate, $timezone);
            $slots = $this->appointmentSlotsForDate($company, $context, $selectedDate);
            if ($slots === []) {
                $noSlotMsg = "Não há horários disponíveis em " . $selectedDt->translatedFormat('D d/m') . ".";
                $noSlotMsg .= "\n\n" . $this->appointmentDayPromptText($context);

                return $this->botStateResult($noSlotMsg, [
                    'flow' => 'appointments',
                    'step' => 'day_select',
                    'context' => ['appointment' => $context],
                ]);
            }
            $context['selected_date'] = $selectedDate;
            $context['slot_page'] = 0;

            return $this->replyWithAppointmentSlotMenu($company, $context);
        }

        // 8: próximos horários disponíveis
        if ($normalizedText === '8') {
            return $this->replyWithNearestSlots($company, $context);
        }

        $parsed = $this->parseDayInput($normalizedText, $timezone);
        if ($parsed === null) {
            return $this->replyWithAppointmentDayMenu($company, $context, true);
        }

        // logica de dia diferente, próxima ocorrência a partir de hoje
        if ($parsed['type'] === 'weekday') {
            $fromDate = CarbonImmutable::now($timezone)->startOfDay()->toDateString();
        } else {
            $fromDate = $parsed['date'];
        }

        $selectedDate = $this->nextOccurrenceOfDay($parsed['day_of_week'], $fromDate, $timezone);

        // verifica limite máximo de antecedência
        $maxDate = CarbonImmutable::now($timezone)->addDays($maxDays)->toDateString();
        if ($selectedDate > $maxDate) {
            return $this->botStateResult(
                "Não é possível agendar além de {$maxDays} dias. Escolha um dia mais próximo.\n\n" . $this->appointmentDayPromptText($context),
                [
                    'flow' => 'appointments',
                    'step' => 'day_select',
                    'context' => ['appointment' => $context],
                ]
            );
        }

        // salva o último dia selecionado para o 7 - proxima semana
        $context['last_day_key'] = $parsed['key'];
        $context['last_day_date'] = $selectedDate;

        $selectedDt = CarbonImmutable::parse($selectedDate, $timezone);
        $slots = $this->appointmentSlotsForDate($company, $context, $selectedDate);

        if ($slots === []) {
            $noSlotMsg = "Não há horários disponíveis em " . $selectedDt->translatedFormat('D d/m') . ".";
            $noSlotMsg .= "\n\n" . $this->appointmentDayPromptText($context);

            return $this->botStateResult(
                $noSlotMsg,
                [
                    'flow' => 'appointments',
                    'step' => 'day_select',
                    'context' => ['appointment' => $context],
                ]
            );
        }

        $context['selected_date'] = $selectedDate;
        $context['slot_page'] = 0;

        return $this->replyWithAppointmentSlotMenu($company, $context);
    }

    /**
     * Interpreta texto digitado pelo cliente como dia.
     * Retorna array com:
     *   type: 'weekday' (nome de dia da semana) ou 'absolute' (data fixa)
     *   day_of_week: int (0=dom … 6=sab)
     *   date: string YYYY-MM-DD (para tipo absolute)
     *   key: string normalizado usado para detectar repetição
     *
     * @return array{type:string,day_of_week:int,date:string,key:string}|null
     */
    private function parseDayInput(string $text, string $timezone): ?array
    {
        $tz = $timezone ?: 'America/Sao_Paulo';
        $accents = [
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
            'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'ç' => 'c', 'ñ' => 'n',
        ];
        $n = mb_strtolower(trim(strtr($text, $accents)));

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
            $day = (int) $m[1];
            $month = (int) $m[2];
            $year = isset($m[3]) && $m[3] !== '' ? (int) $m[3] : (int) $today->year;
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

    /**
     * Retorna a próxima ocorrência do dia da semana $dayOfWeek
     * a partir de $fromDate (inclusive).
     */
    private function nextOccurrenceOfDay(int $dayOfWeek, string $fromDate, string $timezone): string
    {
        $tz = $timezone ?: 'America/Sao_Paulo';
        $date = CarbonImmutable::parse($fromDate, $tz)->startOfDay();
        $diff = ($dayOfWeek - (int) $date->dayOfWeek + 7) % 7;

        return $date->addDays($diff)->toDateString();
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

        $page = max(0, (int) ($context['slot_page'] ?? 0));
        $offset = $page * 7;
        $pageSlots = array_slice($slots, $offset, 7);
        $slotOptions = [];
        foreach ($pageSlots as $index => $slot) {
            $slotOptions[(string) ($index + 1)] = $slot;
        }

        if (! isset($slotOptions[$normalizedText])) {
            return $this->replyWithAppointmentSlotMenu($company, $context, true);
        }

        $selectedSlot = $slotOptions[$normalizedText];
        $context['slot_starts_at'] = (string) ($selectedSlot['starts_at_local'] ?? $selectedSlot['starts_at'] ?? '');
        $context['slot_ends_at'] = (string) ($selectedSlot['ends_at_local'] ?? $selectedSlot['ends_at'] ?? '');
        $context['staff_profile_id'] = (int) ($selectedSlot['staff_profile_id'] ?? ($context['staff_profile_id'] ?? 0));
        $context['staff_name'] = (string) ($selectedSlot['staff_name'] ?? ($context['staff_name'] ?? ''));
        $timezone = $this->appointmentSettings($company)?->timezone ?: 'America/Sao_Paulo';

        return $this->botStateResult(
            $this->appointmentConfirmText($context, $timezone),
            [
                'flow' => 'appointments',
                'step' => 'confirm',
                'context' => ['appointment' => $context],
            ]
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
            return $this->botStateResult(
                "Opção inválida. Responda com 1, 2 ou 9.\n\n" . $this->appointmentConfirmText($context, $timezone),
                [
                    'flow' => 'appointments',
                    'step' => 'confirm',
                    'context' => ['appointment' => $context],
                ]
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
                    'service_id' => (int) ($context['service_id'] ?? 0),
                    'staff_profile_id' => (int) ($context['staff_profile_id'] ?? 0),
                    'starts_at' => (string) ($context['slot_starts_at'] ?? ''),
                    'customer_name' => $conversation->customer_name,
                    'customer_phone' => $conversation->customer_phone,
                    'source' => 'whatsapp',
                    'meta' => [
                        'bot_flow' => 'stateful_appointments',
                    ],
                ],
                null
            );
        } catch (ValidationException $exception) {
            $message = collect($exception->errors())->flatten()->first() ?: 'Não consegui confirmar esse horário.';

            return $this->botStateResult(
                "{$message}\n\n" . $this->appointmentDayPromptText($context),
                [
                    'flow' => 'appointments',
                    'step' => 'day_select',
                    'context' => ['appointment' => $context],
                ]
            );
        }

        $startsAt = $appointment->starts_at?->setTimezone($timezone);
        $dayName = $startsAt?->translatedFormat('l') ?? '';
        $startsAtDate = $startsAt?->format('d/m/Y') ?? '';
        $startsAtTime = $startsAt?->format('H:i') ?? '';
        $serviceName = (string) ($context['service_name'] ?? 'serviço');
        $staffName = trim((string) ($context['staff_name'] ?? ''));
        $staffPart = $staffName !== '' ? "\nAtendente: {$staffName}" : '';
        $replyText = "✅ Agendamento confirmado!\n\nData: {$dayName}, {$startsAtDate}\nHorário: {$startsAtTime}\nServiço: {$serviceName}{$staffPart}\n\nAté lá! 😊";

        $menu = $this->buildInitialMenuResponse($this->registry->definitionForCompany($companyEntity));

        return $this->botStateResult(
            $replyText,
            $menu['new_state'] ?? [
                'flow' => 'main',
                'step' => 'menu',
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
            $parts[] = "Não entendi. Diga um dia como: segunda, terça, hoje, amanhã ou 15/04";
        }
        if ($extraMessage !== '') {
            $parts[] = $extraMessage;
        }
        $parts[] = $this->appointmentDayPromptText($context);

        return $this->botStateResult(
            implode("\n\n", $parts),
            [
                'flow' => 'appointments',
                'step' => 'day_select',
                'context' => ['appointment' => $context],
            ]
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

        $page = max(0, (int) ($context['slot_page'] ?? 0));
        $offset = $page * 7;
        if ($offset >= count($slots)) {
            $page = 0;
            $offset = 0;
        }

        $context['slot_page'] = $page;
        $pageSlots = array_slice($slots, $offset, 7);
        $prefix = $invalidOption ? "Opção inválida. Escolha 1 a 8, 0 ou 9.\n\n" : '';
        $timezone = $this->appointmentSettings($company)?->timezone ?: 'America/Sao_Paulo';
        $hasStaffChoice = (bool) ($context['has_staff_choice'] ?? false);

        return $this->botStateResult(
            $prefix . $this->appointmentSlotMenuText($selectedDate, $pageSlots, ($offset + 7) < count($slots), $timezone, $hasStaffChoice),
            [
                'flow' => 'appointments',
                'step' => 'slot_select',
                'context' => ['appointment' => $context],
            ]
        );
    }

    /**
     * Coleta os próximos horários disponíveis a partir de hoje, até encontrar $limit slots ou atingir maxDays.
     *
     * @param  array<string, mixed>  $context
     * @return array<int, array<string, mixed>>
     */
    private function appointmentNearestSlots(?Company $company, array $context, int $limit = 7): array
    {
        $settings = $this->appointmentSettings($company);
        $timezone = (string) ($settings?->timezone ?: 'America/Sao_Paulo');
        $maxDays = (int) ($settings?->booking_max_advance_days ?? 30);

        $result = [];
        $date = CarbonImmutable::now($timezone)->startOfDay();
        $maxDate = $date->addDays($maxDays);

        while ($date->lte($maxDate) && count($result) < $limit) {
            $slots = $this->appointmentSlotsForDate($company, $context, $date->toDateString());
            foreach ($slots as $slot) {
                $result[] = array_merge($slot, ['date' => $date->toDateString()]);
                if (count($result) >= $limit) {
                    break;
                }
            }
            $date = $date->addDay();
        }

        return $result;
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
        $timezone = (string) ($this->appointmentSettings($company)?->timezone ?: 'America/Sao_Paulo');
        $hasStaffChoice = (bool) ($context['has_staff_choice'] ?? false);

        return $this->botStateResult(
            $this->appointmentNearestSlotsText($slots, $timezone, $hasStaffChoice),
            [
                'flow' => 'appointments',
                'step' => 'nearest_select',
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
            $timezone = (string) ($this->appointmentSettings($company)?->timezone ?: 'America/Sao_Paulo');
            $hasStaffChoice = (bool) ($context['has_staff_choice'] ?? false);

            return $this->botStateResult(
                "Opção inválida.\n\n" . $this->appointmentNearestSlotsText($slots, $timezone, $hasStaffChoice),
                [
                    'flow' => 'appointments',
                    'step' => 'nearest_select',
                    'context' => ['appointment' => $context],
                ]
            );
        }

        $selectedSlot = $slotOptions[$normalizedText];
        $timezone = $this->appointmentSettings($company)?->timezone ?: 'America/Sao_Paulo';
        $context['selected_date'] = (string) ($selectedSlot['date'] ?? '');
        $context['slot_starts_at'] = (string) ($selectedSlot['starts_at_local'] ?? $selectedSlot['starts_at'] ?? '');
        $context['slot_ends_at'] = (string) ($selectedSlot['ends_at_local'] ?? $selectedSlot['ends_at'] ?? '');
        $context['staff_profile_id'] = (int) ($selectedSlot['staff_profile_id'] ?? ($context['staff_profile_id'] ?? 0));
        $context['staff_name'] = (string) ($selectedSlot['staff_name'] ?? ($context['staff_name'] ?? ''));

        return $this->botStateResult(
            $this->appointmentConfirmText($context, $timezone),
            [
                'flow' => 'appointments',
                'step' => 'confirm',
                'context' => ['appointment' => $context],
            ]
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $slots
     */
    private function appointmentNearestSlotsText(array $slots, string $timezone = 'America/Sao_Paulo', bool $hasStaffChoice = false): string
    {
        $tz = $timezone ?: 'America/Sao_Paulo';
        $lines = ['Próximos horários disponíveis:'];
        foreach (array_slice($slots, 0, 7) as $index => $slot) {
            $candidate = (string) ($slot['starts_at_local'] ?? $slot['starts_at'] ?? '');
            $startsAt = CarbonImmutable::parse($candidate)->setTimezone($tz);
            $dateLabel = $startsAt->translatedFormat('D d/m');
            $timeLabel = $startsAt->format('H:i');
            $staffName = trim((string) ($slot['staff_name'] ?? ''));
            $suffix = $staffName !== '' ? " ({$staffName})" : '';
            $lines[] = ($index + 1) . " - {$dateLabel} {$timeLabel}{$suffix}";
        }
        $lines[] = '0 - Voltar';
        $lines[] = '9 - Falar com atendente';

        return implode("\n", $lines);
    }

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
            $lines[] = 'Opção inválida.';
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
        $staffName = trim((string) ($context['staff_name'] ?? ''));
        $staffLine = $staffName !== '' ? "Atendente: {$staffName}" : 'Atendente: qualquer disponível';
        $hasStaffChoice = (bool) ($context['has_staff_choice'] ?? false);
        $hasLastDay = trim((string) ($context['last_day_date'] ?? '')) !== '';

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
    private function appointmentSlotMenuText(string $selectedDate, array $slots, bool $hasMore, string $timezone = 'America/Sao_Paulo', bool $hasStaffChoice = false): string
    {
        $tz = $timezone ?: 'America/Sao_Paulo';
        $date = CarbonImmutable::parse($selectedDate);
        $lines = [];
        $lines[] = 'Horários de ' . $date->translatedFormat('D d/m') . ':';
        foreach ($slots as $index => $slot) {
            $candidate = (string) ($slot['starts_at_local'] ?? $slot['starts_at'] ?? '');
            $startsAt = CarbonImmutable::parse($candidate)->setTimezone($tz);
            $timeLabel = $startsAt->format('H:i');
            $staffName = trim((string) ($slot['staff_name'] ?? ''));
            $suffix = $staffName !== '' ? " ({$staffName})" : '';
            $lines[] = ($index + 1) . " - {$timeLabel}{$suffix}";
        }
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
        $tz = $timezone ?: 'America/Sao_Paulo';
        $startsAt = CarbonImmutable::parse((string) ($context['slot_starts_at'] ?? ''))->setTimezone($tz);
        $serviceName = (string) ($context['service_name'] ?? 'serviço');
        $staffName = trim((string) ($context['staff_name'] ?? ''));
        $staffText = $staffName !== '' ? "Atendente: {$staffName}\n" : '';
        $dayName = $startsAt->translatedFormat('l');

        return "Confirma o agendamento?\nData: {$dayName}, {$startsAt->format('d/m/Y')}\nHora: {$startsAt->format('H:i')}\nServiço: {$serviceName}\n{$staffText}1 - Confirmar\n2 - Escolher outro horário\n9 - Falar com atendente";
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

        $staffId = isset($context['staff_profile_id']) && (int) $context['staff_profile_id'] > 0
            ? (int) $context['staff_profile_id']
            : null;

        $availability = $this->appointmentAvailability->listAvailableSlots($company, $serviceId, $date, $staffId);
        $slots = [];
        foreach (($availability['staff'] ?? []) as $staffAvailability) {
            $staffProfileId = (int) ($staffAvailability['staff_profile_id'] ?? 0);
            $staffName = (string) ($staffAvailability['staff_name'] ?? '');
            foreach (($staffAvailability['slots'] ?? []) as $slot) {
                $slots[] = [
                    'starts_at' => (string) ($slot['starts_at'] ?? ''),
                    'ends_at' => (string) ($slot['ends_at'] ?? ''),
                    'starts_at_local' => (string) ($slot['starts_at_local'] ?? ''),
                    'ends_at_local' => (string) ($slot['ends_at_local'] ?? ''),
                    'staff_profile_id' => $staffProfileId,
                    'staff_name' => $staffName,
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
                'id' => (int) $service->id,
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
                'id' => (int) $profile->id,
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

    private function currentWeekStart(?string $timezone): CarbonImmutable
    {
        $tz = $timezone ?: 'America/Sao_Paulo';

        return CarbonImmutable::now($tz)->startOfWeek(CarbonImmutable::MONDAY);
    }

    private function parseWeekStart(mixed $weekStart, ?string $timezone): CarbonImmutable
    {
        $tz = $timezone ?: 'America/Sao_Paulo';
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
     * @return array<string, mixed>
     */
    private function appointmentContext(Conversation $conversation): array
    {
        $context = is_array($conversation->bot_context ?? null) ? $conversation->bot_context : [];
        $appointmentContext = is_array($context['appointment'] ?? null) ? $context['appointment'] : [];

        return $appointmentContext;
    }

    private function resolveCompany(?Company $company, Conversation $conversation): ?Company
    {
        if ($company?->id) {
            return $company;
        }

        if ((int) $conversation->company_id <= 0) {
            return null;
        }

        return Company::query()->find((int) $conversation->company_id);
    }

    /**
     * @return array<string, mixed>
     */
    private function notHandled(): array
    {
        return [
            'handled' => false,
            'not_handled' => true,
            'reply_text' => null,
            'should_handoff' => false,
            'handoff_target' => null,
            'new_state' => null,
            'clear_state' => false,
            'set_handling_mode' => null,
            'set_assigned_type' => null,
            'set_assigned_id' => null,
            'set_current_area_id' => null,
        ];
    }

    /**
     * @return array{
     *     handoff_target: array<string,mixed>|null,
     *     set_assigned_type: string,
     *     set_assigned_id: int|null,
     *     set_current_area_id: int|null
     * }
     */
    private function resolveAreaAssignment(?Company $company, Conversation $conversation, string $targetAreaName): array
    {
        $companyId = (int) ($company?->id ?: $conversation->company_id);
        $areaLabel = trim($targetAreaName);

        if ($companyId > 0 && $areaLabel !== '') {
            $area = Area::query()
                ->where('company_id', $companyId)
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($areaLabel)])
                ->first(['id', 'name']);

            if ($area) {
                return [
                    'handoff_target' => [
                        'type' => 'area',
                        'id' => (int) $area->id,
                        'name' => (string) $area->name,
                    ],
                    'set_assigned_type' => 'area',
                    'set_assigned_id' => (int) $area->id,
                    'set_current_area_id' => (int) $area->id,
                ];
            }
        }

        return [
            'handoff_target' => $areaLabel === '' ? null : [
                'type' => 'area',
                'id' => null,
                'name' => $areaLabel,
            ],
            'set_assigned_type' => 'unassigned',
            'set_assigned_id' => null,
            'set_current_area_id' => null,
        ];
    }

    /**
     * @param  array<int, string>  $commands
     */
    private function isMenuCommand(string $inputText, array $commands): bool
    {
        $compact = preg_replace('/\s+/', '', mb_strtolower($inputText)) ?? '';

        if ($compact === '') {
            return false;
        }

        return in_array($compact, $commands, true);
    }

    private function stateKey(string $flow, string $step): string
    {
        return "{$flow}.{$step}";
    }
}
