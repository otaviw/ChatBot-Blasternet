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
        $slug = $request->query('slug');

        $reseller = $slug
            ? Reseller::getBySlug($slug)
            : $request->user()->loadMissing('company.reseller')->company?->reseller;

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
