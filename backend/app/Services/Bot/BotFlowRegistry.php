<?php

namespace App\Services\Bot;

use App\Models\AppointmentService;
use App\Models\Company;
use App\Support\Enums\BotFlow;

class BotFlowRegistry
{
    public const FLOW_MAIN    = BotFlow::MAIN->value;
    public const FLOW_SUPPORT = BotFlow::SUPPORT->value;

    public const STEP_MENU            = 'menu';
    public const STEP_ISSUE_MENU      = 'issue_menu';
    public const STEP_FREE_TEXT_ISSUE = 'free_text_issue';

    public const AREA_SUPPORT = 'Suporte';
    public const AREA_SALES = 'Vendas';
    public const AREA_ATTENDANCE = 'Atendimento';

    /**
     * @return array{
     *   commands: array<int, string>,
     *   initial: array{flow:string,step:string},
     *   steps: array<string, array<string, mixed>>
     * }
     */
    public function definitionForCompany(?Company $company): array
    {
        $default = $this->defaultDefinition($company);
        $raw = $company?->botSetting?->stateful_menu_flow;

        if (! is_array($raw)) {
            return $default;
        }

        $normalized = $this->normalizeDefinition($raw);

        return $normalized ?? $default;
    }

    public function mainMenuText(): string
    {
        return "Olá! O que você precisa?\n1 - Suporte técnico\n2 - Vendas\n3 - Falar com atendente";
    }

    public function supportIssueMenuText(): string
    {
        return "Suporte técnico. Qual o problema?\n1 - Internet lenta\n2 - Sem conexão\n3 - Outro";
    }

    public function supportFreeTextPrompt(): string
    {
        return 'Beleza. Me descreve o problema em uma frase.';
    }

    /**
     * @param  array<int, string>  $options
     */
    public function invalidOptionText(array $options): string
    {
        $labels = implode(', ', $options);

        return "Opção inválida. Responda com {$labels}... ou \"menu\" para voltar ao menu principal.";
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array{
     *   commands: array<int, string>,
     *   initial: array{flow:string,step:string},
     *   steps: array<string, array<string, mixed>>
     * }|null
     */
    private function normalizeDefinition(array $raw): ?array
    {
        $rawSteps = $raw['steps'] ?? null;
        if (! is_array($rawSteps) || $rawSteps === []) {
            return null;
        }

        $steps = [];
        foreach ($rawSteps as $stateKey => $stepDefinition) {
            $key = trim((string) $stateKey);
            if ($key === '' || ! is_array($stepDefinition)) {
                continue;
            }

            $normalizedStep = $this->normalizeStepDefinition($stepDefinition);
            if (! is_array($normalizedStep)) {
                continue;
            }

            $steps[$key] = $normalizedStep;
        }

        if ($steps === []) {
            return null;
        }

        if (! $this->validateStepTargets($steps)) {
            return null;
        }

        $commands = $this->normalizeCommands($raw['commands'] ?? null);
        $initial = $this->normalizeInitial($raw['initial'] ?? null, $steps);

        return [
            'commands' => $commands,
            'initial' => $initial,
            'steps' => $steps,
        ];
    }

    /**
     * @param  array<string, mixed>  $step
     * @return array<string, mixed>|null
     */
    private function normalizeStepDefinition(array $step): ?array
    {
        $type = mb_strtolower(trim((string) ($step['type'] ?? '')));
        $replyText = trim((string) ($step['reply_text'] ?? ''));
        if ($replyText === '') {
            return null;
        }

        if ($type === 'numeric_menu') {
            $rawOptions = $step['options'] ?? null;
            if (! is_array($rawOptions) || $rawOptions === []) {
                return null;
            }

            $options = [];
            foreach ($rawOptions as $optionKey => $optionConfig) {
                $key = trim((string) $optionKey);
                if ($key === '' || ! preg_match('/^\d+$/', $key) || ! is_array($optionConfig)) {
                    continue;
                }

                $action = $this->normalizeAction($optionConfig['action'] ?? null);
                if (! is_array($action)) {
                    continue;
                }

                $options[$key] = [
                    'label' => trim((string) ($optionConfig['label'] ?? '')),
                    'action' => $action,
                ];
            }

            if ($options === []) {
                return null;
            }

            $invalidOptionText = trim((string) ($step['invalid_option_text'] ?? ''));

            return [
                'type' => 'numeric_menu',
                'reply_text' => $replyText,
                'invalid_option_text' => $invalidOptionText === '' ? null : $invalidOptionText,
                'options' => $options,
            ];
        }

        if ($type === 'free_text') {
            $onText = $this->normalizeAction($step['on_text'] ?? null);
            if (! is_array($onText)) {
                return null;
            }

            $emptyInputReplyText = trim((string) ($step['empty_input_reply_text'] ?? ''));

            return [
                'type' => 'free_text',
                'reply_text' => $replyText,
                'empty_input_reply_text' => $emptyInputReplyText === '' ? $replyText : $emptyInputReplyText,
                'on_text' => $onText,
            ];
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function normalizeAction(mixed $raw): ?array
    {
        if (! is_array($raw)) {
            return null;
        }

        $kind = mb_strtolower(trim((string) ($raw['kind'] ?? '')));
        if ($kind === 'go_to') {
            $flow = trim((string) ($raw['flow'] ?? ''));
            $step = trim((string) ($raw['step'] ?? ''));
            if ($flow === '' || $step === '') {
                return null;
            }

            $replyText = trim((string) ($raw['reply_text'] ?? ''));

            return [
                'kind' => 'go_to',
                'flow' => $flow,
                'step' => $step,
                'reply_text' => $replyText === '' ? null : $replyText,
            ];
        }

        if ($kind === 'handoff') {
            $targetAreaName = trim((string) ($raw['target_area_name'] ?? ''));
            if ($targetAreaName === '') {
                return null;
            }

            $replyText = trim((string) ($raw['reply_text'] ?? ''));

            return [
                'kind' => 'handoff',
                'target_area_name' => $targetAreaName,
                'reply_text' => $replyText === '' ? null : $replyText,
            ];
        }

        if ($kind === 'appointments_start') {
            $targetAreaName = trim((string) ($raw['target_area_name'] ?? self::AREA_ATTENDANCE));
            $replyText = trim((string) ($raw['reply_text'] ?? ''));

            return [
                'kind' => 'appointments_start',
                'target_area_name' => $targetAreaName !== '' ? $targetAreaName : self::AREA_ATTENDANCE,
                'reply_text' => $replyText === '' ? null : $replyText,
            ];
        }

        if ($kind === 'appointments_cancel') {
            $replyText = trim((string) ($raw['reply_text'] ?? ''));

            return [
                'kind' => 'appointments_cancel',
                'reply_text' => $replyText === '' ? null : $replyText,
            ];
        }

        return null;
    }

    /**
     * @param  array<string, array<string, mixed>>  $steps
     */
    private function validateStepTargets(array $steps): bool
    {
        foreach ($steps as $stepDefinition) {
            $type = $stepDefinition['type'] ?? null;
            if ($type === 'numeric_menu') {
                foreach (($stepDefinition['options'] ?? []) as $optionDefinition) {
                    $action = is_array($optionDefinition['action'] ?? null) ? $optionDefinition['action'] : null;
                    if (! is_array($action)) {
                        return false;
                    }

                    if (($action['kind'] ?? null) !== 'go_to') {
                        continue;
                    }

                    $targetStateKey = $this->stateKey(
                        (string) ($action['flow'] ?? ''),
                        (string) ($action['step'] ?? '')
                    );

                    if (! isset($steps[$targetStateKey])) {
                        return false;
                    }
                }

                continue;
            }

            if ($type !== 'free_text') {
                return false;
            }

            $onText = is_array($stepDefinition['on_text'] ?? null) ? $stepDefinition['on_text'] : null;
            if (! is_array($onText)) {
                return false;
            }

            if (($onText['kind'] ?? null) !== 'go_to') {
                continue;
            }

            $targetStateKey = $this->stateKey(
                (string) ($onText['flow'] ?? ''),
                (string) ($onText['step'] ?? '')
            );

            if (! isset($steps[$targetStateKey])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  mixed  $rawCommands
     * @return array<int, string>
     */
    private function normalizeCommands(mixed $rawCommands): array
    {
        if (! is_array($rawCommands)) {
            return ['#', 'menu'];
        }

        $normalized = [];
        $seen = [];
        foreach ($rawCommands as $command) {
            $token = preg_replace('/\s+/', '', mb_strtolower(trim((string) $command))) ?? '';
            if ($token === '') {
                continue;
            }

            if (isset($seen[$token])) {
                continue;
            }
            $seen[$token] = true;
            $normalized[] = $token;
        }

        return $normalized === [] ? ['#', 'menu'] : $normalized;
    }

    /**
     * @param  mixed  $rawInitial
     * @param  array<string, array<string, mixed>>  $steps
     * @return array{flow:string,step:string}
     */
    private function normalizeInitial(mixed $rawInitial, array $steps): array
    {
        $flow = is_array($rawInitial) ? trim((string) ($rawInitial['flow'] ?? '')) : '';
        $step = is_array($rawInitial) ? trim((string) ($rawInitial['step'] ?? '')) : '';

        $candidateKey = $this->stateKey($flow, $step);
        if ($flow !== '' && $step !== '' && isset($steps[$candidateKey])) {
            return [
                'flow' => $flow,
                'step' => $step,
            ];
        }

        $firstKey = array_key_first($steps);
        if (! is_string($firstKey) || ! str_contains($firstKey, '.')) {
            return [
                'flow' => self::FLOW_MAIN,
                'step' => self::STEP_MENU,
            ];
        }

        [$firstFlow, $firstStep] = explode('.', $firstKey, 2);

        return [
            'flow' => $firstFlow,
            'step' => $firstStep,
        ];
    }

    /**
     * @return array{
     *   commands: array<int, string>,
     *   initial: array{flow:string,step:string},
     *   steps: array<string, array<string, mixed>>
     * }
     */
    private function defaultDefinition(?Company $company = null): array
    {
        $mainMenuReply = $this->defaultMainMenuReply($company);
        $appointmentsEnabled = $this->hasActiveAppointments($company);

        $steps = [
            $this->stateKey(self::FLOW_MAIN, self::STEP_MENU) => [
                'type' => 'numeric_menu',
                'reply_text' => $mainMenuReply,
                'invalid_option_text' => null,
                'options' => [
                    '1' => [
                        'label' => 'Suporte técnico',
                        'action' => [
                            'kind' => 'go_to',
                            'flow' => self::FLOW_SUPPORT,
                            'step' => self::STEP_ISSUE_MENU,
                            'reply_text' => null,
                        ],
                    ],
                    '2' => [
                        'label' => 'Vendas',
                        'action' => [
                            'kind' => 'handoff',
                            'target_area_name' => self::AREA_SALES,
                            'reply_text' => 'Perfeito. Vou te encaminhar para Vendas.',
                        ],
                    ],
                    '3' => [
                        'label' => 'Falar com atendente',
                        'action' => [
                            'kind' => 'handoff',
                            'target_area_name' => self::AREA_ATTENDANCE,
                            'reply_text' => 'Certo. Vou te encaminhar para um atendente.',
                        ],
                    ],
                ],
            ],
            $this->stateKey(self::FLOW_SUPPORT, self::STEP_ISSUE_MENU) => [
                'type' => 'numeric_menu',
                'reply_text' => $this->supportIssueMenuText(),
                'invalid_option_text' => null,
                'options' => [
                    '1' => [
                        'label' => 'Internet lenta',
                        'action' => [
                            'kind' => 'handoff',
                            'target_area_name' => self::AREA_SUPPORT,
                            'reply_text' => 'Entendi: internet lenta. Vou te encaminhar para o Suporte.',
                        ],
                    ],
                    '2' => [
                        'label' => 'Sem conexão',
                        'action' => [
                            'kind' => 'handoff',
                            'target_area_name' => self::AREA_SUPPORT,
                            'reply_text' => 'Entendi: sem conexão. Vou te encaminhar para o Suporte.',
                        ],
                    ],
                    '3' => [
                        'label' => 'Outro',
                        'action' => [
                            'kind' => 'go_to',
                            'flow' => self::FLOW_SUPPORT,
                            'step' => self::STEP_FREE_TEXT_ISSUE,
                            'reply_text' => null,
                        ],
                    ],
                ],
            ],
            $this->stateKey(self::FLOW_SUPPORT, self::STEP_FREE_TEXT_ISSUE) => [
                'type' => 'free_text',
                'reply_text' => $this->supportFreeTextPrompt(),
                'empty_input_reply_text' => $this->supportFreeTextPrompt(),
                'on_text' => [
                    'kind' => 'handoff',
                    'target_area_name' => self::AREA_SUPPORT,
                    'reply_text' => 'Perfeito, vou encaminhar sua descrição para o Suporte.',
                ],
            ],
        ];

        if ($appointmentsEnabled) {
            $steps[$this->stateKey(self::FLOW_MAIN, self::STEP_MENU)]['options']['4'] = [
                'label' => 'Marcar agendamento',
                'action' => [
                    'kind' => 'appointments_start',
                    'target_area_name' => self::AREA_ATTENDANCE,
                    'reply_text' => null,
                ],
            ];
            $steps[$this->stateKey(self::FLOW_MAIN, self::STEP_MENU)]['options']['5'] = [
                'label' => 'Cancelar agendamento',
                'action' => [
                    'kind' => 'appointments_cancel',
                    'reply_text' => null,
                ],
            ];
        }

        return [
            'commands' => ['#', 'menu'],
            'initial' => [
                'flow' => self::FLOW_MAIN,
                'step' => self::STEP_MENU,
            ],
            'steps' => $steps,
        ];
    }

    private function defaultMainMenuReply(?Company $company): string
    {
        $welcome = trim((string) ($company?->botSetting?->welcome_message ?? ''));
        $appointmentsEnabled = $this->hasActiveAppointments($company);
        $appointmentsLine = $appointmentsEnabled ? "\n4 - Marcar agendamento\n5 - Cancelar agendamento" : '';
        if ($welcome === '') {
            return "Olá! O que você precisa?\n1 - Suporte técnico\n2 - Vendas\n3 - Falar com atendente{$appointmentsLine}";
        }

        return "{$welcome}\n1 - Suporte técnico\n2 - Vendas\n3 - Falar com atendente{$appointmentsLine}";
    }

    private function hasActiveAppointments(?Company $company): bool
    {
        if (! $company?->id) {
            return false;
        }

        return AppointmentService::query()
            ->where('company_id', (int) $company->id)
            ->where('is_active', true)
            ->exists();
    }

    private function stateKey(string $flow, string $step): string
    {
        return "{$flow}.{$step}";
    }
}


