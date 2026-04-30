<?php

declare(strict_types=1);


namespace App\Services\Ai;

use App\Models\AiAuditLog;
use Illuminate\Support\Facades\Log;
use Throwable;

class AiAuditService
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function logAction(
        int $companyId,
        ?int $userId,
        ?int $conversationId,
        string $action,
        array $metadata = []
    ): void {
        if ($companyId <= 0) {
            return;
        }

        $normalizedAction = $this->normalizeAction($action);

        try {
            AiAuditLog::query()->create([
                'company_id' => $companyId,
                'user_id' => $userId,
                'conversation_id' => $conversationId,
                'action' => $normalizedAction,
                'metadata' => $metadata,
                'created_at' => now(),
            ]);
        } catch (Throwable $exception) {
            Log::warning('ai.audit.log_failed', [
                'company_id' => $companyId,
                'user_id' => $userId,
                'conversation_id' => $conversationId,
                'action' => $normalizedAction,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function logMessageSent(
        int $companyId,
        ?int $userId,
        ?int $conversationId,
        array $metadata = []
    ): void {
        $this->logAction($companyId, $userId, $conversationId, AiAuditLog::ACTION_MESSAGE_SENT, $metadata);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function logToolExecuted(
        int $companyId,
        ?int $userId,
        ?int $conversationId,
        array $metadata = []
    ): void {
        $this->logAction($companyId, $userId, $conversationId, AiAuditLog::ACTION_TOOL_EXECUTED, $metadata);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function logToolFailed(
        int $companyId,
        ?int $userId,
        ?int $conversationId,
        array $metadata = []
    ): void {
        $this->logAction($companyId, $userId, $conversationId, AiAuditLog::ACTION_TOOL_FAILED, $metadata);
    }

    /**
     * Registra bloqueio pelo pipeline de segurança.
     *
     * Metadados esperados (sem conteúdo de mensagem — apenas metadados):
     *   stage    — etapa que bloqueou (ex.: 'prompt_injection')
     *   reason   — chave do motivo (ex.: 'prompt_injection:jailbreak')
     *   flags    — array de flags detectadas
     *   feature  — feature de origem (internal_chat | conversation_suggestion | chatbot)
     *
     * @param  array<string, mixed>  $metadata
     */
    public function logSafetyBlocked(
        int $companyId,
        ?int $userId,
        ?int $conversationId,
        array $metadata = []
    ): void {
        $this->logAction($companyId, $userId, $conversationId, AiAuditLog::ACTION_SAFETY_BLOCKED, $metadata);
    }

    private function normalizeAction(string $action): string
    {
        $normalized = mb_strtolower(trim($action));
        if (in_array($normalized, AiAuditLog::ALLOWED_ACTIONS, true)) {
            return $normalized;
        }

        return AiAuditLog::ACTION_MESSAGE_SENT;
    }
}

