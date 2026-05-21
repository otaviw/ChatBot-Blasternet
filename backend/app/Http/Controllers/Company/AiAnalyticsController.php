<?php

declare(strict_types=1);

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Models\AiChatbotDecisionLog;
use App\Models\AiUsage;
use App\Models\AiUsageLog;
use App\Models\Area;
use App\Models\Company;
use App\Models\User;
use App\Services\Ai\AiAccessService;
use App\Services\Ai\ChatbotAiPolicyService;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Throwable;

class AiAnalyticsController extends Controller
{
    private const CHANNEL_ALL = 'all';
    private const CHANNEL_WHATSAPP = 'whatsapp';
    private const CHANNEL_INTERNAL_CHAT = 'internal_chat';

    public function __construct(
        private readonly AiAccessService $aiAccess
    ) {}

    public function index(Request $request): JsonResponse|Response
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

        [$companyId, $allCompanies] = $this->resolveCompanyScope($request, $user);
        [$dateFrom, $dateTo, $days] = $this->resolveDateRange($request);

        $filters = [
            'channel' => $this->normalizeChannel($request->query('channel')),
            'area_id' => $this->positiveIntOrNull($request->query('area_id')),
            'flow' => $this->normalizeOptionalFilter($request->query('flow')),
            'provider' => $this->normalizeOptionalFilter($request->query('provider')),
            'feature' => $this->normalizeOptionalFilter($request->query('feature')),
        ];

        $payload = $this->buildDashboardPayload(
            request: $request,
            user: $user,
            companyId: $companyId,
            allCompanies: $allCompanies,
            dateFrom: $dateFrom,
            dateTo: $dateTo,
            days: $days,
            filters: $filters,
        );

        if (mb_strtolower(trim((string) $request->query('export', ''))) === 'csv') {
            return response($this->toCsv($payload), 200, [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="ia-analytics.csv"',
            ]);
        }

        return response()->json($payload);
    }

    /**
     * @param  array{channel:string,area_id:int|null,flow:string|null,provider:string|null,feature:string|null}  $filters
     * @return array<string, mixed>
     */
    private function buildDashboardPayload(
        Request $request,
        User $user,
        ?int $companyId,
        bool $allCompanies,
        Carbon $dateFrom,
        Carbon $dateTo,
        int $days,
        array $filters
    ): array {
        $companies = $user->isSystemAdmin()
            ? Company::orderBy('name')->get(['id', 'name'])
            : null;

        $usageBase = AiUsageLog::query()
            ->whereBetween('created_at', [$dateFrom, $dateTo]);

        $decisionBase = AiChatbotDecisionLog::query()
            ->whereBetween('created_at', [$dateFrom, $dateTo]);

        $this->applyCompanyScope($usageBase, $companyId, $allCompanies);
        $this->applyCompanyScope($decisionBase, $companyId, $allCompanies);

        $this->applyUsageFilters($usageBase, $filters);
        $this->applyDecisionFilters($decisionBase, $filters);

        $usageSummary = $this->usageSummary(clone $usageBase);
        $decisionSummary = $this->decisionSummary(clone $decisionBase);

        $providerTokens = (int) $usageSummary['total_tokens'];
        $chatbotDecisionTokens = (int) $decisionSummary['total_tokens'];
        $totalTokens = $providerTokens + $chatbotDecisionTokens;
        $estimatedCostPer1k = (float) config('ai.analytics.estimated_cost_per_1k_tokens', 0);
        $estimatedCost = round(($totalTokens / 1000) * $estimatedCostPer1k, 4);

        $totalQualityBase = (int) $decisionSummary['total_decisions'];
        $handoffCount = (int) $decisionSummary['handoff_count'];
        $resolvedCount = (int) $decisionSummary['resolved_count'];
        $decisionFailureCount = (int) $decisionSummary['failure_count'];
        $providerErrorCount = (int) $usageSummary['error_count'];
        $providerRequestCount = (int) $usageSummary['total_requests'];

        $daily = $this->dailySeries(clone $usageBase, clone $decisionBase, $dateFrom, $dateTo);

        $payload = [
            'authenticated' => true,
            'is_admin' => $user->isSystemAdmin(),
            'companies' => $companies,
            'selected_company_id' => $allCompanies ? 'all' : $companyId,
            'days' => $days,
            'range' => [
                'from' => $dateFrom->toDateString(),
                'to' => $dateTo->toDateString(),
                'start' => $dateFrom->toDateString(),
                'end' => $dateTo->toDateString(),
            ],
            'filters' => $filters,
            'filter_options' => $this->filterOptions($companyId, $allCompanies, $dateFrom, $dateTo),
            'export_urls' => [
                'json' => $request->fullUrlWithQuery(['export' => 'json']),
                'csv' => $request->fullUrlWithQuery(['export' => 'csv']),
            ],
            'summary' => [
                'total_requests' => $providerRequestCount,
                'provider_requests' => $providerRequestCount,
                'chatbot_decisions' => $totalQualityBase,
                'total_messages' => $providerRequestCount,
                'total_month' => $this->usageTotalCurrentMonth($companyId, $allCompanies),
                'total_users_period' => $this->usageUsersPeriod($companyId, $allCompanies, $dateFrom, $dateTo),
                'ok_count' => (int) $usageSummary['ok_count'],
                'error_count' => $providerErrorCount,
                'failure_count' => $providerErrorCount + $decisionFailureCount,
                'failure_rate_pct' => $this->percentage($providerErrorCount + $decisionFailureCount, $providerRequestCount + $totalQualityBase),
                'avg_response_time_ms' => $usageSummary['avg_response_time_ms'],
                'avg_decision_latency_ms' => $decisionSummary['avg_latency_ms'],
                'total_tokens' => $totalTokens,
                'provider_tokens' => $providerTokens,
                'chatbot_decision_tokens' => $chatbotDecisionTokens,
                'estimated_cost' => $estimatedCost,
                'estimated_cost_currency' => (string) config('ai.analytics.currency', 'USD'),
                'estimated_cost_per_1k_tokens' => $estimatedCostPer1k,
                'resolved_count' => $resolvedCount,
                'resolution_rate_pct' => $totalQualityBase > 0 ? $this->percentage($resolvedCount, $totalQualityBase) : null,
                'handoff_count' => $handoffCount,
                'handoff_rate_pct' => $totalQualityBase > 0 ? $this->percentage($handoffCount, $totalQualityBase) : null,
                'handoff_menu_count' => (int) $decisionSummary['handoff_menu_count'],
                'handoff_incapacity_count' => (int) $decisionSummary['handoff_incapacity_count'],
                'avg_confidence' => $decisionSummary['avg_confidence'],
                'last_event_at' => $this->lastEventAt(clone $usageBase, clone $decisionBase),
            ],
            'daily' => $daily,
            'daily_messages' => array_map(fn (array $row): array => [
                'date' => $row['date'],
                'label' => $row['label'],
                'count' => $row['provider_requests'],
            ], $daily),
            'by_feature' => $this->byFeature(clone $usageBase, clone $decisionBase),
            'by_provider' => $this->byProvider(clone $usageBase, clone $decisionBase),
            'by_error_type' => $this->byErrorType(clone $usageBase),
            'usage_by_user' => $this->usageByUser($companyId, $allCompanies, $dateFrom, $dateTo),
            'tools_usage' => $this->toolsUsage($companyId, $allCompanies, $dateFrom, $dateTo),
            'top_intents' => $this->topIntents(clone $decisionBase),
            'handoff_by_type' => $this->handoffByType(clone $decisionBase),
            'handoff_reasons' => $this->handoffReasons(clone $decisionBase),
            'bottlenecks_by_flow' => $this->bottlenecksByFlow(clone $decisionBase),
        ];

        return $payload;
    }

    /**
     * @return array{0:int|null,1:bool}
     */
    private function resolveCompanyScope(Request $request, User $user): array
    {
        if (! $user->isSystemAdmin()) {
            return [(int) $user->company_id, false];
        }

        $companyIdParam = trim((string) $request->query('company_id', ''));
        if ($companyIdParam === '' || $companyIdParam === 'all' || $companyIdParam === '0') {
            return [null, true];
        }

        return [(int) $companyIdParam, false];
    }

    /**
     * @return array{0:Carbon,1:Carbon,2:int}
     */
    private function resolveDateRange(Request $request): array
    {
        $days = (int) $request->integer('days', 30);
        $days = in_array($days, [7, 30, 90], true) ? $days : 30;

        $from = trim((string) $request->query('date_from', ''));
        $to = trim((string) $request->query('date_to', ''));

        try {
            $dateFrom = $from !== '' ? Carbon::parse($from)->startOfDay() : Carbon::now()->startOfDay()->subDays($days - 1);
        } catch (Throwable) {
            $dateFrom = Carbon::now()->startOfDay()->subDays($days - 1);
        }

        try {
            $dateTo = $to !== '' ? Carbon::parse($to)->endOfDay() : Carbon::now()->endOfDay();
        } catch (Throwable) {
            $dateTo = Carbon::now()->endOfDay();
        }

        if ($dateFrom->greaterThan($dateTo)) {
            [$dateFrom, $dateTo] = [$dateTo->copy()->startOfDay(), $dateFrom->copy()->endOfDay()];
        }

        if ($dateFrom->diffInDays($dateTo) > 180) {
            $dateFrom = $dateTo->copy()->subDays(179)->startOfDay();
        }

        return [$dateFrom, $dateTo, (int) $dateFrom->diffInDays($dateTo) + 1];
    }

    /**
     * @param  Builder<AiUsageLog|AiChatbotDecisionLog>  $query
     */
    private function applyCompanyScope(Builder $query, ?int $companyId, bool $allCompanies): void
    {
        if (! $allCompanies && $companyId !== null) {
            $query->where('company_id', $companyId);
        }
    }

    /**
     * @param  Builder<AiUsageLog>  $query
     * @param  array{channel:string,area_id:int|null,flow:string|null,provider:string|null,feature:string|null}  $filters
     */
    private function applyUsageFilters(Builder $query, array $filters): void
    {
        if ($filters['channel'] === self::CHANNEL_INTERNAL_CHAT) {
            $query->where(function (Builder $q): void {
                $q->where('feature', AiUsageLog::FEATURE_INTERNAL_CHAT)
                    ->orWhere('type', AiUsageLog::TYPE_INTERNAL_CHAT);
            });
        } elseif ($filters['channel'] === self::CHANNEL_WHATSAPP) {
            $query->where(function (Builder $q): void {
                $q->where('feature', '<>', AiUsageLog::FEATURE_INTERNAL_CHAT)
                    ->orWhere('type', AiUsageLog::TYPE_CHATBOT);
            });
        }

        if ($filters['provider'] !== null) {
            $query->where('provider', $filters['provider']);
        }

        if ($filters['feature'] !== null && in_array($filters['feature'], AiUsageLog::ALLOWED_FEATURES, true)) {
            $query->where('feature', $filters['feature']);
        }

        if ($filters['area_id'] !== null || $filters['flow'] !== null) {
            // AiUsageLog nao possui area/fluxo de atendimento; esses filtros sao confiaveis nos logs de decisao.
            $query->whereRaw('1 = 0');
        }
    }

    /**
     * @param  Builder<AiChatbotDecisionLog>  $query
     * @param  array{channel:string,area_id:int|null,flow:string|null,provider:string|null,feature:string|null}  $filters
     */
    private function applyDecisionFilters(Builder $query, array $filters): void
    {
        if ($filters['channel'] === self::CHANNEL_INTERNAL_CHAT) {
            $query->whereRaw('1 = 0');
        } elseif ($filters['channel'] === self::CHANNEL_WHATSAPP) {
            $query->where('channel', AiChatbotDecisionLog::CHANNEL_WHATSAPP);
        }

        if ($filters['provider'] !== null) {
            $query->where('provider', $filters['provider']);
        }

        if ($filters['feature'] !== null && $filters['feature'] !== AiUsageLog::FEATURE_CHATBOT) {
            $query->whereRaw('1 = 0');
        }

        if ($filters['area_id'] !== null) {
            $areaId = $filters['area_id'];
            $query->where(function (Builder $q) use ($areaId): void {
                $q->where('handoff_area_id', $areaId)
                    ->orWhereHas('conversation', function (Builder $conversationQuery) use ($areaId): void {
                        $conversationQuery->where('current_area_id', $areaId);
                    });
            });
        }

        if ($filters['flow'] !== null) {
            $query->where('flow', $filters['flow']);
        }
    }

    /**
     * @param  Builder<AiUsageLog>  $query
     * @return array{total_requests:int,ok_count:int,error_count:int,avg_response_time_ms:int|null,total_tokens:int}
     */
    private function usageSummary(Builder $query): array
    {
        $row = $query
            ->selectRaw(implode(', ', [
                'COUNT(*) as total_requests',
                "SUM(CASE WHEN status = 'ok' THEN 1 ELSE 0 END) as ok_count",
                "SUM(CASE WHEN status = 'error' THEN 1 ELSE 0 END) as error_count",
                'AVG(response_time_ms) as avg_ms',
                'SUM(COALESCE(tokens_used, 0)) as total_tokens',
            ]))
            ->first();

        return [
            'total_requests' => (int) ($row->total_requests ?? 0),
            'ok_count' => (int) ($row->ok_count ?? 0),
            'error_count' => (int) ($row->error_count ?? 0),
            'avg_response_time_ms' => $row?->avg_ms !== null ? (int) round((float) $row->avg_ms) : null,
            'total_tokens' => (int) ($row->total_tokens ?? 0),
        ];
    }

    /**
     * @param  Builder<AiChatbotDecisionLog>  $query
     * @return array<string, int|float|null>
     */
    private function decisionSummary(Builder $query): array
    {
        $row = $query
            ->selectRaw(implode(', ', [
                'COUNT(*) as total_decisions',
                'SUM(COALESCE(tokens_used, 0)) as total_tokens',
                'AVG(latency_ms) as avg_latency_ms',
                'AVG(confidence) as avg_confidence',
                "SUM(CASE WHEN COALESCE(handoff_type, '') <> '' OR action = 'handoff' THEN 1 ELSE 0 END) as handoff_count",
                "SUM(CASE WHEN handoff_type = 'menu' THEN 1 ELSE 0 END) as handoff_menu_count",
                "SUM(CASE WHEN handoff_type = 'incapacity' OR (COALESCE(handoff_type, '') = '' AND action = 'handoff') THEN 1 ELSE 0 END) as handoff_incapacity_count",
                "SUM(CASE WHEN action IN ('suggest_reply', 'route_to_appointment_flow', 'extract_only') AND COALESCE(handoff_type, '') = '' AND (error IS NULL OR TRIM(error) = '') THEN 1 ELSE 0 END) as resolved_count",
                "SUM(CASE WHEN error IS NOT NULL AND TRIM(error) <> '' THEN 1 ELSE 0 END) as failure_count",
            ]))
            ->first();

        return [
            'total_decisions' => (int) ($row->total_decisions ?? 0),
            'total_tokens' => (int) ($row->total_tokens ?? 0),
            'avg_latency_ms' => $row?->avg_latency_ms !== null ? (int) round((float) $row->avg_latency_ms) : null,
            'avg_confidence' => $row?->avg_confidence !== null ? round((float) $row->avg_confidence, 3) : null,
            'handoff_count' => (int) ($row->handoff_count ?? 0),
            'handoff_menu_count' => (int) ($row->handoff_menu_count ?? 0),
            'handoff_incapacity_count' => (int) ($row->handoff_incapacity_count ?? 0),
            'resolved_count' => (int) ($row->resolved_count ?? 0),
            'failure_count' => (int) ($row->failure_count ?? 0),
        ];
    }

    /**
     * @param  Builder<AiUsageLog>  $usageQuery
     * @param  Builder<AiChatbotDecisionLog>  $decisionQuery
     * @return list<array<string, mixed>>
     */
    private function dailySeries(Builder $usageQuery, Builder $decisionQuery, Carbon $dateFrom, Carbon $dateTo): array
    {
        $usageRows = $usageQuery
            ->selectRaw(implode(', ', [
                'DATE(created_at) as day',
                'COUNT(*) as provider_requests',
                'SUM(COALESCE(tokens_used, 0)) as provider_tokens',
                "SUM(CASE WHEN status = 'error' THEN 1 ELSE 0 END) as provider_errors",
            ]))
            ->groupBy('day')
            ->get()
            ->keyBy(fn ($row): string => (string) $row->day);

        $decisionRows = $decisionQuery
            ->selectRaw(implode(', ', [
                'DATE(created_at) as day',
                'COUNT(*) as chatbot_decisions',
                'SUM(COALESCE(tokens_used, 0)) as decision_tokens',
                "SUM(CASE WHEN COALESCE(handoff_type, '') <> '' OR action = 'handoff' THEN 1 ELSE 0 END) as handoffs",
                "SUM(CASE WHEN error IS NOT NULL AND TRIM(error) <> '' THEN 1 ELSE 0 END) as decision_failures",
            ]))
            ->groupBy('day')
            ->get()
            ->keyBy(fn ($row): string => (string) $row->day);

        $items = [];
        foreach (CarbonPeriod::create($dateFrom->copy()->startOfDay(), '1 day', $dateTo->copy()->startOfDay()) as $date) {
            $key = $date->format('Y-m-d');
            $usage = $usageRows->get($key);
            $decision = $decisionRows->get($key);
            $providerTokens = $usage ? (int) $usage->provider_tokens : 0;
            $decisionTokens = $decision ? (int) $decision->decision_tokens : 0;

            $items[] = [
                'date' => $key,
                'label' => $date->format('d/m'),
                'provider_requests' => $usage ? (int) $usage->provider_requests : 0,
                'chatbot_decisions' => $decision ? (int) $decision->chatbot_decisions : 0,
                'tokens' => $providerTokens + $decisionTokens,
                'provider_tokens' => $providerTokens,
                'decision_tokens' => $decisionTokens,
                'handoffs' => $decision ? (int) $decision->handoffs : 0,
                'failures' => ($usage ? (int) $usage->provider_errors : 0) + ($decision ? (int) $decision->decision_failures : 0),
            ];
        }

        return $items;
    }

    /**
     * @param  Builder<AiUsageLog>  $usageQuery
     * @param  Builder<AiChatbotDecisionLog>  $decisionQuery
     * @return list<array<string, mixed>>
     */
    private function byFeature(Builder $usageQuery, Builder $decisionQuery): array
    {
        $buckets = [];

        $usageQuery
            ->selectRaw(implode(', ', [
                'COALESCE(feature, type) as feature',
                'COUNT(*) as total',
                "SUM(CASE WHEN status = 'error' THEN 1 ELSE 0 END) as error",
                'AVG(response_time_ms) as avg_ms',
                'SUM(COALESCE(tokens_used, 0)) as tokens',
            ]))
            ->groupByRaw('COALESCE(feature, type)')
            ->get()
            ->each(function ($row) use (&$buckets): void {
                $this->mergeMetricBucket(
                    buckets: $buckets,
                    labelKey: 'feature',
                    label: (string) ($row->feature ?: AiUsageLog::FEATURE_CHATBOT),
                    total: (int) $row->total,
                    error: (int) $row->error,
                    avgMs: $row->avg_ms !== null ? (int) round((float) $row->avg_ms) : null,
                    tokens: (int) $row->tokens,
                );
            });

        $decisionRow = $decisionQuery
            ->selectRaw(implode(', ', [
                'COUNT(*) as total',
                "SUM(CASE WHEN error IS NOT NULL AND TRIM(error) <> '' THEN 1 ELSE 0 END) as error",
                'AVG(latency_ms) as avg_ms',
                'SUM(COALESCE(tokens_used, 0)) as tokens',
            ]))
            ->first();

        if ((int) ($decisionRow->total ?? 0) > 0) {
            $this->mergeMetricBucket(
                buckets: $buckets,
                labelKey: 'feature',
                label: AiUsageLog::FEATURE_CHATBOT,
                total: (int) $decisionRow->total,
                error: (int) $decisionRow->error,
                avgMs: $decisionRow->avg_ms !== null ? (int) round((float) $decisionRow->avg_ms) : null,
                tokens: (int) $decisionRow->tokens,
            );
        }

        return $this->finalizeMetricBuckets($buckets);
    }

    /**
     * @param  Builder<AiUsageLog>  $usageQuery
     * @param  Builder<AiChatbotDecisionLog>  $decisionQuery
     * @return list<array<string, mixed>>
     */
    private function byProvider(Builder $usageQuery, Builder $decisionQuery): array
    {
        $buckets = [];

        $usageQuery
            ->whereNotNull('provider')
            ->selectRaw(implode(', ', [
                'provider',
                'COUNT(*) as total',
                "SUM(CASE WHEN status = 'error' THEN 1 ELSE 0 END) as error",
                'AVG(response_time_ms) as avg_ms',
                'SUM(COALESCE(tokens_used, 0)) as tokens',
            ]))
            ->groupBy('provider')
            ->get()
            ->each(function ($row) use (&$buckets): void {
                $this->mergeMetricBucket(
                    buckets: $buckets,
                    labelKey: 'provider',
                    label: (string) $row->provider,
                    total: (int) $row->total,
                    error: (int) $row->error,
                    avgMs: $row->avg_ms !== null ? (int) round((float) $row->avg_ms) : null,
                    tokens: (int) $row->tokens,
                );
            });

        $decisionQuery
            ->whereNotNull('provider')
            ->selectRaw(implode(', ', [
                'provider',
                'COUNT(*) as total',
                "SUM(CASE WHEN error IS NOT NULL AND TRIM(error) <> '' THEN 1 ELSE 0 END) as error",
                'AVG(latency_ms) as avg_ms',
                'SUM(COALESCE(tokens_used, 0)) as tokens',
            ]))
            ->groupBy('provider')
            ->get()
            ->each(function ($row) use (&$buckets): void {
                $this->mergeMetricBucket(
                    buckets: $buckets,
                    labelKey: 'provider',
                    label: (string) $row->provider,
                    total: (int) $row->total,
                    error: (int) $row->error,
                    avgMs: $row->avg_ms !== null ? (int) round((float) $row->avg_ms) : null,
                    tokens: (int) $row->tokens,
                );
            });

        return $this->finalizeMetricBuckets($buckets);
    }

    /**
     * @param  array<string, array<string, mixed>>  $buckets
     */
    private function mergeMetricBucket(
        array &$buckets,
        string $labelKey,
        string $label,
        int $total,
        int $error,
        ?int $avgMs,
        int $tokens
    ): void {
        if ($label === '' || $total <= 0) {
            return;
        }

        if (! isset($buckets[$label])) {
            $buckets[$label] = [
                $labelKey => $label,
                'total' => 0,
                'error' => 0,
                'avg_ms' => null,
                'tokens' => 0,
                '_weighted_ms' => 0,
                '_weighted_count' => 0,
            ];
        }

        $buckets[$label]['total'] = (int) $buckets[$label]['total'] + $total;
        $buckets[$label]['error'] = (int) $buckets[$label]['error'] + $error;
        $buckets[$label]['tokens'] = (int) $buckets[$label]['tokens'] + $tokens;

        if ($avgMs !== null) {
            $buckets[$label]['_weighted_ms'] = (int) $buckets[$label]['_weighted_ms'] + ($avgMs * $total);
            $buckets[$label]['_weighted_count'] = (int) $buckets[$label]['_weighted_count'] + $total;
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $buckets
     * @return list<array<string, mixed>>
     */
    private function finalizeMetricBuckets(array $buckets): array
    {
        $items = array_map(function (array $bucket): array {
            $weightedCount = (int) ($bucket['_weighted_count'] ?? 0);
            $bucket['avg_ms'] = $weightedCount > 0
                ? (int) round(((int) $bucket['_weighted_ms']) / $weightedCount)
                : null;

            unset($bucket['_weighted_ms'], $bucket['_weighted_count']);

            return $bucket;
        }, array_values($buckets));

        usort($items, fn (array $a, array $b): int => ((int) $b['total']) <=> ((int) $a['total']));

        return $items;
    }

    /**
     * @param  Builder<AiUsageLog>  $query
     * @return list<array{error_type:string,count:int}>
     */
    private function byErrorType(Builder $query): array
    {
        return $query
            ->where('status', AiUsageLog::STATUS_ERROR)
            ->whereNotNull('error_type')
            ->selectRaw('error_type, COUNT(*) as total')
            ->groupBy('error_type')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row): array => [
                'error_type' => (string) $row->error_type,
                'count' => (int) $row->total,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  Builder<AiChatbotDecisionLog>  $query
     * @return list<array<string, mixed>>
     */
    private function topIntents(Builder $query): array
    {
        return $query
            ->whereNotNull('intent')
            ->selectRaw(implode(', ', [
                'intent',
                'COUNT(*) as total',
                "SUM(CASE WHEN COALESCE(handoff_type, '') <> '' OR action = 'handoff' THEN 1 ELSE 0 END) as handoffs",
                'AVG(confidence) as avg_confidence',
            ]))
            ->groupBy('intent')
            ->orderByDesc('total')
            ->limit(20)
            ->get()
            ->map(fn ($row): array => [
                'intent' => (string) $row->intent,
                'total' => (int) $row->total,
                'handoffs' => (int) $row->handoffs,
                'avg_confidence' => $row->avg_confidence !== null ? round((float) $row->avg_confidence, 3) : null,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  Builder<AiChatbotDecisionLog>  $query
     * @return list<array{type:string,count:int}>
     */
    private function handoffByType(Builder $query): array
    {
        return $query
            ->where(function (Builder $q): void {
                $q->whereNotNull('handoff_type')
                    ->orWhere('action', ChatbotAiPolicyService::ACTION_HANDOFF);
            })
            ->selectRaw("CASE WHEN handoff_type = 'menu' THEN 'menu' ELSE 'incapacity' END as type, COUNT(*) as total")
            ->groupByRaw("CASE WHEN handoff_type = 'menu' THEN 'menu' ELSE 'incapacity' END")
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row): array => [
                'type' => (string) $row->type,
                'count' => (int) $row->total,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  Builder<AiChatbotDecisionLog>  $query
     * @return list<array{reason:string,count:int}>
     */
    private function handoffReasons(Builder $query): array
    {
        return $query
            ->where(function (Builder $q): void {
                $q->whereNotNull('handoff_type')
                    ->orWhere('action', ChatbotAiPolicyService::ACTION_HANDOFF);
            })
            ->selectRaw("COALESCE(NULLIF(handoff_reason, ''), 'sem_motivo') as reason, COUNT(*) as total")
            ->groupByRaw("COALESCE(NULLIF(handoff_reason, ''), 'sem_motivo')")
            ->orderByDesc('total')
            ->limit(20)
            ->get()
            ->map(fn ($row): array => [
                'reason' => (string) $row->reason,
                'count' => (int) $row->total,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  Builder<AiChatbotDecisionLog>  $query
     * @return list<array<string, mixed>>
     */
    private function bottlenecksByFlow(Builder $query): array
    {
        return $query
            ->selectRaw(implode(', ', [
                "COALESCE(NULLIF(flow, ''), 'sem_fluxo') as flow",
                'COUNT(*) as total',
                "SUM(CASE WHEN COALESCE(handoff_type, '') <> '' OR action = 'handoff' THEN 1 ELSE 0 END) as handoffs",
                "SUM(CASE WHEN error IS NOT NULL AND TRIM(error) <> '' THEN 1 ELSE 0 END) as failures",
                'AVG(confidence) as avg_confidence',
            ]))
            ->groupByRaw("COALESCE(NULLIF(flow, ''), 'sem_fluxo')")
            ->orderByDesc('handoffs')
            ->orderByDesc('failures')
            ->limit(20)
            ->get()
            ->map(fn ($row): array => [
                'flow' => (string) $row->flow,
                'total' => (int) $row->total,
                'handoffs' => (int) $row->handoffs,
                'failures' => (int) $row->failures,
                'avg_confidence' => $row->avg_confidence !== null ? round((float) $row->avg_confidence, 3) : null,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function usageByUser(?int $companyId, bool $allCompanies, Carbon $dateFrom, Carbon $dateTo): array
    {
        $query = AiUsage::query()
            ->join('users', 'users.id', '=', 'ai_usages.user_id')
            ->whereBetween('ai_usages.created_at', [$dateFrom, $dateTo]);

        if (! $allCompanies && $companyId !== null) {
            $query->where('ai_usages.company_id', $companyId);
        }

        if ($allCompanies) {
            $query
                ->leftJoin('companies', 'companies.id', '=', 'ai_usages.company_id')
                ->select(
                    'users.id as user_id',
                    'users.name as user_name',
                    'companies.name as company_name',
                    DB::raw('COUNT(ai_usages.id) as total_messages')
                )
                ->groupBy('users.id', 'users.name', 'companies.id', 'companies.name');
        } else {
            $query
                ->select(
                    'users.id as user_id',
                    'users.name as user_name',
                    DB::raw('COUNT(ai_usages.id) as total_messages')
                )
                ->groupBy('users.id', 'users.name');
        }

        return $query
            ->orderByDesc('total_messages')
            ->limit(50)
            ->get()
            ->map(fn ($row): array => array_filter([
                'user_id' => (int) $row->user_id,
                'name' => (string) ($row->user_name ?: 'Sem nome'),
                'company_name' => $allCompanies ? (string) ($row->company_name ?? '-') : null,
                'count' => (int) $row->total_messages,
            ], fn ($v): bool => $v !== null))
            ->values()
            ->all();
    }

    /**
     * @return list<array{tool:string,count:int}>
     */
    private function toolsUsage(?int $companyId, bool $allCompanies, Carbon $dateFrom, Carbon $dateTo): array
    {
        $query = AiUsage::query()
            ->whereBetween('ai_usages.created_at', [$dateFrom, $dateTo])
            ->whereNotNull('ai_usages.tool_used')
            ->whereRaw("TRIM(COALESCE(ai_usages.tool_used, '')) <> ''");

        if (! $allCompanies && $companyId !== null) {
            $query->where('ai_usages.company_id', $companyId);
        }

        return $query
            ->select('ai_usages.tool_used', DB::raw('COUNT(*) as total_uses'))
            ->groupBy('ai_usages.tool_used')
            ->orderByDesc('total_uses')
            ->limit(20)
            ->get()
            ->map(fn ($row): array => [
                'tool' => (string) $row->tool_used,
                'count' => (int) $row->total_uses,
            ])
            ->values()
            ->all();
    }

    private function usageTotalCurrentMonth(?int $companyId, bool $allCompanies): int
    {
        $query = AiUsageLog::query()
            ->whereBetween('created_at', [Carbon::now()->startOfMonth(), Carbon::now()->endOfMonth()]);

        $this->applyCompanyScope($query, $companyId, $allCompanies);

        return (int) $query->count();
    }

    private function usageUsersPeriod(?int $companyId, bool $allCompanies, Carbon $dateFrom, Carbon $dateTo): int
    {
        $query = AiUsageLog::query()
            ->whereBetween('created_at', [$dateFrom, $dateTo])
            ->whereNotNull('user_id');

        $this->applyCompanyScope($query, $companyId, $allCompanies);

        return (int) $query->distinct('user_id')->count('user_id');
    }

    private function lastEventAt(Builder $usageQuery, Builder $decisionQuery): ?string
    {
        $usageLast = $usageQuery->max('created_at');
        $decisionLast = $decisionQuery->max('created_at');

        $dates = array_filter([$usageLast, $decisionLast]);
        if ($dates === []) {
            return null;
        }

        return Carbon::parse(max($dates))->toIso8601String();
    }

    /**
     * @return array<string, mixed>
     */
    private function filterOptions(?int $companyId, bool $allCompanies, Carbon $dateFrom, Carbon $dateTo): array
    {
        $areasQuery = Area::query()->orderBy('name');
        if (! $allCompanies && $companyId !== null) {
            $areasQuery->where('company_id', $companyId);
        }

        $flowsQuery = AiChatbotDecisionLog::query()
            ->whereBetween('created_at', [$dateFrom, $dateTo])
            ->whereNotNull('flow')
            ->whereRaw("TRIM(flow) <> ''");
        $this->applyCompanyScope($flowsQuery, $companyId, $allCompanies);

        $usageProvidersQuery = AiUsageLog::query()
            ->whereBetween('created_at', [$dateFrom, $dateTo])
            ->whereNotNull('provider')
            ->whereRaw("TRIM(provider) <> ''");
        $this->applyCompanyScope($usageProvidersQuery, $companyId, $allCompanies);

        $decisionProvidersQuery = AiChatbotDecisionLog::query()
            ->whereBetween('created_at', [$dateFrom, $dateTo])
            ->whereNotNull('provider')
            ->whereRaw("TRIM(provider) <> ''");
        $this->applyCompanyScope($decisionProvidersQuery, $companyId, $allCompanies);

        $providers = $usageProvidersQuery
            ->select('provider')
            ->distinct()
            ->pluck('provider')
            ->merge(
                $decisionProvidersQuery
                    ->select('provider')
                    ->distinct()
                    ->pluck('provider')
            )
            ->map(fn ($provider): string => (string) $provider)
            ->unique()
            ->sort()
            ->values()
            ->all();

        return [
            'channels' => [
                ['value' => self::CHANNEL_ALL, 'label' => 'Todos os canais'],
                ['value' => self::CHANNEL_WHATSAPP, 'label' => 'WhatsApp'],
                ['value' => self::CHANNEL_INTERNAL_CHAT, 'label' => 'Chat interno'],
            ],
            'areas' => $areasQuery
                ->limit(200)
                ->get(['id', 'company_id', 'name'])
                ->map(fn (Area $area): array => [
                    'id' => (int) $area->id,
                    'company_id' => (int) $area->company_id,
                    'name' => (string) $area->name,
                ])
                ->values()
                ->all(),
            'flows' => $flowsQuery
                ->select('flow')
                ->distinct()
                ->orderBy('flow')
                ->limit(100)
                ->pluck('flow')
                ->map(fn ($flow): string => (string) $flow)
                ->values()
                ->all(),
            'features' => AiUsageLog::ALLOWED_FEATURES,
            'providers' => $providers,
        ];
    }

    private function normalizeChannel(mixed $value): string
    {
        $channel = mb_strtolower(trim((string) $value));

        return in_array($channel, [self::CHANNEL_WHATSAPP, self::CHANNEL_INTERNAL_CHAT], true)
            ? $channel
            : self::CHANNEL_ALL;
    }

    private function normalizeOptionalFilter(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized === '' || $normalized === 'all' ? null : mb_substr($normalized, 0, 120);
    }

    private function positiveIntOrNull(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $int = (int) $value;

        return $int > 0 ? $int : null;
    }

    private function percentage(int $part, int $total): float
    {
        return $total > 0 ? round(($part / $total) * 100, 2) : 0.0;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function toCsv(array $payload): string
    {
        $summary = is_array($payload['summary'] ?? null) ? $payload['summary'] : [];
        $lines = ["metric,value"];

        foreach ($summary as $key => $value) {
            if (is_array($value) || is_object($value)) {
                continue;
            }

            $lines[] = $this->csvCell((string) $key) . ',' . $this->csvCell((string) ($value ?? ''));
        }

        $lines[] = '';
        $lines[] = 'intent,total,handoffs,avg_confidence';
        foreach (($payload['top_intents'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $lines[] = implode(',', [
                $this->csvCell((string) ($row['intent'] ?? '')),
                $this->csvCell((string) ($row['total'] ?? 0)),
                $this->csvCell((string) ($row['handoffs'] ?? 0)),
                $this->csvCell((string) ($row['avg_confidence'] ?? '')),
            ]);
        }

        return implode("\n", $lines) . "\n";
    }

    private function csvCell(string $value): string
    {
        $escaped = str_replace('"', '""', $value);

        return "\"{$escaped}\"";
    }
}
