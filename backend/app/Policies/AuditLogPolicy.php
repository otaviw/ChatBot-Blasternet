<?php

namespace App\Policies;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class AuditLogPolicy
{
    public function viewAny(User $user): Response
    {
        $resellerId = $this->resolveResellerId($user);
        if ($user->isSystemAdmin() || (int) ($user->company_id ?? 0) > 0 || $resellerId > 0) {
            return Response::allow();
        }

        return Response::deny('Voce nao possui permissao para acessar logs de auditoria.');
    }

    public function view(User $user, AuditLog $auditLog): Response
    {
        return Response::deny('Acesso direto aos logs de auditoria nao e permitido.');
    }

    public function create(User $user): Response
    {
        return Response::deny('Criacao manual de logs de auditoria nao e permitida.');
    }

    public function update(User $user, AuditLog $auditLog): Response
    {
        return Response::deny('Atualizacao de logs de auditoria nao e permitida.');
    }

    public function delete(User $user, AuditLog $auditLog): Response
    {
        return Response::deny('Exclusao de logs de auditoria nao e permitida.');
    }

    private function resolveResellerId(User $user): int
    {
        $candidate = $user->reseller_id ?? session('reseller_id');

        return is_numeric($candidate) ? (int) $candidate : 0;
    }
}
