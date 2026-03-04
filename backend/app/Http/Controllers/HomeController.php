<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class HomeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'authenticated' => Auth::check(),
            'demo_accounts' => [
                [
                    'label' => 'Admin demo',
                    'email' => 'admin@teste.local',
                    'password' => 'teste123',
                ],
                [
                    'label' => 'Empresa demo',
                    'email' => 'empresa@teste.local',
                    'password' => 'teste123',
                ],
            ],
        ]);
    }

    public function dashboard(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json([
                'authenticated' => false,
                'redirect' => '/entrar',
            ], 403);
        }

        if ($user->isSystemAdmin()) {
            return response()->json([
                'authenticated' => true,
                'role' => 'admin',
                'user_role' => \App\Models\User::normalizeRole($user->role),
                'can_manage_users' => false,
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                ],
            ]);
        }

        return response()->json([
            'authenticated' => true,
            'role' => 'company',
            'user_role' => \App\Models\User::normalizeRole($user->role),
            'can_manage_users' => $user->canManageCompanyUsers(),
            'companyName' => $user->company?->name ?? 'Empresa',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
        ]);
    }
}
