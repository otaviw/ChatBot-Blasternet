<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class MetaNumberObservabilityService
{
    private const WINDOW_SECONDS = 3600;
    private const ALERT_FAILURE_SPIKE_THRESHOLD = 20;

    public function logSendResolution(
        int $companyId,
        int $contactId,
        ?int $conversationId,
        ?int $campaignId,
        ?int $metaNumberId,
        bool $usedFallback
    ): void {
        $this->incrementCounter($companyId, 'send_total');
        if ($usedFallback) {
            $this->incrementCounter($companyId, 'send_fallback');
        }

        Log::info('meta_number.send_resolution', [
            'company_id' => $companyId,
            'contact_id' => $contactId,
            'meta_number_id' => $metaNumberId,
            'campaign_id' => $campaignId,
            'conversation_id' => $conversationId,
            'used_fallback' => $usedFallback,
        ]);
    }

    public function logInvalidContactNumber(int $companyId, int $contactId, ?int $conversationId, ?int $campaignId): void
    {
        $this->incrementCounter($companyId, 'contact_invalid_total');
        $this->incrementCounter($companyId, 'no_active_number_failures');

        Log::warning('meta_number.no_active_number_for_company', [
            'company_id' => $companyId,
            'contact_id' => $contactId,
            'meta_number_id' => null,
            'campaign_id' => $campaignId,
            'conversation_id' => $conversationId,
            'error_code' => 'NO_ACTIVE_META_NUMBER_FOR_COMPANY',
        ]);

        $this->checkFailureSpikeAlert($companyId);
    }

    public function logReassignmentTiming(
        int $companyId,
        int $removedMetaNumberId,
        ?int $replacementMetaNumberId,
        int $affectedContacts,
        float $durationMs
    ): void {
        Log::info('meta_number.reassignment_timing', [
            'company_id' => $companyId,
            'contact_id' => null,
            'meta_number_id' => $replacementMetaNumberId,
            'campaign_id' => null,
            'conversation_id' => null,
            'removed_meta_number_id' => $removedMetaNumberId,
            'replacement_meta_number_id' => $replacementMetaNumberId,
            'affected_contacts' => $affectedContacts,
            'duration_ms' => $durationMs,
        ]);
    }

    public function alertCompanyWithoutActiveNumber(int $companyId): void
    {
        Log::warning('meta_number.company_without_active_numbers', [
            'company_id' => $companyId,
            'contact_id' => null,
            'meta_number_id' => null,
            'campaign_id' => null,
            'conversation_id' => null,
        ]);
    }

    public function fallbackRate(int $companyId): float
    {
        $total = (int) Cache::get($this->key($companyId, 'send_total'), 0);
        if ($total <= 0) {
            return 0.0;
        }

        $fallback = (int) Cache::get($this->key($companyId, 'send_fallback'), 0);
        return ($fallback / $total) * 100;
    }

    public function invalidContactRate(int $companyId): float
    {
        $total = (int) Cache::get($this->key($companyId, 'send_total'), 0);
        if ($total <= 0) {
            return 0.0;
        }

        $invalid = (int) Cache::get($this->key($companyId, 'contact_invalid_total'), 0);
        return ($invalid / $total) * 100;
    }

    private function incrementCounter(int $companyId, string $metric): void
    {
        $key = $this->key($companyId, $metric);
        $current = (int) Cache::get($key, 0);
        Cache::put($key, $current + 1, self::WINDOW_SECONDS);
    }

    private function checkFailureSpikeAlert(int $companyId): void
    {
        $failures = (int) Cache::get($this->key($companyId, 'no_active_number_failures'), 0);
        if ($failures >= self::ALERT_FAILURE_SPIKE_THRESHOLD) {
            Log::error('meta_number.no_active_number_failure_spike', [
                'company_id' => $companyId,
                'contact_id' => null,
                'meta_number_id' => null,
                'campaign_id' => null,
                'conversation_id' => null,
                'failures_last_hour' => $failures,
                'threshold' => self::ALERT_FAILURE_SPIKE_THRESHOLD,
                'error_code' => 'NO_ACTIVE_META_NUMBER_FOR_COMPANY',
            ]);
        }
    }

    private function key(int $companyId, string $metric): string
    {
        return "meta_number_obs:company:{$companyId}:{$metric}";
    }
}

