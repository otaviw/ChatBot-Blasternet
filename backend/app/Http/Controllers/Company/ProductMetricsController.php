<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ProductMetricsService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductMetricsController extends Controller
{
    public function __construct(
        private readonly ProductMetricsService $productMetrics,
    ) {}

    public function funnel(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->user();

        if (! $user || ! (bool) $user->is_active) {
            return response()->json([
                'authenticated' => false,
                'redirect' => '/entrar',
            ], 403);
        }

        $companyId = $user->isSystemAdmin()
            ? max(0, (int) $request->integer('company_id', 0))
            : (int) ($user->company_id ?? 0);

        $dateFrom = $this->parseDate((string) $request->query('date_from', ''), now()->subDays(29)->startOfDay());
        $dateTo = $this->parseDate((string) $request->query('date_to', ''), now()->endOfDay());

        if ($dateFrom->gt($dateTo)) {
            [$dateFrom, $dateTo] = [$dateTo->copy()->startOfDay(), $dateFrom->copy()->endOfDay()];
        }

        $payload = $this->productMetrics->funnelSummary(
            $companyId > 0 ? $companyId : null,
            $dateFrom,
            $dateTo,
        );

        return response()->json([
            'ok' => true,
            ...$payload,
        ]);
    }

    private function parseDate(string $raw, Carbon $fallback): Carbon
    {
        $value = trim($raw);
        if ($value === '') {
            return $fallback;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return $fallback;
        }
    }
}
