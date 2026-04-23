<?php

namespace App\Http\Controllers;

use App\Models\Reseller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BrandingController extends Controller
{
    private const DEFAULT_BRANDING = [
        'name'          => null,
        'logo_url'      => null,
        'primary_color' => null,
    ];

    public function show(Request $request): JsonResponse
    {
        $slug = trim((string) $request->query('slug', ''));
        $user = $request->user()?->loadMissing('company.reseller', 'reseller');

        $reseller = $slug !== ''
            ? Reseller::getBySlug($slug)
            : ($user?->company?->reseller ?? $user?->reseller);

        if (! $reseller) {
            return response()->json(self::DEFAULT_BRANDING);
        }

        return response()->json([
            'name'          => $reseller->name,
            'logo_url'      => $reseller->logo_url,
            'primary_color' => $reseller->primary_color,
        ]);
    }
}
