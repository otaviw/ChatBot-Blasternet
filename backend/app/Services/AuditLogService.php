<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Http\Request;

class AuditLogService
{
    public function record(
        Request $request,
        string $action,
        ?int $companyId = null,
        array $changes = [],
        array $meta = []
    ): void {
        $user = $request->user();

        AuditLog::create([
            'company_id' => $companyId,
            'actor_role' => $user?->role ?? $request->session()->get('role'),
            'actor_company_id' => $user?->company_id ?? $request->session()->get('company_id'),
            'action' => $action,
            'method' => $request->method(),
            'route' => $request->path(),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'changes' => $changes ?: null,
            'meta' => $meta ?: null,
        ]);
    }
}
