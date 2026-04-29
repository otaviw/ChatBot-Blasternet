<?php

namespace App\Services;

use App\Models\ProductEvent;
use App\Models\User;
use App\Support\ProductFunnels;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class ProductMetricsService
{
    /**
     * @param  array<string, mixed>|null  $meta
     */
    public function track(
        string $funnel,
        string $step,
        string $eventName,
        ?int $companyId = null,
        ?int $userId = null,
        ?array $meta = null,
    ): void {
        try {
            ProductEvent::query()->create([
                'company_id' => $companyId,
                'user_id' => $userId,
                'funnel' => $funnel,
                'step' => $step,
                'event_name' => $eventName,
                'meta' => $meta,
                'occurred_at' => now(),
            ]);
        } catch (\Throwable $exception) {
            Log::warning('product_metrics.track_failed', [
                'funnel' => $funnel,
                'step' => $step,
                'event_name' => $eventName,
                'company_id' => $companyId,
                'user_id' => $userId,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>|null  $meta
     */
    public function trackForUser(
        string $funnel,
        string $step,
        string $eventName,
        ?User $user,
        ?array $meta = null,
    ): void {
        $companyId = $user?->company_id ? (int) $user->company_id : null;
        $userId = $user?->id ? (int) $user->id : null;

        $this->track($funnel, $step, $eventName, $companyId, $userId, $meta);
    }

    /**
     * @return array<string, mixed>
     */
    public function funnelSummary(?int $companyId, Carbon $from, Carbon $to): array
    {
        $stepsByFunnel = ProductFunnels::steps();
        $summary = [];
        $totals = $this->baseQuery($companyId, $from, $to)
            ->select([
                'funnel',
                'step',
                DB::raw('COUNT(*) as total'),
            ])
            ->groupBy('funnel', 'step')
            ->get()
            ->mapWithKeys(function (ProductEvent $event): array {
                return [sprintf('%s:%s', (string) $event->funnel, (string) $event->step) => (int) ($event->total ?? 0)];
            });

        foreach ($stepsByFunnel as $funnel => $steps) {
            $stepTotals = [];

            foreach ($steps as $step) {
                $stepTotals[$step] = (int) ($totals->get(sprintf('%s:%s', $funnel, $step), 0));
            }

            $firstStep = $steps[0] ?? null;
            $lastStep = $steps[count($steps) - 1] ?? null;
            $entered = $firstStep ? (int) ($stepTotals[$firstStep] ?? 0) : 0;
            $converted = $lastStep ? (int) ($stepTotals[$lastStep] ?? 0) : 0;
            $conversionRate = $entered > 0 ? round(($converted / $entered) * 100, 2) : 0.0;

            $summary[$funnel] = [
                'entered' => $entered,
                'converted' => $converted,
                'conversion_rate_pct' => $conversionRate,
                'steps' => $stepTotals,
            ];
        }

        return [
            'date_from' => $from->toDateString(),
            'date_to' => $to->toDateString(),
            'company_id' => $companyId,
            'funnels' => $summary,
        ];
    }

    private function baseQuery(?int $companyId, Carbon $from, Carbon $to): Builder
    {
        $query = ProductEvent::query()
            ->where('occurred_at', '>=', $from->copy()->startOfDay())
            ->where('occurred_at', '<=', $to->copy()->endOfDay());

        if ($companyId !== null && $companyId > 0) {
            $query->where('company_id', $companyId);
        }

        return $query;
    }
}
