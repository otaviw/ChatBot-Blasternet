<?php

declare(strict_types=1);


namespace App\Http\Controllers;

use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\UpdatePasswordRequest;
use App\Http\Requests\Auth\UpdateProfileRequest;
use App\Models\User;
use App\Services\Ai\AiAccessService;
use App\Services\ProductMetricsService;
use App\Support\ProductFunnels;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class AuthController extends Controller
{
    public function __construct(
        private readonly AiAccessService $aiAccessService,
        private readonly ProductMetricsService $productMetrics,
    ) {}

    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->validated();
        $emailHash = hash('sha256', mb_strtolower((string) ($credentials['email'] ?? '')));

        $this->productMetrics->track(
            ProductFunnels::LOGIN,
            'attempt',
            'auth_login_attempt',
            null,
            null,
            ['email_hash' => $emailHash],
        );

        if (! Auth::attempt($credentials, true)) {
            $reason = User::where('email', $credentials['email'] ?? '')->exists()
                ? 'invalid_password'
                : 'unknown_user';

            Log::warning('auth.login_failed', [
                'reason'     => $reason,
                'email_hash' => $emailHash,
                'ip'         => $request->ip(),
            ]);

            return $this->invalidCredentialsResponse();
        }

        $request->session()->regenerate();
        $user = $request->user();

        if (! $user) {
            return $this->errorResponse('Falha ao autenticar usuário.', 'auth_failed', 500);
        }

        if (! $user->is_active) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            Log::warning('auth.login_failed', [
                'reason'     => 'inactive_user',
                'email_hash' => $emailHash,
                'ip'         => $request->ip(),
                'user_id'    => (int) $user->id,
            ]);

            return $this->invalidCredentialsResponse();
        }

        $user->loadMissing('company.reseller', 'reseller');

        $this->productMetrics->track(
            ProductFunnels::LOGIN,
            'success',
            'auth_login_success',
            $user->company_id ? (int) $user->company_id : null,
            (int) $user->id,
            ['role' => User::normalizeRole((string) $user->role)],
        );

        return response()->json([
            'authenticated' => true,
            'user'          => $this->userPayload($user),
            'reseller'      => $this->resellerPayload($user),
        ]);
    }

    /**
     * Resposta genérica para credenciais inválidas.
     * Única resposta possível para falha de login — impede enumeração de usuários.
     */
    private function invalidCredentialsResponse(): JsonResponse
    {
        return $this->errorResponse('Credenciais inválidas.', 'invalid_credentials', 401);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json([
                'authenticated' => false,
                'redirect' => '/entrar',
            ], 403);
        }

        $user->loadMissing('company.reseller', 'reseller');

        return response()->json([
            'authenticated' => true,
            'user' => $this->userPayload($user),
            'reseller' => $this->resellerPayload($user),
        ]);
    }

    public function updateProfile(UpdateProfileRequest $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return $this->errorResponse('Não autenticado.', 'unauthenticated', 401);
        }

        $user->name = $request->validated('name');
        $user->save();

        $user->loadMissing('company.reseller', 'reseller');

        return response()->json([
            'user' => $this->userPayload($user),
            'reseller' => $this->resellerPayload($user),
        ]);
    }

    public function updatePassword(UpdatePasswordRequest $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return $this->errorResponse('Não autenticado.', 'unauthenticated', 401);
        }

        $validated = $request->validated();

        if (! Hash::check($validated['current_password'], $user->password)) {
            return response()->json([
                'message' => 'Senha atual incorreta.',
                'errors' => ['current_password' => ['Senha atual incorreta.']],
            ], 422);
        }

        $user->password = Hash::make($validated['password']);
        $user->save();

        return response()->json([
            'ok' => true,
            'message' => 'Senha alterada com sucesso.',
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json([
            'ok' => true,
        ]);
    }

    private function resellerPayload(User $user): ?array
    {
        $reseller = $user->company?->reseller ?? $user->reseller;

        if (! $reseller) {
            return null;
        }

        return [
            'id'            => $reseller->id,
            'slug'          => $reseller->slug,
            'name'          => $reseller->name,
            'logo_url'      => $reseller->logo_url,
            'primary_color' => $reseller->primary_color,
        ];
    }

    private function userPayload(User $user): array
    {
        $settings = $this->aiAccessService->resolveCompanySettings($user);
        $canUseInternalAi = $this->aiAccessService->canUseInternalAi($user, $settings);

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => User::normalizeRole($user->role),
            'company_id' => $user->company_id,
            'company_name' => $user->company?->name,
            'reseller_id' => $user->reseller_id ?? $user->company?->reseller_id,
            'reseller_name' => $user->reseller?->name ?? $user->company?->reseller?->name,
            'can_manage_users' => $user->canManageCompanyUsers(),
            'can_use_ai' => $canUseInternalAi,
            'can_access_internal_ai_chat' => $canUseInternalAi,
            'can_manage_ai' => $this->aiAccessService->canManageAi($user),
            'has_ixc_integration' => (bool) ($user->company?->has_ixc_integration ?? false),
            'permissions' => $user->resolvedPermissions(),
        ];
    }
}
