<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Models\AiUsage;
use App\Models\Company;
use App\Models\User;
use App\Services\Ai\AiAccessService;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AiAnalyticsController extends Controller
{
    public function __construct(
        private AiAccessService $aiAccess
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User || ! (bool) $user->is_active) {
            return response()->json([
                'authenticated' => false,
                'redirect' => '/entrar',
            ], 403);
        }

        if (! $this->aiAccess->canManageAi($user)) {
            return response()->json([
                'authenticated' => true,
                'message' => 'Somente admin da empresa pode acessar analytics da IA.',
            ], 403);
        }

        $companies = $user->isSystemAdmin()
            ? Company::orderBy('name')->get(['id', 'name'])
            : null;

        $allCompanies = false;
        if ($user->isSystemAdmin()) {
            $companyIdParam = trim((string) $request->query('company_id', ''));
            if ($companyIdParam === 'all' || $companyIdParam === '0' || $companyIdParam === '') {
                $allCompanies = true;
                $companyId = null;
            } else {
                $companyId = (int) $companyIdParam;
            }
        } else {
            $companyId = (int) $user->company_id;
        }

        $days = (int) $request->integer('days', 7);
        $days = in_array($days, [7, 30], true) ? $days : 7;

        $startDate = Carbon::now()->startOfDay()->subDays($days - 1);
        $endDate = Carbon::now()->endOfDay();
        $monthStart = Carbon::now()->startOfMonth();
        $monthEnd = Carbon::now()->endOfMonth();

        $baseQuery = AiUsage::query()
            ->where('ai_usages.feature', AiUsage::FEATURE_INTERNAL_CHAT);

        if (! $allCompanies && $companyId !== null) {
            $baseQuery->where('ai_usages.company_id', $companyId);
        }

        $totalMessages = (clone $baseQuery)->count();
        $totalMonth = (clone $baseQuery)
            ->whereBetween('ai_usages.created_at', [$monthStart, $monthEnd])
            ->count();
        $totalUsersPeriod = (clone $baseQuery)
            ->whereBetween('ai_usages.created_at', [$startDate, $endDate])
            ->whereNotNull('ai_usages.user_id')
            ->distinct('ai_usages.user_id')
            ->count('ai_usages.user_id');

        $dailyRows = (clone $baseQuery)
            ->selectRaw('DATE(ai_usages.created_at) as day, COUNT(*) as total')
            ->whereBetween('ai_usages.created_at', [$startDate, $endDate])
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

        $usageByUserQuery = (clone $baseQuery)
            ->join('users', 'users.id', '=', 'ai_usages.user_id')
            ->whereBetween('ai_usages.created_at', [$startDate, $endDate]);

        if ($allCompanies) {
            $usageByUserQuery
                ->leftJoin('companies', 'companies.id', '=', 'ai_usages.company_id')
                ->select(
                    'users.id as user_id',
                    'users.name as user_name',
                    'companies.name as company_name',
                    DB::raw('COUNT(ai_usages.id) as total_messages')
                )
                ->groupBy('users.id', 'users.name', 'companies.id', 'companies.name');
        } else {
            $usageByUserQuery
                ->select(
                    'users.id as user_id',
                    'users.name as user_name',
                    DB::raw('COUNT(ai_usages.id) as total_messages')
                )
                ->groupBy('users.id', 'users.name');
        }

        $usageByUser = $usageByUserQuery
            ->orderByDesc('total_messages')
            ->limit(50)
            ->get()
            ->map(fn ($row) => array_filter([
                'user_id' => (int) $row->user_id,
                'name' => (string) ($row->user_name ?: 'Sem nome'),
                'company_name' => $allCompanies ? (string) ($row->company_name ?? '-') : null,
                'count' => (int) $row->total_messages,
            ], fn ($v) => $v !== null))
            ->values();

        $toolsUsage = (clone $baseQuery)
            ->whereBetween('ai_usages.created_at', [$startDate, $endDate])
            ->whereNotNull('ai_usages.tool_used')
            ->whereRaw("TRIM(COALESCE(ai_usages.tool_used, '')) <> ''")
            ->select('ai_usages.tool_used', DB::raw('COUNT(*) as total_uses'))
            ->groupBy('ai_usages.tool_used')
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
            'is_admin' => $user->isSystemAdmin(),
            'companies' => $companies,
            'selected_company_id' => $allCompanies ? 'all' : $companyId,
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
