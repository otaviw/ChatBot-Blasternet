<?php

declare(strict_types=1);


namespace App\Services\Bot\Handlers;

use App\Models\Area;
use App\Models\Company;
use App\Models\Conversation;
use App\Support\Enums\BotFlow;

trait BotHandlerHelpers
{
    /**
     * @param  array<string, mixed>  $newState
     * @param  array<string, mixed>|string|null  $replyMessage
     * @return array<string, mixed>
     */
    private function botStateResult(string $replyText, array $newState, array|string|null $replyMessage = null): array
    {
        return [
            'handled'              => true,
            'not_handled'          => false,
            'reply_text'           => $replyText,
            'reply_message'        => $replyMessage ?? $replyText,
            'should_handoff'       => false,
            'handoff_target'       => null,
            'new_state'            => $newState,
            'clear_state'          => false,
            'set_handling_mode'    => 'bot',
            'set_assigned_type'    => 'bot',
            'set_assigned_id'      => null,
            'set_current_area_id'  => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function notHandled(): array
    {
        return [
            'handled'              => false,
            'not_handled'          => true,
            'reply_text'           => null,
            'should_handoff'       => false,
            'handoff_target'       => null,
            'new_state'            => null,
            'clear_state'          => false,
            'set_handling_mode'    => null,
            'set_assigned_type'    => null,
            'set_assigned_id'      => null,
            'set_current_area_id'  => null,
        ];
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
            'handled'              => true,
            'not_handled'          => false,
            'reply_text'           => $replyText,
            'should_handoff'       => true,
            'handoff_target'       => $assignment['handoff_target'],
            'new_state'            => null,
            'clear_state'          => true,
            'set_handling_mode'    => 'human',
            'set_assigned_type'    => $assignment['set_assigned_type'],
            'set_assigned_id'      => $assignment['set_assigned_id'],
            'set_current_area_id'  => $assignment['set_current_area_id'],
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
                        'id'   => (int) $area->id,
                        'name' => (string) $area->name,
                    ],
                    'set_assigned_type'    => 'area',
                    'set_assigned_id'      => (int) $area->id,
                    'set_current_area_id'  => (int) $area->id,
                ];
            }
        }

        return [
            'handoff_target' => $areaLabel === '' ? null : [
                'type' => 'area',
                'id'   => null,
                'name' => $areaLabel,
            ],
            'set_assigned_type'    => 'unassigned',
            'set_assigned_id'      => null,
            'set_current_area_id'  => null,
        ];
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
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    public function buildInitialMenuResponse(array $definition): array
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

        $stateKey    = $this->stateKey($flow, $step);
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

        $context      = [];
        $replyMessage = null;
        if (($initialStep['type'] ?? null) === 'numeric_menu') {
            $context['last_menu_keys'] = array_map(
                static fn($value) => (string) $value,
                array_keys(is_array($initialStep['options'] ?? null) ? $initialStep['options'] : [])
            );
            $replyMessage = $this->buildMenuReplyMessage($initialStep);
        }

        return $this->botStateResult($replyText, [
            'flow'    => $flow,
            'step'    => $step,
            'context' => $context,
        ], $replyMessage);
    }

    /**
     * @param  array<string, mixed>  $stepDefinition
     * @return array<string, mixed>
     */
    private function buildMenuReplyMessage(array $stepDefinition): array
    {
        $replyText  = trim((string) ($stepDefinition['reply_text'] ?? ''));
        $rawOptions = is_array($stepDefinition['options'] ?? null) ? $stepDefinition['options'] : [];

        $mode = trim((string) ($stepDefinition['interaction_mode'] ?? 'auto'));
        if ($mode === 'auto') {
            $mode = count($rawOptions) <= 3 ? 'button' : 'list';
        }

        if ($mode === 'text' || $rawOptions === []) {
            return ['type' => 'text', 'text' => $replyText];
        }

        $headerText  = trim((string) ($stepDefinition['button_header_text'] ?? ''));
        $footerText  = trim((string) ($stepDefinition['button_footer_text'] ?? ''));
        $actionLabel = trim((string) ($stepDefinition['button_action_label'] ?? ''));
        if ($actionLabel === '') {
            $actionLabel = 'Ver opções';
        }

        if ($mode === 'button') {
            $buttons = [];
            foreach ($rawOptions as $optionDef) {
                if (! is_array($optionDef)) {
                    continue;
                }
                $label    = trim((string) ($optionDef['label'] ?? ''));
                $buttonId = trim((string) ($optionDef['button_id'] ?? ''));
                if ($buttonId === '') {
                    $buttonId = $this->slugifyLabel($label);
                }
                $buttons[] = ['id' => $buttonId, 'title' => $label];
            }

            return [
                'type'        => 'interactive_buttons',
                'body_text'   => $replyText,
                'header_text' => $headerText,
                'footer_text' => $footerText,
                'buttons'     => $buttons,
            ];
        }

        $rows = [];
        foreach ($rawOptions as $optionDef) {
            if (! is_array($optionDef)) {
                continue;
            }
            $label    = trim((string) ($optionDef['label'] ?? ''));
            $buttonId = trim((string) ($optionDef['button_id'] ?? ''));
            if ($buttonId === '') {
                $buttonId = $this->slugifyLabel($label);
            }
            $rows[] = ['id' => $buttonId, 'title' => $label, 'description' => ''];
        }

        return [
            'type'         => 'interactive_list',
            'body_text'    => $replyText,
            'header_text'  => $headerText,
            'footer_text'  => $footerText,
            'action_label' => $actionLabel,
            'rows'         => $rows,
        ];
    }

    private function slugifyLabel(string $label): string
    {
        $accents = [
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
            'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'ç' => 'c', 'ñ' => 'n',
        ];
        $normalized = mb_strtolower(trim(strtr($label, $accents)));
        $slug       = preg_replace('/[^a-z0-9]+/', '-', $normalized) ?? '';

        return trim($slug, '-');
    }

    private function stateKey(string $flow, string $step): string
    {
        return "{$flow}.{$step}";
    }

    private function nullableContextEmail(mixed $value): ?string
    {
        $email = trim((string) ($value ?? ''));

        return ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) ? $email : null;
    }
}
