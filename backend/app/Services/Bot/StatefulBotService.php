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
            $initialRoute = $this->tryRouteNaturalMenuInput($company, $conversation, $definition, $normalizedText);
            if ($initialRoute !== null) {
                return $initialRoute;
            }

            return $this->generalMenuHandler->buildInitialMenuResponse($definition);
        }

        if (in_array($flow, [BotFlow::APPOINTMENTS->value, BotFlow::CANCEL_APPOINTMENT->value, BotFlow::IXC_INVOICES->value], true)) {
            $menuRoute = $this->tryRouteNaturalMenuInput($company, $conversation, $definition, $normalizedText);
            if ($menuRoute !== null) {
                return $menuRoute;
            }
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

        if (! $this->isDirectHandoffIntent($intent) && $this->isSpecificFinancialRequest($intent, $messageText)) {
            $globalMatch = $this->findMenuMatchForIntent($definition, $intent, $messageText);
            if ($globalMatch !== null) {
                return $this->generalMenuHandler->handleStep(
                    $company,
                    $conversation,
                    $definition,
                    $globalMatch['flow'],
                    $globalMatch['step'],
                    $globalMatch['option_key'],
                    ['ai_message_text' => $messageText]
                );
            }
        }

        if ($context !== null) {
            $optionKey = $this->resolveOptionKeyForIntent($context['step_definition'], $intent, $messageText);
            if ($optionKey !== null) {
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
        }

        if ($this->isDirectHandoffIntent($intent)) {
            return null;
        }

        $initialContext = $this->initialMenuContext($definition);
        if ($initialContext !== null) {
            $optionKey = $this->resolveOptionKeyForIntent($initialContext['step_definition'], $intent, $messageText);
            if ($optionKey !== null) {
                return $this->generalMenuHandler->handleStep(
                    $company,
                    $conversation,
                    $definition,
                    $initialContext['flow'],
                    $initialContext['step'],
                    $optionKey,
                    ['ai_message_text' => $messageText]
                );
            }
        }

        $globalMatch = $this->findMenuMatchForIntent($definition, $intent, $messageText);
        if ($globalMatch === null) {
            return null;
        }

        return $this->generalMenuHandler->handleStep(
            $company,
            $conversation,
            $definition,
            $globalMatch['flow'],
            $globalMatch['step'],
            $globalMatch['option_key'],
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
        $normalized = $this->normalizeLookupText($inputText);
        $compact = preg_replace('/\s+/', '', $normalized) ?? '';

        if ($compact === '') {
            return false;
        }

        $normalizedCommands = array_map(
            fn (string $command): string => preg_replace('/\s+/', '', $this->normalizeLookupText($command)) ?? '',
            $commands
        );

        if (in_array($compact, array_filter($normalizedCommands), true)) {
            return true;
        }

        return in_array($compact, ['#', 'menu', 'inicio', 'iniciar', 'comecar', 'começar'], true)
            || str_contains($normalized, 'voltar para o inicio')
            || str_contains($normalized, 'voltar pro inicio')
            || str_contains($normalized, 'voltar ao inicio')
            || str_contains($normalized, 'voltar para o menu')
            || str_contains($normalized, 'voltar pro menu')
            || str_contains($normalized, 'voltar ao menu')
            || str_contains($normalized, 'menu principal')
            || str_contains($normalized, 'comecar de novo')
            || str_contains($normalized, 'começar de novo');
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
     * @return array<string, mixed>|null
     */
    private function tryRouteNaturalMenuInput(
        ?Company $company,
        Conversation $conversation,
        array $definition,
        string $inputText
    ): ?array {
        if (! $this->looksLikeNaturalMenuRoute($inputText)) {
            return null;
        }

        $initial = is_array($definition['initial'] ?? null) ? $definition['initial'] : [];
        $flow = trim((string) ($initial['flow'] ?? ''));
        $step = trim((string) ($initial['step'] ?? ''));
        if ($flow === '' || $step === '') {
            return null;
        }

        $result = $this->generalMenuHandler->handleStep($company, $conversation, $definition, $flow, $step, $inputText);
        if (! (bool) ($result['handled'] ?? false)) {
            return null;
        }

        $reply = $this->normalizeLookupText((string) ($result['reply_text'] ?? ''));
        if ($reply === '' || str_contains($reply, 'opcao invalida') || str_contains($reply, 'opção inválida')) {
            return null;
        }

        return $result;
    }

    private function looksLikeNaturalMenuRoute(string $inputText): bool
    {
        $normalized = $this->normalizeLookupText($inputText);
        if ($normalized === '' || preg_match('/^\d+$/', $normalized) === 1) {
            return false;
        }

        return $this->containsAny($normalized, [
            'financeiro',
            'boleto',
            'fatura',
            'segunda via',
            'pagamento',
            'nota fiscal',
            'fiscal',
            'agendamento',
            'agendar',
            'agenda',
            'egndamento',
            'agendmento',
            'horario',
            'marcar',
            'suporte',
            'internet',
            'conexao',
            'wifi',
            'vendas',
            'comprar',
            'contratar',
            'plano',
        ]);
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
     * @param  array<string, mixed>  $definition
     * @return array{flow:string,step:string,step_definition:array<string,mixed>}|null
     */
    private function initialMenuContext(array $definition): ?array
    {
        $initial = is_array($definition['initial'] ?? null) ? $definition['initial'] : [];
        $flow = trim((string) ($initial['flow'] ?? ''));
        $step = trim((string) ($initial['step'] ?? ''));

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
    private function resolveOptionKeyForIntent(array $stepDefinition, string $intent, string $messageText = ''): ?string
    {
        $options = is_array($stepDefinition['options'] ?? null) ? $stepDefinition['options'] : [];
        if ($options === []) {
            return null;
        }

        $bestKey = null;
        $bestScore = 0;

        foreach ($options as $optionKey => $optionDefinition) {
            if (! is_array($optionDefinition)) {
                continue;
            }

            $action = is_array($optionDefinition['action'] ?? null) ? $optionDefinition['action'] : [];
            $label = (string) ($optionDefinition['label'] ?? '');
            $score = $this->optionIntentScore($intent, $label, $action, $messageText);

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestKey = (string) $optionKey;
            }
        }

        return $bestScore > 0 ? $bestKey : null;
    }

    /**
     * @param  array<string, mixed>  $action
     */
    private function optionMatchesIntent(string $intent, string $label, array $action): bool
    {
        return $this->optionIntentScore($intent, $label, $action, '') > 0;
    }

    /**
     * @param  array<string, mixed>  $action
     */
    private function optionIntentScore(string $intent, string $label, array $action, string $messageText): int
    {
        $normalizedIntent = mb_strtolower(trim($intent));
        $kind = mb_strtolower(trim((string) ($action['kind'] ?? '')));
        $targetArea = (string) ($action['target_area_name'] ?? '');
        $haystack = $this->normalizeLookupText($label.' '.$targetArea.' '.$kind);
        $message = $this->normalizeLookupText($messageText);

        if ($normalizedIntent === 'falar_com_atendente') {
            return $kind === 'handoff'
                && (
                    str_contains($haystack, 'atendente')
                    || str_contains($haystack, 'atendimento')
                    || str_contains($haystack, 'humano')
                )
                ? 100
                : 0;
        }

        if (in_array($normalizedIntent, ['agendamento', 'remarcar_agendamento'], true)) {
            return $kind === 'appointments_start'
                || str_contains($haystack, 'agendamento')
                || str_contains($haystack, 'agenda')
                || str_contains($haystack, 'horario')
                ? 100
                : 0;
        }

        if ($normalizedIntent === 'cancelar_agendamento') {
            return $kind === 'appointments_cancel'
                || str_contains($haystack, 'cancelar agendamento')
                || str_contains($haystack, 'cancelamento')
                ? 100
                : 0;
        }

        if ($normalizedIntent === 'financeiro') {
            $asksInvoice = $this->containsAny($message, ['boleto', 'fatura', 'segunda via', 'pagamento']);
            $asksFiscalNote = $this->containsAny($message, ['nota fiscal', 'nf', 'fiscal']);

            if ($asksInvoice && ($kind === 'ixc_invoices_start' || $this->containsAny($haystack, ['boleto', 'fatura', 'segunda via']))) {
                return 120;
            }

            if ($asksFiscalNote && ($kind === 'ixc_fiscal_notes_start' || $this->containsAny($haystack, ['nota fiscal', 'fiscal']))) {
                return 120;
            }

            if (in_array($kind, ['ixc_invoices_start', 'ixc_fiscal_notes_start'], true)) {
                return 90;
            }

            if ($this->containsAny($haystack, ['financeiro', 'boleto', 'fatura', 'fiscal', 'nota'])) {
                return 70;
            }

            return 0;
        }

        if ($normalizedIntent === 'suporte_tecnico') {
            return str_contains($haystack, 'suporte')
                || str_contains($haystack, 'internet')
                || str_contains($haystack, 'conex')
                || str_contains($haystack, 'tecnico')
                ? 100
                : 0;
        }

        return 0;
    }

    private function isDirectHandoffIntent(string $intent): bool
    {
        return mb_strtolower(trim($intent)) === 'falar_com_atendente';
    }

    private function isSpecificFinancialRequest(string $intent, string $messageText): bool
    {
        if (mb_strtolower(trim($intent)) !== 'financeiro') {
            return false;
        }

        return $this->containsAny(
            $this->normalizeLookupText($messageText),
            ['boleto', 'fatura', 'segunda via', 'pagamento', 'nota fiscal', 'nf', 'fiscal']
        );
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array{flow:string,step:string,option_key:string}|null
     */
    private function findMenuMatchForIntent(array $definition, string $intent, string $messageText): ?array
    {
        $steps = is_array($definition['steps'] ?? null) ? $definition['steps'] : [];
        $bestMatch = null;
        $bestScore = 0;

        foreach ($steps as $stateKey => $stepDefinition) {
            if (! is_string($stateKey) || ! str_contains($stateKey, '.') || ! is_array($stepDefinition)) {
                continue;
            }

            if (($stepDefinition['type'] ?? null) !== 'numeric_menu') {
                continue;
            }

            [$flow, $step] = explode('.', $stateKey, 2);
            $options = is_array($stepDefinition['options'] ?? null) ? $stepDefinition['options'] : [];
            foreach ($options as $optionKey => $optionDefinition) {
                if (! is_array($optionDefinition)) {
                    continue;
                }

                $action = is_array($optionDefinition['action'] ?? null) ? $optionDefinition['action'] : [];
                $label = (string) ($optionDefinition['label'] ?? '');
                $score = $this->optionIntentScore($intent, $label, $action, $messageText);

                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestMatch = [
                        'flow' => $flow,
                        'step' => $step,
                        'option_key' => (string) $optionKey,
                    ];
                }
            }
        }

        return $bestScore > 0 ? $bestMatch : null;
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
