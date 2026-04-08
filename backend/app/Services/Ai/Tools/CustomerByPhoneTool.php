<?php

namespace App\Services\Ai\Tools;

use App\Models\Conversation;
use App\Support\PhoneNumberNormalizer;

class CustomerByPhoneTool implements AiToolInterface
{
    public function getName(): string
    {
        return 'get_customer_by_phone';
    }

    public function getDescription(): string
    {
        return 'Busca cliente da empresa pelo telefone informado.';
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function execute(array $params): array
    {
        $companyId = (int) ($params['company_id'] ?? 0);
        $phone = PhoneNumberNormalizer::normalizeBrazil((string) ($params['phone'] ?? ''));

        if ($companyId <= 0 || $phone === '') {
            return [
                'found' => false,
                'name' => null,
                'plan' => null,
            ];
        }

        $phoneVariants = PhoneNumberNormalizer::variantsForLookup($phone);

        $conversation = Conversation::query()
            ->where('company_id', $companyId)
            ->whereIn('customer_phone', $phoneVariants !== [] ? $phoneVariants : [$phone])
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->first(['id', 'company_id', 'customer_name', 'bot_context']);

        if (! $conversation) {
            return [
                'found' => false,
                'name' => null,
                'plan' => null,
            ];
        }

        $customerName = trim((string) ($conversation->customer_name ?? ''));
        $plan = $this->resolvePlanFromBotContext($conversation->bot_context);

        return [
            'found' => true,
            'name' => $customerName !== '' ? $customerName : null,
            'plan' => $plan,
        ];
    }

    /**
     * @param  mixed  $botContext
     */
    private function resolvePlanFromBotContext(mixed $botContext): ?string
    {
        if (! is_array($botContext)) {
            return null;
        }

        $candidateKeys = ['plan', 'plano', 'customer_plan', 'customerPlan'];

        foreach ($candidateKeys as $key) {
            $value = trim((string) ($botContext[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }
}
