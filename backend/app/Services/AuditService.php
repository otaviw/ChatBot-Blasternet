<?php

declare(strict_types=1);


namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Auditoria de mudanças em entidades (mensagens, modelos, eventos de domínio).
 *
 * Use este serviço quando precisar registrar O QUE mudou em uma entidade específica,
 * independente de existir uma requisição HTTP ativa. É chamado de forma estática e
 * resolve o contexto do request automaticamente via container.
 *
 * Para registrar AÇÕES DE USUÁRIO (quem fez o quê via HTTP, com IP e rota), use
 * AuditLogService, que exige injeção de dependência e um Request explícito.
 *
 * @see AuditLogService Para auditoria de ações HTTP de usuário
 */
class AuditService
{
    /**
     * Registra um evento de auditoria sem interromper o fluxo da aplicação.
     *
     * @param  array<string, mixed>|null  $oldData
     * @param  array<string, mixed>|null  $newData
     */
    public static function log(
        string $action,
        string $entityType,
        mixed $entityId = null,
        ?array $oldData = null,
        ?array $newData = null
    ): void {
        try {
            $request = self::resolveRequest();
            $user = Auth::user();

            $normalizedAction = trim($action);
            $normalizedEntityType = trim($entityType);
            $companyId = self::resolveCompanyId($request, $user);

            if ($normalizedAction === '' || $normalizedEntityType === '' || $companyId === null) {
                return;
            }

            DB::table('audit_logs')->insert([
                'user_id' => self::resolveUserId($user),
                'company_id' => $companyId,
                'reseller_id' => self::resolveResellerId($request, $user),
                'action' => $normalizedAction,
                'entity_type' => $normalizedEntityType,
                'entity_id' => self::normalizeEntityId($entityId),
                'old_data' => self::normalizePayload($oldData),
                'new_data' => self::normalizePayload($newData),
                'ip_address' => $request?->ip(),
                'user_agent' => $request?->userAgent(),
                'created_at' => now(),
            ]);
        } catch (Throwable $exception) {
            Log::warning('audit.log_failed', [
                'action' => $action,
                'entity_type' => $entityType,
                'entity_id' => self::normalizeEntityId($entityId),
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private static function resolveRequest(): ?Request
    {
        if (! app()->bound('request')) {
            return null;
        }

        $request = request();
        return $request instanceof Request ? $request : null;
    }

    private static function resolveUserId(mixed $user): ?int
    {
        $userId = $user?->id ?? null;
        return is_numeric($userId) ? (int) $userId : null;
    }

    private static function resolveCompanyId(?Request $request, mixed $user): ?int
    {
        if (is_numeric($user?->company_id ?? null) && (int) $user->company_id > 0) {
            return (int) $user->company_id;
        }

        $candidates = [
            self::normalizeRouteCompany($request?->route('company')),
            $request?->attributes->get('company_id'),
            $request?->route('company_id'),
            session('company_id'),
        ];

        foreach ($candidates as $candidate) {
            if (is_numeric($candidate) && (int) $candidate > 0) {
                return (int) $candidate;
            }
        }

        return null;
    }

    private static function resolveResellerId(?Request $request, mixed $user): ?int
    {
        $candidates = [
            $user?->reseller_id ?? null,
            $request?->attributes->get('reseller_id'),
            $request?->route('reseller'),
            $request?->route('reseller_id'),
            session('reseller_id'),
        ];

        foreach ($candidates as $candidate) {
            if (is_numeric($candidate) && (int) $candidate > 0) {
                return (int) $candidate;
            }
        }

        return null;
    }

    private static function normalizeRouteCompany(mixed $value): mixed
    {
        if (is_numeric($value)) {
            return $value;
        }

        if (is_object($value) && isset($value->id) && is_numeric($value->id)) {
            return $value->id;
        }

        if (is_string($value) && Str::contains($value, '/')) {
            return null;
        }

        return $value;
    }

    private static function normalizeEntityId(mixed $entityId): ?string
    {
        if ($entityId === null) {
            return null;
        }

        $normalized = trim((string) $entityId);
        return $normalized === '' ? null : $normalized;
    }

    private static function normalizePayload(?array $payload): ?string
    {
        if ($payload === null || $payload === []) {
            return null;
        }

        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $encoded !== false ? $encoded : null;
    }
}
