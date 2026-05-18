<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Manual;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ManualController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $this->validatePayload($request);
        /** @var User $user */
        $user = $request->user();

        $manual = new Manual($validated);
        $manual->created_by_user_id = $user->id;
        $manual->updated_by_user_id = $user->id;
        $manual->save();

        return response()->json(['manual' => $this->serialize($manual)], 201);
    }

    public function update(Request $request, Manual $manual): JsonResponse
    {
        $validated = $this->validatePayload($request);
        /** @var User $user */
        $user = $request->user();

        $manual->fill($validated);
        $manual->updated_by_user_id = $user->id;
        $manual->save();

        return response()->json(['manual' => $this->serialize($manual)]);
    }

    public function destroy(Manual $manual): JsonResponse
    {
        $manual->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePayload(Request $request): array
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'category' => ['required', 'string', Rule::in(['screen', 'flow'])],
            'target_key' => ['nullable', 'string', 'max:64'],
            'summary' => ['nullable', 'string', 'max:2000'],
            'content' => ['required', 'string'],
            'image_urls' => ['nullable', 'array'],
            'image_urls.*' => ['string', 'max:2000'],
            'required_roles' => ['nullable', 'array'],
            'required_roles.*' => ['string', Rule::in(User::assignableRoleValuesForSystemAdmin())],
            'required_permissions' => ['nullable', 'array'],
            'required_permissions.*' => ['string'],
            'is_published' => ['sometimes', 'boolean'],
        ]);

        $validated['image_urls'] = array_values(array_filter($validated['image_urls'] ?? [], fn ($url) => trim((string) $url) !== ''));
        $validated['required_roles'] = array_values(array_filter($validated['required_roles'] ?? [], fn ($value) => trim((string) $value) !== ''));
        $validated['required_permissions'] = array_values(array_filter($validated['required_permissions'] ?? [], fn ($value) => trim((string) $value) !== ''));
        $validated['is_published'] = array_key_exists('is_published', $validated) ? (bool) $validated['is_published'] : true;

        return $validated;
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(Manual $manual): array
    {
        return [
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
        ];
    }
}

