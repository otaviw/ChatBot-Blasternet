<?php

namespace App\Http\Controllers;

use App\Models\Area;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AreaController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user || ! $user->isCompanyUser()) {
            return response()->json([
                'authenticated' => false,
                'redirect' => '/entrar',
            ], 403);
        }

        $this->authorize('viewAny', Area::class);

        $areas = Area::query()
            ->where('company_id', (int) $user->company_id)
            ->withCount('users')
            ->orderBy('name')
            ->get(['id', 'company_id', 'name', 'created_at', 'updated_at']);

        return response()->json([
            'authenticated' => true,
            'areas' => $areas,
        ]);
    }

    public function users(Request $request, Area $area): JsonResponse
    {
        $user = $request->user();
        if (! $user || ! $user->isCompanyUser()) {
            return response()->json([
                'authenticated' => false,
                'redirect' => '/entrar',
            ], 403);
        }

        $this->authorize('view', $area);

        $users = $area->users()
            ->where('role', 'company')
            ->where('is_active', true)
            ->with('areas:id,name')
            ->orderBy('name')
            ->get(['users.id', 'users.name', 'users.email', 'users.company_id', 'users.is_active']);

        return response()->json([
            'authenticated' => true,
            'area' => [
                'id' => $area->id,
                'name' => $area->name,
                'company_id' => $area->company_id,
            ],
            'users' => $users,
        ]);
    }
}

