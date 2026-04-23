<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreResellerRequest;
use App\Http\Requests\Admin\UpdateResellerRequest;
use App\Models\Reseller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class ResellerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $resellerId = $this->resolveResellerId($request);

        $resellers = Reseller::query()
            ->when($resellerId !== null, fn ($query) => $query->where('id', $resellerId))
            ->orderBy('name')
            ->get();

        return response()->json([
            'ok' => true,
            'resellers' => $resellers,
        ]);
    }

    public function store(StoreResellerRequest $request): JsonResponse
    {
        if (! $request->user()?->isSystemAdmin()) {
            return response()->json([
                'message' => 'Acesso negado.',
            ], 403);
        }

        $payload = $request->validated();
        $logoPath = $this->storeLogoFile($request->file('logo'));

        if ($logoPath !== null) {
            $payload['logo'] = $logoPath;
        } else {
            unset($payload['logo']);
        }

        $reseller = Reseller::create($payload);
        $this->forgetSlugCache((string) $reseller->slug);

        return response()->json([
            'ok' => true,
            'reseller' => $reseller,
        ], 201);
    }

    public function update(UpdateResellerRequest $request, Reseller $reseller): JsonResponse
    {
        $resellerId = $this->resolveResellerId($request);
        if ($resellerId !== null && (int) $reseller->id !== $resellerId) {
            return response()->json([
                'message' => 'Acesso negado para este reseller.',
            ], 403);
        }

        $payload = $request->validated();
        $oldSlug = (string) $reseller->slug;

        $newLogoPath = $this->storeLogoFile($request->file('logo'));
        if ($newLogoPath !== null) {
            $this->deleteLogoFile((string) ($reseller->logo ?? ''));
            $payload['logo'] = $newLogoPath;
        } else {
            unset($payload['logo']);
        }

        $reseller->fill($payload);
        $reseller->save();

        $this->forgetSlugCache($oldSlug);
        $this->forgetSlugCache((string) $reseller->slug);

        return response()->json([
            'ok' => true,
            'reseller' => $reseller->refresh(),
        ]);
    }

    private function storeLogoFile(?UploadedFile $file): ?string
    {
        if (! $file) {
            return null;
        }

        return $file->store('resellers/logos', 'public');
    }

    private function deleteLogoFile(string $logoPath): void
    {
        $normalized = trim($logoPath);
        if ($normalized === '') {
            return;
        }

        if (str_starts_with($normalized, 'http://') || str_starts_with($normalized, 'https://')) {
            return;
        }

        Storage::disk('public')->delete(ltrim($normalized, '/'));
    }

    private function forgetSlugCache(string $slug): void
    {
        $normalizedSlug = trim($slug);
        if ($normalizedSlug === '') {
            return;
        }

        Cache::forget('reseller_slug_' . $normalizedSlug);
    }

    private function resolveResellerId(Request $request): ?int
    {
        $user = $request->user();

        if (! $user || $user->isSystemAdmin()) {
            return null;
        }

        $resellerId = (int) ($user->company?->reseller_id ?? 0);

        return $resellerId > 0 ? $resellerId : -1;
    }
}
