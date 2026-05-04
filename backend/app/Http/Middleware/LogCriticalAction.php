<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\CriticalAuditLogService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LogCriticalAction
{
    public function __construct(private readonly CriticalAuditLogService $criticalAuditLog) {}

    public function handle(Request $request, Closure $next, string $action): Response
    {
        $response = $next($request);

        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 400) {
            return $response;
        }

        if (! $this->shouldLog($request, $action)) {
            return $response;
        }

        $this->criticalAuditLog->record($request, $action, $this->resolveCompanyId($request), [
            'method' => $request->method(),
            'route' => $request->path(),
        ]);

        return $response;
    }

    private function shouldLog(Request $request, string $action): bool
    {
        if ($action !== 'auth.admin_login') {
            return true;
        }

        $user = $request->user();
        if (! $user instanceof User) {
            return false;
        }

        return $user->isAdmin() || $user->isCompanyAdmin();
    }

    private function resolveCompanyId(Request $request): ?int
    {
        $user = $request->user();
        if (is_numeric($user?->company_id ?? null) && (int) $user->company_id > 0) {
            return (int) $user->company_id;
        }

        $routeCompany = $request->route('company');
        if (is_object($routeCompany) && isset($routeCompany->id) && is_numeric($routeCompany->id)) {
            return (int) $routeCompany->id;
        }

        if (is_numeric($routeCompany) && (int) $routeCompany > 0) {
            return (int) $routeCompany;
        }

        $routeUser = $request->route('user');
        if (is_object($routeUser) && isset($routeUser->company_id) && is_numeric($routeUser->company_id) && (int) $routeUser->company_id > 0) {
            return (int) $routeUser->company_id;
        }

        if (is_numeric($request->input('company_id')) && (int) $request->input('company_id') > 0) {
            return (int) $request->input('company_id');
        }

        return null;
    }
}
