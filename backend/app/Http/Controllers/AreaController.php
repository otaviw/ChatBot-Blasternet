<?php

namespace App\Http\Controllers;

use App\Models\Area;
use App\Models\User;
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

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user || ! $user->isCompanyUser()) {
            return response()->json(['authenticated' => false, 'redirect' => '/entrar'], 403);
        }

        $this->authorize('create', Area::class);

        $validated = $request->validate([
            'name' => 'required|string|max:120',
        ]);

        $area = Area::firstOrCreate([
            'company_id' => (int) $user->company_id,
            'name'       => trim($validated['name']),
        ]);

        $area->loadCount('users');

        return response()->json([
            'authenticated' => true,
            'area'          => $area,
        ], 201);
    }

    public function destroy(Request $request, Area $area): JsonResponse
    {
        $user = $request->user();
        if (! $user || ! $user->isCompanyUser()) {
            return response()->json(['authenticated' => false, 'redirect' => '/entrar'], 403);
        }

        $this->authorize('delete', $area);

        $area->delete();

        return response()->json(['authenticated' => true]);
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
            ->whereIn('role', User::companyRoleValues())
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
