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

        $search = trim((string) $request->query('q', ''));
        $category = trim((string) $request->query('category', ''));

        $terms = $this->searchTerms($search);

        $manuals = Manual::query()
            ->orderByRaw("CASE WHEN category = 'flow' THEN 0 ELSE 1 END")
            ->orderBy('title')
            ->get()
            ->filter(fn (Manual $manual): bool => $this->canViewManual($user, $manual))
            ->filter(fn (Manual $manual): bool => $this->matchesCategory($manual, $category))
            ->filter(fn (Manual $manual): bool => $this->matchesSearch($manual, $terms))
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

    /**
     * @return list<string>
     */
    private function searchTerms(string $search): array
    {
        $stopWords = ['a', 'as', 'o', 'os', 'e', 'de', 'da', 'das', 'do', 'dos', 'em', 'na', 'nas', 'no', 'nos'];
        $normalized = $this->normalizeSearchText($search);
        $parts = preg_split('/\s+/', $normalized) ?: [];
        $terms = [];

        foreach ($parts as $part) {
            $term = trim($part);
            if ($term === '' || in_array($term, $stopWords, true)) {
                continue;
            }

            $terms[] = $term;
        }

        return $terms !== [] ? array_values(array_unique($terms)) : [];
    }

    private function matchesCategory(Manual $manual, string $category): bool
    {
        if (! in_array($category, ['screen', 'flow'], true)) {
            return true;
        }

        return (string) $manual->category === $category;
    }

    /**
     * @param list<string> $terms
     */
    private function matchesSearch(Manual $manual, array $terms): bool
    {
        if ($terms === []) {
            return true;
        }

        $haystack = $this->normalizeSearchText(implode(' ', [
            $manual->title,
            $manual->summary,
            $manual->content,
            $manual->target_key,
        ]));

        foreach ($terms as $term) {
            if (! str_contains($haystack, $term)) {
                return false;
            }
        }

        return true;
    }

    private function normalizeSearchText(string $value): string
    {
        $normalized = mb_strtolower(trim($value));
        $withoutAccents = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $normalized);
        if (is_string($withoutAccents) && $withoutAccents !== '') {
            $normalized = $withoutAccents;
        }

        $normalized = preg_replace('/[^a-z0-9]+/i', ' ', $normalized) ?? $normalized;

        return trim(preg_replace('/\s+/', ' ', $normalized) ?? $normalized);
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
