<?php

namespace App\Services\Bot;

use App\Models\Company;
use App\Models\Conversation;
use App\Services\Bot\Handlers\AppointmentFlowHandler;
use App\Services\Bot\Handlers\GeneralMenuFlowHandler;
use App\Support\Enums\BotFlow;

class StatefulBotService
{
    public function __construct(
        private BotFlowRegistry $registry,
        private AppointmentFlowHandler $appointmentHandler,
        private GeneralMenuFlowHandler $generalMenuHandler,
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

        if ($this->isCancelCommand($normalizedText)) {
            return $this->appointmentHandler->startCancellation($company, $conversation);
        }

        return $this->generalMenuHandler->handleStep($company, $conversation, $definition, $flow, $step, $normalizedText);
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
}
