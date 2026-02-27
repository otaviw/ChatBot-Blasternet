<?php

namespace App\Services\Bot;

use App\Models\Area;
use App\Models\Company;
use App\Models\Conversation;

class StatefulBotService
{
    public function __construct(
        private BotFlowRegistry $registry
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
