<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Manual;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ManualController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Não autenticado.'], 401);
        }

        $manuals = Manual::query()
            ->orderByRaw("CASE WHEN category = 'flow' THEN 0 ELSE 1 END")
            ->orderBy('title')
            ->get()
            ->filter(fn (Manual $manual): bool => $this->canViewManual($user, $manual))
            ->values()
            ->map(fn (Manual $manual): array => [
                'id' => (int) $manual->id,
                'title' => (string) $manual->title,
                'category' => (string) $manual->category,
                'target_key' => $manual->target_key,
                'summary' => $manual->summary,
                'content' => (string) $manual->content,
                'image_urls' => is_array($manual->image_urls) ? array_values($manual->image_urls) : [],
                'required_roles' => is_array($manual->required_roles) ? array_values($manual->required_roles) : [],
                'required_permissions' => is_array($manual->required_permissions) ? array_values($manual->required_permissions) : [],
                'is_published' => (bool) $manual->is_published,
                'updated_at' => optional($manual->updated_at)?->toISOString(),
            ]);

        return response()->json([
            'manuals' => $manuals,
            'can_manage' => $user->isSystemAdmin(),
        ]);
    }

    private function canViewManual(User $user, Manual $manual): bool
    {
        if ($user->isSystemAdmin()) {
            return true;
        }

        if (! $manual->is_published) {
            return false;
        }

        $requiredRoles = is_array($manual->required_roles) ? $manual->required_roles : [];
        if ($requiredRoles !== []) {
            $normalizedRole = User::normalizeRole((string) $user->role);
            if (! in_array($normalizedRole, $requiredRoles, true)) {
                return false;
            }
        }

        $requiredPermissions = is_array($manual->required_permissions) ? $manual->required_permissions : [];
        if ($requiredPermissions !== []) {
            $userPermissions = $user->resolvedPermissions();
            foreach ($requiredPermissions as $permission) {
                if (! in_array((string) $permission, $userPermissions, true)) {
                    return false;
                }
            }
        }

        return true;
    }
}

