<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreResellerRequest;
use App\Models\Reseller;
use Illuminate\Http\JsonResponse;

class ResellerController extends Controller
{
    public function store(StoreResellerRequest $request): JsonResponse
    {
        $reseller = Reseller::create($request->validated());

        return response()->json([
            'ok'       => true,
            'reseller' => $reseller,
        ], 201);
    }
}
