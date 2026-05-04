<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class CriticalAuditLogService
{
    private const MAX_USER_AGENT_LENGTH = 1024;

    /**
     * @param array<string, mixed> $meta
     */
    public function record(Request $request, string $action, ?int $companyId = null, array $meta = []): void
    {
        $normalizedAction = trim($action);
        if ($normalizedAction === '') {
            return;
        }

        $user = $request->user();

        try {
            DB::table('critical_audit_logs')->insert([
                'user_id' => is_numeric($user?->id ?? null) ? (int) $user->id : null,
                'company_id' => is_numeric($companyId) && (int) $companyId > 0 ? (int) $companyId : null,
                'action' => $normalizedAction,
                'ip_address' => $this->sanitizeIp($request->ip()),
                'user_agent' => $this->sanitizeUserAgent($request->userAgent()),
                'meta' => $meta === [] ? null : json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_at' => now(),
            ]);
        } catch (Throwable $exception) {
            Log::warning('critical_audit_log.record_failed', [
                'action' => $normalizedAction,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function sanitizeIp(?string $ip): ?string
    {
        $value = trim((string) $ip);
        if ($value === '') {
            return null;
        }

        return mb_substr($value, 0, 45);
    }

    private function sanitizeUserAgent(?string $userAgent): ?string
    {
        $value = trim((string) $userAgent);
        if ($value === '') {
            return null;
        }

        return mb_substr($value, 0, self::MAX_USER_AGENT_LENGTH);
    }
}
