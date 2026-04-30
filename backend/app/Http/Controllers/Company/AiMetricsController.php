<?php

declare(strict_types=1);


namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Models\AiSuggestionFeedback;
use App\Models\AiUsageLog;
use App\Models\Company;
use App\Models\User;
use App\Services\Ai\AiAccessService;
use App\Support\CacheKeys;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Endpoint de observabilidade técnica para chamadas de IA.
 *
 * Expostos: latência, taxa de erro, tokens, breakdown por provider/feature.
 * NÃO exposto: conteúdo de mensagens, prompts ou respostas.
 *
 * Acesso: company_admin (canManageAi) ou system_admin.
 */
class AiMetricsController extends Controller
{
    public function __construct(
        private readonly AiAccessService $aiAccess
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User || ! (bool) $user->is_active) {
            return response()->json(['authenticated' => false, 'redirect' => '/entrar'], 403);
        }

        if (! $this->aiAccess->canManageAi($user)) {
            return response()->json([
                'authenticated' => true,
                'message' => 'Somente admin da empresa pode acessar métricas de IA.',
            ], 403);
        }

        // ── Resolução de empresa ───────────────────────────────────────────────
        $companies = $user->isSystemAdmin()
            ? Company::orderBy('name')->get(['id', 'name'])
            : null;

        [$companyId, $allCompanies] = $this->resolveCompanyScope($request, $user);

        // ── Período ───────────────────────────────────────────────────────────
        [$dateFrom, $dateTo] = $this->resolveDateRange($request);

        // ── Filtros opcionais ─────────────────────────────────────────────────
        $filterProvider = trim((string) $request->query('provider', ''));
        $filterFeature = trim((string) $request->query('feature', ''));

        // ── Cache ─────────────────────────────────────────────────────────────
        // Dados analíticos históricos toleram 5 min de defasagem. O ganho é alto:
        // as queries abaixo fazem 6-7 round-trips com aggregations pesados e
        // o PERCENTILE_CONT é especialmente caro em tabelas grandes.
        $cacheKey = CacheKeys::aiMetrics(
            companyScope: $allCompanies ? 'all' : (string) $companyId,
            dateFrom: $dateFrom->toDateString(),
            dateTo: $dateTo->toDateString(),
            provider: $filterProvider,
            feature: $filterFeature,
        );

        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return response()->json($cached);
        }

        // ── Query base ────────────────────────────────────────────────────────
        $base = AiUsageLog::query()
            ->whereBetween('created_at', [$dateFrom, $dateTo]);

        if (! $allCompanies && $companyId !== null) {
            $base->where('company_id', $companyId);
        }

        if ($filterProvider !== '') {
            $base->where('provider', $filterProvider);
        }

        if ($filterFeature !== '' && in_array($filterFeature, AiUsageLog::ALLOWED_FEATURES, true)) {
            $base->where('feature', $filterFeature);
        }

        // ── Sumário geral ─────────────────────────────────────────────────────
        $summaryRow = (clone $base)
            ->selectRaw(implode(', ', [
                'COUNT(*) as total_requests',
                "SUM(CASE WHEN status = 'ok' THEN 1 ELSE 0 END) as ok_count",
                "SUM(CASE WHEN status = 'error' THEN 1 ELSE 0 END) as error_count",
                'AVG(response_time_ms) as avg_response_time_ms',
                'SUM(COALESCE(tokens_used, 0)) as total_tokens',
            ]))
            ->first();

        $totalRequests = (int) ($summaryRow->total_requests ?? 0);
        $okCount = (int) ($summaryRow->ok_count ?? 0);
        $errorCount = (int) ($summaryRow->error_count ?? 0);
        $avgMs = $summaryRow->avg_response_time_ms !== null
            ? (int) round((float) $summaryRow->avg_response_time_ms)
            : null;
        $totalTokens = (int) ($summaryRow->total_tokens ?? 0);
        $errorRatePct = $totalRequests > 0 ? round($errorCount / $totalRequests * 100, 2) : 0.0;

        // P95 de latência — apenas PostgreSQL suporta percentile_cont
        $p95Ms = $this->computeP95(clone $base, $dateFrom, $dateTo);

        // ── Breakdown por feature ─────────────────────────────────────────────
        $byFeature = (clone $base)
            ->selectRaw(implode(', ', [
                'COALESCE(feature, type) as feature_name',
                'COUNT(*) as total',
                "SUM(CASE WHEN status = 'ok' THEN 1 ELSE 0 END) as ok_count",
                "SUM(CASE WHEN status = 'error' THEN 1 ELSE 0 END) as error_count",
                'AVG(response_time_ms) as avg_ms',
                'SUM(COALESCE(tokens_used, 0)) as tokens',
            ]))
            ->groupByRaw('COALESCE(feature, type)')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => [
                'feature' => (string) $row->feature_name,
                'total' => (int) $row->total,
                'ok' => (int) $row->ok_count,
                'error' => (int) $row->error_count,
                'avg_ms' => $row->avg_ms !== null ? (int) round((float) $row->avg_ms) : null,
                'tokens' => (int) $row->tokens,
            ])
            ->values();

        // ── Breakdown por provider ────────────────────────────────────────────
        $byProvider = (clone $base)
            ->whereNotNull('provider')
            ->selectRaw(implode(', ', [
                'provider',
                'COUNT(*) as total',
                "SUM(CASE WHEN status = 'ok' THEN 1 ELSE 0 END) as ok_count",
                "SUM(CASE WHEN status = 'error' THEN 1 ELSE 0 END) as error_count",
                'AVG(response_time_ms) as avg_ms',
                'SUM(COALESCE(tokens_used, 0)) as tokens',
            ]))
            ->groupBy('provider')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => [
                'provider' => (string) $row->provider,
                'total' => (int) $row->total,
                'ok' => (int) $row->ok_count,
                'error' => (int) $row->error_count,
                'avg_ms' => $row->avg_ms !== null ? (int) round((float) $row->avg_ms) : null,
                'tokens' => (int) $row->tokens,
            ])
            ->values();

        // ── Distribuição de tipos de erro ─────────────────────────────────────
        $byErrorType = (clone $base)
            ->where('status', AiUsageLog::STATUS_ERROR)
            ->whereNotNull('error_type')
            ->selectRaw('error_type, COUNT(*) as total')
            ->groupBy('error_type')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => [
                'error_type' => (string) $row->error_type,
                'count' => (int) $row->total,
            ])
            ->values();

        // ── Série temporal diária ─────────────────────────────────────────────
        $dailyRows = (clone $base)
            ->selectRaw(implode(', ', [
                'DATE(created_at) as day',
                'COUNT(*) as total',
                "SUM(CASE WHEN status = 'error' THEN 1 ELSE 0 END) as errors",
                'AVG(response_time_ms) as avg_ms',
                'SUM(COALESCE(tokens_used, 0)) as tokens',
            ]))
            ->groupBy('day')
            ->orderBy('day')
            ->get();

        $dailyMap = [];
        foreach ($dailyRows as $row) {
            $dailyMap[(string) $row->day] = $row;
        }

        $daily = [];
        foreach (CarbonPeriod::create($dateFrom->copy()->startOfDay(), '1 day', $dateTo->copy()->startOfDay()) as $date) {
            $key = $date->format('Y-m-d');
            $row = $dailyMap[$key] ?? null;
            $daily[] = [
                'date' => $key,
                'label' => $date->format('d/m'),
                'total' => $row ? (int) $row->total : 0,
                'errors' => $row ? (int) $row->errors : 0,
                'avg_ms' => ($row && $row->avg_ms !== null) ? (int) round((float) $row->avg_ms) : null,
                'tokens' => $row ? (int) $row->tokens : 0,
            ];
        }

        // ── Feedback de sugestões ─────────────────────────────────────────────
        $feedbackQuery = AiSuggestionFeedback::query()
            ->join('ai_usage_logs', 'ai_suggestion_feedback.suggestion_id', '=', 'ai_usage_logs.id')
            ->whereBetween('ai_suggestion_feedback.created_at', [$dateFrom, $dateTo]);

        if (! $allCompanies && $companyId !== null) {
            $feedbackQuery->where('ai_usage_logs.company_id', $companyId);
        }

        $feedbackTotal   = (clone $feedbackQuery)->count();
        $feedbackHelpful = (clone $feedbackQuery)->where('ai_suggestion_feedback.helpful', true)->count();
        $helpfulPct      = $feedbackTotal > 0 ? round($feedbackHelpful / $feedbackTotal * 100, 1) : null;

        $payload = [
            'authenticated' => true,
            'is_admin' => $user->isSystemAdmin(),
            'companies' => $companies,
            'selected_company_id' => $allCompanies ? 'all' : $companyId,
            'range' => [
                'from' => $dateFrom->toDateString(),
                'to' => $dateTo->toDateString(),
            ],
            'filters' => [
                'provider' => $filterProvider ?: null,
                'feature' => $filterFeature ?: null,
            ],
            'summary' => [
                'total_requests' => $totalRequests,
                'ok_count' => $okCount,
                'error_count' => $errorCount,
                'error_rate_pct' => $errorRatePct,
                'avg_response_time_ms' => $avgMs,
                'p95_response_time_ms' => $p95Ms,
                'total_tokens' => $totalTokens,
                'feedback_total' => $feedbackTotal,
                'feedback_helpful_pct' => $helpfulPct,
            ],
            'by_feature' => $byFeature,
            'by_provider' => $byProvider,
            'by_error_type' => $byErrorType,
            'daily' => $daily,
        ];

        Cache::put($cacheKey, $payload, now()->addMinutes(5));

        return response()->json($payload);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * @return array{0: int|null, 1: bool}
     */
    private function resolveCompanyScope(Request $request, User $user): array
    {
        if (! $user->isSystemAdmin()) {
            return [(int) $user->company_id, false];
        }

        $param = trim((string) $request->query('company_id', ''));
        if ($param === 'all' || $param === '0' || $param === '') {
            return [null, true];
        }

        return [(int) $param, false];
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function resolveDateRange(Request $request): array
    {
        $from = trim((string) $request->query('date_from', ''));
        $to = trim((string) $request->query('date_to', ''));

        $dateFrom = $from !== '' ? Carbon::parse($from)->startOfDay() : Carbon::now()->subDays(29)->startOfDay();
        $dateTo = $to !== '' ? Carbon::parse($to)->endOfDay() : Carbon::now()->endOfDay();

        // Máximo 90 dias para evitar queries pesadas
        if ($dateFrom->diffInDays($dateTo) > 90) {
            $dateFrom = $dateTo->copy()->subDays(89)->startOfDay();
        }

        return [$dateFrom, $dateTo];
    }

    /**
     * Calcula o percentil 95 de latência.
     * Retorna null em drivers sem suporte a percentile_cont (SQLite).
     *
     * @param  \Illuminate\Database\Eloquent\Builder<AiUsageLog>  $query
     */
    private function computeP95(\Illuminate\Database\Eloquent\Builder $query, Carbon $dateFrom, Carbon $dateTo): ?int
    {
        if (DB::getDriverName() !== 'pgsql') {
            return null;
        }

        $row = $query
            ->whereNotNull('response_time_ms')
            ->selectRaw('PERCENTILE_CONT(0.95) WITHIN GROUP (ORDER BY response_time_ms) as p95')
            ->first();

        if ($row === null || $row->p95 === null) {
            return null;
        }

        return (int) round((float) $row->p95);
    }
}
