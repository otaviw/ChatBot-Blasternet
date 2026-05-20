<?php

declare(strict_types=1);


namespace App\Services\Bot;

use App\Models\Company;
use App\Models\Conversation;
use App\Services\Bot\Handlers\AppointmentFlowHandler;
use App\Services\Bot\Handlers\GeneralMenuFlowHandler;
use App\Services\Bot\Handlers\IxcFiscalNoteFlowHandler;
use App\Services\Bot\Handlers\IxcInvoiceFlowHandler;
use App\Support\Enums\BotFlow;

class StatefulBotService
{
    public function __construct(
        private BotFlowRegistry $registry,
        private AppointmentFlowHandler $appointmentHandler,
        private GeneralMenuFlowHandler $generalMenuHandler,
        private IxcInvoiceFlowHandler $ixcInvoiceHandler,
        private IxcFiscalNoteFlowHandler $ixcFiscalNoteHandler,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(
        ?Company $company,
        Conversation $conversation,
        string $inputText,
        bool $isFirstInboundMessage,
        bool $sendOutbound = true,
    ): array {
        unset($isFirstInboundMessage);

        $definition    = $this->registry->definitionForCompany($company);
        $normalizedText = trim($inputText);

        if ($this->isMenuCommand($normalizedText, $definition['commands'] ?? [])) {
            return $this->generalMenuHandler->buildInitialMenuResponse($definition);
        }

        $flow = is_string($conversation->bot_flow) ? trim($conversation->bot_flow) : '';
        $step = is_string($conversation->bot_step) ? trim($conversation->bot_step) : '';
        if ($flow === '' || $step === '') {
            return $this->generalMenuHandler->buildInitialMenuResponse($definition);
        }

        if ($flow === BotFlow::APPOINTMENTS->value) {
            return $this->appointmentHandler->handle($company, $conversation, $step, $normalizedText);
        }

        if ($flow === BotFlow::CANCEL_APPOINTMENT->value) {
            return $this->appointmentHandler->handleCancellation($company, $conversation, $step, $normalizedText);
        }

        if ($flow === BotFlow::IXC_INVOICES->value) {
            $context = is_array($conversation->bot_context) ? $conversation->bot_context : [];
            if (($context['mode'] ?? null) === 'fiscal_note') {
                return $this->ixcFiscalNoteHandler->handle($company, $conversation, $step, $normalizedText, $sendOutbound);
            }
            return $this->ixcInvoiceHandler->handle($company, $conversation, $step, $normalizedText, $sendOutbound);
        }

        if ($this->isCancelCommand($normalizedText)) {
            return $this->appointmentHandler->startCancellation($company, $conversation);
        }

        return $this->generalMenuHandler->handleStep($company, $conversation, $definition, $flow, $step, $normalizedText);
    }

    /**
     * Tenta converter uma intencao classificada pela IA em uma opcao real do menu atual.
     *
     * @return array<string, mixed>|null
     */
    public function handleAiResolvedMenuAction(
        ?Company $company,
        Conversation $conversation,
        string $intent,
        string $messageText
    ): ?array {
        $definition = $this->registry->definitionForCompany($company);
        $context = $this->currentMenuContext($conversation, $definition);
        if ($context === null) {
            return null;
        }

        $optionKey = $this->resolveOptionKeyForIntent($context['step_definition'], $intent);
        if ($optionKey === null) {
            return null;
        }

        return $this->generalMenuHandler->handleStep(
            $company,
            $conversation,
            $definition,
            $context['flow'],
            $context['step'],
            $optionKey,
            ['ai_message_text' => $messageText]
        );
    }

    public function hasDirectAttendantHandoffOption(
        ?Company $company,
        Conversation $conversation
    ): bool {
        $definition = $this->registry->definitionForCompany($company);
        $context = $this->currentMenuContext($conversation, $definition);
        if ($context === null) {
            return false;
        }

        return $this->resolveOptionKeyForIntent(
            $context['step_definition'],
            'falar_com_atendente'
        ) !== null;
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

    private function isCancelCommand(string $text): bool
    {
        $accents = [
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a',
            'é' => 'e', 'ê' => 'e',
            'í' => 'i', 'ó' => 'o', 'ô' => 'o', 'ú' => 'u',
            'ç' => 'c',
        ];
        $n = mb_strtolower(trim(strtr($text, $accents)));

        return in_array($n, ['cancelar', 'cancelar agendamento', 'cancela', 'cancela agendamento'], true);
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array{flow:string,step:string,step_definition:array<string,mixed>}|null
     */
    private function currentMenuContext(Conversation $conversation, array $definition): ?array
    {
        $flow = is_string($conversation->bot_flow) ? trim($conversation->bot_flow) : '';
        $step = is_string($conversation->bot_step) ? trim($conversation->bot_step) : '';

        if ($flow === '' || $step === '') {
            $initial = is_array($definition['initial'] ?? null) ? $definition['initial'] : [];
            $flow = trim((string) ($initial['flow'] ?? ''));
            $step = trim((string) ($initial['step'] ?? ''));
        }

        if ($flow === '' || $step === '') {
            return null;
        }

        $stateKey = "{$flow}.{$step}";
        $stepDefinition = is_array($definition['steps'][$stateKey] ?? null)
            ? $definition['steps'][$stateKey]
            : null;

        if (! is_array($stepDefinition) || ($stepDefinition['type'] ?? null) !== 'numeric_menu') {
            return null;
        }

        return [
            'flow' => $flow,
            'step' => $step,
            'step_definition' => $stepDefinition,
        ];
    }

    /**
     * @param  array<string, mixed>  $stepDefinition
     */
    private function resolveOptionKeyForIntent(array $stepDefinition, string $intent): ?string
    {
        $options = is_array($stepDefinition['options'] ?? null) ? $stepDefinition['options'] : [];
        if ($options === []) {
            return null;
        }

        foreach ($options as $optionKey => $optionDefinition) {
            if (! is_array($optionDefinition)) {
                continue;
            }

            $action = is_array($optionDefinition['action'] ?? null) ? $optionDefinition['action'] : [];
            $label = (string) ($optionDefinition['label'] ?? '');

            if ($this->optionMatchesIntent($intent, $label, $action)) {
                return (string) $optionKey;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $action
     */
    private function optionMatchesIntent(string $intent, string $label, array $action): bool
    {
        $normalizedIntent = mb_strtolower(trim($intent));
        $kind = mb_strtolower(trim((string) ($action['kind'] ?? '')));
        $targetArea = (string) ($action['target_area_name'] ?? '');
        $haystack = $this->normalizeLookupText($label.' '.$targetArea.' '.$kind);

        if ($normalizedIntent === 'falar_com_atendente') {
            return $kind === 'handoff'
                && (
                    str_contains($haystack, 'atendente')
                    || str_contains($haystack, 'atendimento')
                    || str_contains($haystack, 'humano')
                );
        }

        if (in_array($normalizedIntent, ['agendamento', 'remarcar_agendamento'], true)) {
            return $kind === 'appointments_start'
                || str_contains($haystack, 'agendamento')
                || str_contains($haystack, 'agenda');
        }

        if ($normalizedIntent === 'cancelar_agendamento') {
            return $kind === 'appointments_cancel'
                || str_contains($haystack, 'cancelar agendamento')
                || str_contains($haystack, 'cancelamento');
        }

        if ($normalizedIntent === 'financeiro') {
            return in_array($kind, ['ixc_invoices_start', 'ixc_fiscal_notes_start'], true)
                || str_contains($haystack, 'financeiro')
                || str_contains($haystack, 'boleto')
                || str_contains($haystack, 'fiscal')
                || str_contains($haystack, 'nota');
        }

        if ($normalizedIntent === 'suporte_tecnico') {
            return str_contains($haystack, 'suporte')
                || str_contains($haystack, 'internet')
                || str_contains($haystack, 'conex')
                || str_contains($haystack, 'tecnico');
        }

        return false;
    }

    private function normalizeLookupText(string $value): string
    {
        $normalized = mb_strtolower(trim($value));
        $normalized = strtr($normalized, [
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a',
            'é' => 'e', 'ê' => 'e',
            'í' => 'i',
            'ó' => 'o', 'ô' => 'o', 'õ' => 'o',
            'ú' => 'u',
            'ç' => 'c',
        ]);

        return preg_replace('/\s+/', ' ', $normalized) ?? $normalized;
    }
}
