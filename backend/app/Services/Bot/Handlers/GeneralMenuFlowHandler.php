<?php

declare(strict_types=1);


namespace App\Services\Bot\Handlers;

use App\Models\Company;
use App\Models\Conversation;
use App\Services\Bot\BotFlowRegistry;

class GeneralMenuFlowHandler
{
    use BotHandlerHelpers;

    public function __construct(
        private BotFlowRegistry $registry,
        private AppointmentFlowHandler $appointmentHandler,
    ) {}

    /**
     * Handles a generic flow step (numeric_menu or free_text).
     *
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    public function handleStep(
        ?Company $company,
        Conversation $conversation,
        array $definition,
        string $flow,
        string $step,
        string $normalizedText
    ): array {
        $stateKey       = $this->stateKey($flow, $step);
        $stepDefinition = is_array($definition['steps'][$stateKey] ?? null)
            ? $definition['steps'][$stateKey]
            : null;

        if (! is_array($stepDefinition)) {
            return $this->notHandled();
        }

        $stepType = (string) ($stepDefinition['type'] ?? '');

        if ($stepType === 'numeric_menu') {
            return $this->handleNumericMenuStep(
                $company, $conversation, $definition, $flow, $step, $normalizedText, $stepDefinition
            );
        }

        if ($stepType === 'free_text') {
            return $this->handleFreeTextStep(
                $company, $conversation, $definition, $normalizedText, $stepDefinition, $flow, $step
            );
        }

        return $this->notHandled();
    }

    // -------------------------------------------------------------------------
    // Private step handlers
    // -------------------------------------------------------------------------

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
        $rawOptions  = is_array($stepDefinition['options'] ?? null) ? $stepDefinition['options'] : [];
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

        $resolvedKey = $this->resolveOptionKey($stepDefinition, $normalizedText);

        if ($resolvedKey === null) {
            $invalidOptionText = trim((string) ($stepDefinition['invalid_option_text'] ?? ''));
            if ($invalidOptionText === '') {
                $invalidOptionText = $this->registry->invalidOptionText($expectedOptions);
            }

            return $this->botStateResult(
                $invalidOptionText,
                [
                    'flow'    => $flow,
                    'step'    => $step,
                    'context' => ['last_menu_keys' => $expectedOptions],
                ]
            );
        }

        $selectedOption = is_array($optionsByKey[$resolvedKey] ?? null) ? $optionsByKey[$resolvedKey] : null;
        $action         = is_array($selectedOption['action'] ?? null) ? $selectedOption['action'] : null;
        if (! is_array($action)) {
            return $this->notHandled();
        }

        return $this->executeAction(
            $company,
            $conversation,
            $definition,
            $action,
            ['selected_option' => $resolvedKey]
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
                'flow'    => $flow,
                'step'    => $step,
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

            $nextStateKey       = $this->stateKey($nextFlow, $nextStep);
            $nextStepDefinition = is_array($definition['steps'][$nextStateKey] ?? null)
                ? $definition['steps'][$nextStateKey]
                : null;
            if (! is_array($nextStepDefinition)) {
                return $this->notHandled();
            }

            $actionReply = trim((string) ($action['reply_text'] ?? ''));
            $defaultReply = trim((string) ($nextStepDefinition['reply_text'] ?? ''));
            $replyText    = $actionReply !== '' ? $actionReply : $defaultReply;
            if ($replyText === '') {
                return $this->notHandled();
            }

            $context      = $extraContext;
            $replyMessage = null;
            if (($nextStepDefinition['type'] ?? null) === 'numeric_menu') {
                $context['last_menu_keys'] = array_map(
                    static fn($value) => (string) $value,
                    array_keys(is_array($nextStepDefinition['options'] ?? null) ? $nextStepDefinition['options'] : [])
                );
                $replyMessage = $this->buildMenuReplyMessage($nextStepDefinition);
            }

            return $this->botStateResult($replyText, [
                'flow'    => $nextFlow,
                'step'    => $nextStep,
                'context' => $context,
            ], $replyMessage);
        }

        if ($kind === 'appointments_start') {
            return $this->appointmentHandler->start($company, $conversation, $action);
        }

        if ($kind === 'appointments_cancel') {
            return $this->appointmentHandler->startCancellation($company, $conversation);
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

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $step
     */
    private function resolveOptionKey(array $step, string $input): ?string
    {
        $rawOptions = is_array($step['options'] ?? null) ? $step['options'] : [];

        foreach ($rawOptions as $key => $optionDef) {
            if ((string) $key === $input) {
                return (string) $key;
            }
        }

        foreach ($rawOptions as $key => $optionDef) {
            if (! is_array($optionDef)) {
                continue;
            }
            $storedId    = trim((string) ($optionDef['button_id'] ?? ''));
            $effectiveId = $storedId !== ''
                ? $storedId
                : $this->slugifyLabel((string) ($optionDef['label'] ?? ''));
            if ($effectiveId !== '' && $effectiveId === $input) {
                return (string) $key;
            }
        }

        return null;
    }
}
