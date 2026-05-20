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
        private IxcInvoiceFlowHandler $ixcInvoiceHandler,
        private IxcFiscalNoteFlowHandler $ixcFiscalNoteHandler,
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
        string $normalizedText,
        array $extraContext = []
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
                $company, $conversation, $definition, $flow, $step, $normalizedText, $stepDefinition, $extraContext
            );
        }

        if ($stepType === 'free_text') {
            return $this->handleFreeTextStep(
                $company, $conversation, $definition, $normalizedText, $stepDefinition, $flow, $step
            );
        }

        return $this->notHandled();
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
        array $stepDefinition,
        array $extraContext = []
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
            $globalMatch = $this->resolveOptionFromDefinition($definition, $flow, $step, $normalizedText);
            if ($globalMatch !== null) {
                return $this->handleNumericMenuStep(
                    $company,
                    $conversation,
                    $definition,
                    $globalMatch['flow'],
                    $globalMatch['step'],
                    $globalMatch['option_key'],
                    $globalMatch['step_definition'],
                    $extraContext
                );
            }
        }

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
            array_merge($extraContext, [
                'selected_option' => $resolvedKey,
                'input_text' => $normalizedText,
            ])
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
            $appointmentAction = $action;
            $initialMessage = trim((string) ($extraContext['ai_message_text'] ?? $extraContext['input_text'] ?? ''));
            if ($initialMessage !== '') {
                $appointmentAction['initial_message_text'] = $initialMessage;
            }

            return $this->appointmentHandler->start($company, $conversation, $appointmentAction);
        }

        if ($kind === 'appointments_cancel') {
            return $this->appointmentHandler->startCancellation($company, $conversation);
        }

        if ($kind === 'ixc_invoices_start') {
            return $this->ixcInvoiceHandler->start($company, $conversation, $action);
        }
        if ($kind === 'ixc_fiscal_notes_start') {
            return $this->ixcFiscalNoteHandler->start($company, $conversation, $action);
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
     * @param  array<string, mixed>  $step
     */
    private function resolveOptionKey(array $step, string $input): ?string
    {
        $rawOptions = is_array($step['options'] ?? null) ? $step['options'] : [];
        $inputSlug  = $this->slugifyLabel($input);
        $inputLookup = $this->normalizeLookupText($input);

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
            if ($effectiveId !== '' && ($effectiveId === $input || $effectiveId === $inputSlug)) {
                return (string) $key;
            }

            $labelSlug = $this->slugifyLabel((string) ($optionDef['label'] ?? ''));
            if ($labelSlug !== '' && $labelSlug === $inputSlug) {
                return (string) $key;
            }

            if (
                $labelSlug !== ''
                && $inputSlug !== ''
                && (
                    (mb_strlen($labelSlug) >= 3 && str_contains($inputSlug, $labelSlug))
                    || (mb_strlen($inputSlug) >= 3 && str_contains($labelSlug, $inputSlug))
                )
            ) {
                return (string) $key;
            }

            $action = is_array($optionDef['action'] ?? null) ? $optionDef['action'] : [];
            if ($this->optionMatchesNaturalInput((string) ($optionDef['label'] ?? ''), $action, $inputLookup)) {
                return (string) $key;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array{flow:string,step:string,option_key:string,step_definition:array<string,mixed>}|null
     */
    private function resolveOptionFromDefinition(array $definition, string $currentFlow, string $currentStep, string $input): ?array
    {
        $inputLookup = $this->normalizeLookupText($input);
        if ($inputLookup === '' || $this->isAttendantNaturalInput($inputLookup)) {
            return null;
        }

        $steps = is_array($definition['steps'] ?? null) ? $definition['steps'] : [];
        foreach ($steps as $stateKey => $stepDefinition) {
            if (! is_string($stateKey) || ! str_contains($stateKey, '.') || ! is_array($stepDefinition)) {
                continue;
            }

            if (($stepDefinition['type'] ?? null) !== 'numeric_menu') {
                continue;
            }

            [$flow, $step] = explode('.', $stateKey, 2);
            if ($flow === $currentFlow && $step === $currentStep) {
                continue;
            }

            $options = is_array($stepDefinition['options'] ?? null) ? $stepDefinition['options'] : [];
            foreach ($options as $optionKey => $optionDef) {
                if (! is_array($optionDef)) {
                    continue;
                }

                $action = is_array($optionDef['action'] ?? null) ? $optionDef['action'] : [];
                if (($action['kind'] ?? null) === 'handoff') {
                    continue;
                }

                if ($this->optionMatchesNaturalInput((string) ($optionDef['label'] ?? ''), $action, $inputLookup)) {
                    return [
                        'flow' => $flow,
                        'step' => $step,
                        'option_key' => (string) $optionKey,
                        'step_definition' => $stepDefinition,
                    ];
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $action
     */
    private function optionMatchesNaturalInput(string $label, array $action, string $inputLookup): bool
    {
        if ($inputLookup === '') {
            return false;
        }

        $kind = mb_strtolower(trim((string) ($action['kind'] ?? '')));
        $haystack = $this->normalizeLookupText($label.' '.(string) ($action['target_area_name'] ?? '').' '.$kind);

        if ($this->containsAny($inputLookup, ['boleto', 'fatura', 'segunda via', 'pagamento', 'financeiro'])) {
            return in_array($kind, ['ixc_invoices_start', 'ixc_fiscal_notes_start'], true)
                || $this->containsAny($haystack, ['financeiro', 'boleto', 'fatura', 'segunda via']);
        }

        if ($this->containsAny($inputLookup, ['nota fiscal', 'nf', 'fiscal'])) {
            return $kind === 'ixc_fiscal_notes_start'
                || $this->containsAny($haystack, ['nota fiscal', 'fiscal']);
        }

        if ($this->containsAny($inputLookup, ['agendamento', 'agendar', 'agenda', 'egndamento', 'agendmento', 'agendemento', 'horario', 'marcar'])) {
            return $kind === 'appointments_start'
                || $this->containsAny($haystack, ['agendamento', 'agenda', 'horario']);
        }

        if ($this->containsAny($inputLookup, ['suporte', 'internet', 'conexao', 'conexão', 'wifi'])) {
            return $this->containsAny($haystack, ['suporte', 'internet', 'conex', 'tecnico']);
        }

        if ($this->containsAny($inputLookup, ['vendas', 'comprar', 'contratar', 'plano'])) {
            return $this->containsAny($haystack, ['vendas', 'comercial', 'contratar', 'plano']);
        }

        return false;
    }

    private function normalizeLookupText(string $value): string
    {
        $normalized = mb_strtolower(trim(strtr($value, [
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
            'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'ç' => 'c', 'ñ' => 'n',
            'Ã¡' => 'a', 'Ã ' => 'a', 'Ã¢' => 'a', 'Ã£' => 'a', 'Ã¤' => 'a',
            'Ã©' => 'e', 'Ã¨' => 'e', 'Ãª' => 'e', 'Ã«' => 'e',
            'Ã­' => 'i', 'Ã¬' => 'i', 'Ã®' => 'i', 'Ã¯' => 'i',
            'Ã³' => 'o', 'Ã²' => 'o', 'Ã´' => 'o', 'Ãµ' => 'o', 'Ã¶' => 'o',
            'Ãº' => 'u', 'Ã¹' => 'u', 'Ã»' => 'u', 'Ã¼' => 'u',
            'Ã§' => 'c', 'Ã±' => 'n',
        ])));

        $normalized = preg_replace('/[^a-z0-9]+/', ' ', $normalized) ?? $normalized;

        return trim((string) preg_replace('/\s+/', ' ', $normalized));
    }

    /**
     * @param  array<int, string>  $needles
     */
    private function containsAny(string $text, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($text, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function isAttendantNaturalInput(string $inputLookup): bool
    {
        return $this->containsAny($inputLookup, ['atendente', 'humano', 'pessoa', 'operador']);
    }
}
