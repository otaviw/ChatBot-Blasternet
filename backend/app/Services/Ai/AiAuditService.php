<?php

declare(strict_types=1);


namespace App\Services\Ai;

use App\Models\AiAuditLog;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

class AiAuditService
{
    /** @var array<string, bool>|null */
    private ?array $columns = null;

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
        $sanitizedMetadata = $this->sanitizeMetadata($metadata, $normalizedAction);

        try {
            AiAuditLog::query()->create(array_filter([
                'company_id' => $companyId,
                'user_id' => $userId,
                'conversation_id' => $conversationId,
                'inbox_conversation_id' => $this->metadataInt($sanitizedMetadata, 'inbox_conversation_id'),
                'message_id' => $this->metadataInt($sanitizedMetadata, 'message_id'),
                'decision_log_id' => $this->metadataInt($sanitizedMetadata, 'decision_log_id'),
                'action' => $normalizedAction,
                'source' => $this->metadataString($sanitizedMetadata, 'source', 40),
                'contact_phone_hash' => $this->metadataString($sanitizedMetadata, 'contact_phone_hash', 64),
                'contact_name' => $this->metadataString($sanitizedMetadata, 'contact_name', 160),
                'metadata' => $sanitizedMetadata,
                'created_at' => now(),
            ], fn ($value, string $key): bool => $this->columnExists($key), ARRAY_FILTER_USE_BOTH));
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

    private function metadataInt(array $metadata, string $key): ?int
    {
        if (! is_numeric($metadata[$key] ?? null)) {
            return null;
        }

        $value = (int) $metadata[$key];

        return $value > 0 ? $value : null;
    }

    private function metadataString(array $metadata, string $key, int $limit): ?string
    {
        $value = trim((string) ($metadata[$key] ?? ''));

        return $value !== '' ? mb_substr($value, 0, $limit) : null;
    }

    private function columnExists(string $column): bool
    {
        if ($this->columns === null) {
            try {
                $this->columns = array_fill_keys(Schema::getColumnListing('ai_audit_logs'), true);
            } catch (Throwable) {
                $this->columns = [];
            }
        }

        return isset($this->columns[$column]);
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function sanitizeMetadata(array $metadata, string $action): array
    {
        $gateResult = is_array($metadata['gate_result'] ?? null) ? $metadata['gate_result'] : null;
        $replyComparison = is_array($gateResult['reply_comparison'] ?? null) ? $gateResult['reply_comparison'] : null;
        if ($gateResult === null || $replyComparison === null) {
            return $metadata;
        }

        $legacyReply = trim((string) ($replyComparison['legacy_reply'] ?? ''));
        $aiReply = trim((string) ($replyComparison['ai_reply'] ?? ''));
        $existingChangedResponse = $replyComparison['changed_response'] ?? null;
        $changedResponse = is_bool($existingChangedResponse)
            ? $existingChangedResponse
            : $legacyReply !== $aiReply;

        unset($replyComparison['legacy_reply'], $replyComparison['ai_reply']);

        $replyComparison['legacy_reply_length'] = mb_strlen($legacyReply);
        $replyComparison['ai_reply_length'] = mb_strlen($aiReply);
        $replyComparison['legacy_reply_hash'] = hash('sha256', $legacyReply);
        $replyComparison['ai_reply_hash'] = hash('sha256', $aiReply);
        $replyComparison['changed_response'] = $changedResponse;
        $replyComparison['action'] = trim((string) ($replyComparison['action'] ?? $action));
        $replyComparison['confidence'] = is_numeric($replyComparison['confidence'] ?? null)
            ? (float) $replyComparison['confidence']
            : null;

        $gateResult['reply_comparison'] = $replyComparison;
        $metadata['gate_result'] = $gateResult;

        return $metadata;
    }
}
