<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Models\AiUsage;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AiAnalyticsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User || ! (bool) $user->is_active) {
            return response()->json([
                'authenticated' => false,
                'redirect' => '/entrar',
            ], 403);
        }

        if (! $user->isCompanyUser()) {
            return response()->json([
                'authenticated' => true,
                'message' => 'Acesso restrito a usuários da empresa.',
            ], 403);
        }

        if (! $user->isCompanyAdmin()) {
            return response()->json([
                'authenticated' => true,
                'message' => 'Somente admin da empresa pode acessar analytics da IA.',
            ], 403);
        }

        $companyId = (int) $user->company_id;
        $days = (int) $request->integer('days', 7);
        $days = in_array($days, [7, 30], true) ? $days : 7;

        $startDate = Carbon::now()->startOfDay()->subDays($days - 1);
        $endDate = Carbon::now()->endOfDay();
        $monthStart = Carbon::now()->startOfMonth();
        $monthEnd = Carbon::now()->endOfMonth();

        $baseQuery = AiUsage::query()
            ->where('company_id', $companyId)
            ->where('feature', AiUsage::FEATURE_INTERNAL_CHAT);

        $totalMessages = (clone $baseQuery)->count();
        $totalMonth = (clone $baseQuery)
            ->whereBetween('created_at', [$monthStart, $monthEnd])
            ->count();
        $totalUsersPeriod = (clone $baseQuery)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->whereNotNull('user_id')
            ->distinct('user_id')
            ->count('user_id');

        $dailyRows = (clone $baseQuery)
            ->selectRaw('DATE(created_at) as day, COUNT(*) as total')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->groupBy('day')
            ->orderBy('day')
            ->get();

        $dailyMap = [];
        foreach ($dailyRows as $row) {
            $dailyMap[(string) $row->day] = (int) $row->total;
        }

        $dailyMessages = [];
        foreach (CarbonPeriod::create($startDate->copy()->startOfDay(), '1 day', $endDate->copy()->startOfDay()) as $date) {
            $key = $date->format('Y-m-d');
            $dailyMessages[] = [
                'date' => $key,
                'label' => $date->format('d/m'),
                'count' => (int) ($dailyMap[$key] ?? 0),
            ];
        }

        $usageByUser = (clone $baseQuery)
            ->join('users', 'users.id', '=', 'ai_usages.user_id')
            ->whereBetween('ai_usages.created_at', [$startDate, $endDate])
            ->select(
                'users.id as user_id',
                'users.name as user_name',
                DB::raw('COUNT(ai_usages.id) as total_messages')
            )
            ->groupBy('users.id', 'users.name')
            ->orderByDesc('total_messages')
            ->limit(50)
            ->get()
            ->map(fn ($row) => [
                'user_id' => (int) $row->user_id,
                'name' => (string) ($row->user_name ?: 'Sem nome'),
                'count' => (int) $row->total_messages,
            ])
            ->values();

        $toolsUsage = (clone $baseQuery)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->whereNotNull('tool_used')
            ->whereRaw("TRIM(COALESCE(tool_used, '')) <> ''")
            ->select('tool_used', DB::raw('COUNT(*) as total_uses'))
            ->groupBy('tool_used')
            ->orderByDesc('total_uses')
            ->limit(20)
            ->get()
            ->map(fn ($row) => [
                'tool' => (string) $row->tool_used,
                'count' => (int) $row->total_uses,
            ])
            ->values();

        return response()->json([
            'authenticated' => true,
            'days' => $days,
            'range' => [
                'start' => $startDate->toDateString(),
                'end' => $endDate->toDateString(),
            ],
            'summary' => [
                'total_messages' => $totalMessages,
                'total_month' => $totalMonth,
                'total_users_period' => $totalUsersPeriod,
            ],
            'daily_messages' => $dailyMessages,
            'usage_by_user' => $usageByUser,
            'tools_usage' => $toolsUsage,
        ]);
    }
}
