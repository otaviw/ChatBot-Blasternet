<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credentials, true)) {
            return response()->json([
                'message' => 'Credenciais invalidas.',
            ], 422);
        }

        $request->session()->regenerate();
        $user = $request->user();
        if (! $user) {
            return response()->json([
                'message' => 'Falha ao autenticar usuário.',
            ], 500);
        }
        if (! $user->is_active) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return response()->json([
                'message' => 'Usuario inativo. Procure um administrador.',
            ], 403);
        }

        return response()->json([
            'authenticated' => true,
            'user' => $this->userPayload($user->loadMissing('company')),
        ]);
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

        return response()->json([
            'authenticated' => true,
            'user' => $this->userPayload($user->loadMissing('company')),
        ]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Não autenticado.'], 403);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $user->name = $validated['name'];
        $user->save();

        return response()->json([
            'user' => $this->userPayload($user->loadMissing('company')),
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

    private function userPayload($user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => User::normalizeRole($user->role),
            'company_id' => $user->company_id,
            'company_name' => $user->company?->name,
            'can_manage_users' => $user->canManageCompanyUsers(),
        ];
    }
}
