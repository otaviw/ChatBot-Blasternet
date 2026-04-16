<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class AuditLogService
{
    /** @var array<int, string>|null */
    private static ?array $auditLogColumns = null;

    public function record(
        Request $request,
        string $action,
        ?int $companyId = null,
        array $changes = [],
        array $meta = []
    ): void {
        $user = $request->user();
        $normalizedAction = trim($action);
        if ($normalizedAction === '') {
            return;
        }

        $normalizedChanges = $this->normalizePayload($changes);
        $normalizedMeta = $this->normalizePayload($meta);

        $entityType = $this->resolveEntityType($normalizedAction, $changes, $meta);
        $entityId = $this->resolveEntityId($changes, $meta);

        $basePayload = [
            'user_id' => is_numeric($user?->id ?? null) ? (int) $user->id : null,
            'company_id' => is_numeric($companyId) && (int) $companyId > 0 ? (int) $companyId : null,
            'reseller_id' => $this->resolveResellerId($request, $user),
            'actor_role' => $user?->role ?? $request->session()->get('role'),
            'actor_company_id' => $user?->company_id ?? $request->session()->get('company_id'),
            'action' => $normalizedAction,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'method' => $request->method(),
            'route' => $request->path(),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'changes' => $normalizedChanges,
            'meta' => $normalizedMeta,
            'old_data' => $normalizedMeta,
            'new_data' => $normalizedChanges,
            'created_at' => now(),
        ];

        $payload = $this->filterPayloadByExistingColumns($basePayload);
        if (
            $this->hasAuditLogColumn('entity_type')
            && (! array_key_exists('entity_type', $payload) || ! is_string($payload['entity_type']) || trim($payload['entity_type']) === '')
        ) {
            $payload['entity_type'] = 'audit';
        }

        DB::table('audit_logs')->insert($payload);
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @return array<string, mixed>|null
     */
    private function normalizePayload(?array $payload): ?array
    {
        if (! is_array($payload) || $payload === []) {
            return null;
        }

        return $payload;
    }

    private function resolveResellerId(Request $request, mixed $user): ?int
    {
        $candidates = [
            $user?->reseller_id ?? null,
            $request->attributes->get('reseller_id'),
            $request->route('reseller'),
            $request->route('reseller_id'),
            session('reseller_id'),
        ];

        foreach ($candidates as $candidate) {
            if (is_numeric($candidate) && (int) $candidate > 0) {
                return (int) $candidate;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $changes
     * @param  array<string, mixed>  $meta
     */
    private function resolveEntityType(string $action, array $changes, array $meta): string
    {
        $metaType = trim((string) ($meta['entity_type'] ?? ''));
        if ($metaType !== '') {
            return $metaType;
        }

        if (array_key_exists('conversation_id', $changes)) {
            return 'conversation';
        }

        if (array_key_exists('ticket_id', $changes)) {
            return 'support_ticket';
        }

        if (array_key_exists('tag_id', $changes)) {
            return 'tag';
        }

        if (array_key_exists('target_user_id', $changes) || array_key_exists('user_id', $changes)) {
            return 'user';
        }

        if (array_key_exists('message_id', $changes)) {
            return 'message';
        }

        return match (true) {
            Str::contains($action, '.conversation.') => 'conversation',
            Str::contains($action, '.ticket.') => 'support_ticket',
            Str::contains($action, '.tag.') => 'tag',
            Str::contains($action, '.user.') => 'user',
            Str::contains($action, '.company.') => 'company',
            Str::contains($action, '.bot_settings.') => 'bot_settings',
            default => 'audit',
        };
    }

    /**
     * @param  array<string, mixed>  $changes
     * @param  array<string, mixed>  $meta
     */
    private function resolveEntityId(array $changes, array $meta): ?string
    {
        $candidates = [
            $meta['entity_id'] ?? null,
            $changes['conversation_id'] ?? null,
            $changes['ticket_id'] ?? null,
            $changes['tag_id'] ?? null,
            $changes['target_user_id'] ?? null,
            $changes['user_id'] ?? null,
            $changes['message_id'] ?? null,
            $changes['company_id'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if ($candidate === null) {
                continue;
            }

            if (is_scalar($candidate)) {
                $normalized = trim((string) $candidate);
                if ($normalized !== '') {
                    return $normalized;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function filterPayloadByExistingColumns(array $payload): array
    {
        $this->loadAuditLogColumns();

        $allowed = array_flip(self::$auditLogColumns);

        return array_filter(
            $payload,
            static fn (string $key): bool => isset($allowed[$key]),
            ARRAY_FILTER_USE_KEY
        );
    }

    private function hasAuditLogColumn(string $column): bool
    {
        $this->loadAuditLogColumns();
        return in_array($column, self::$auditLogColumns, true);
    }

    private function loadAuditLogColumns(): void
    {
        if (self::$auditLogColumns === null) {
            self::$auditLogColumns = Schema::getColumnListing('audit_logs');
        }
    }
}
