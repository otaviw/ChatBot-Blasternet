<?php

declare(strict_types=1);

namespace App\Services\Company;

use App\Models\Company;
use App\Models\CompanyMetaNumber;
use App\Models\User;
use App\Services\AuditService;
use App\Services\MetaNumberObservabilityService;
use App\Support\AuditActions;
use App\Support\PhoneNumberNormalizer;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CompanyMetaNumberService
{
    public function __construct(
        private readonly MetaNumberObservabilityService $observability,
    ) {}

    /**
     * @param  array{phone_number:string,display_name?:?string,is_active?:?bool,is_primary?:?bool,reason?:?string}  $data
     */
    public function createNumber(int $companyId, array $data, User $actor): CompanyMetaNumber
    {
        $company = $this->loadCompanyWithPermission($companyId, $actor);
        $phoneNumber = $this->normalizePhoneNumber((string) ($data['phone_number'] ?? ''));
        if ($phoneNumber === '') {
            throw new RuntimeException('META_NUMBER_INVALID_PHONE');
        }

        return DB::transaction(function () use ($company, $actor, $data, $phoneNumber): CompanyMetaNumber {
            $setPrimary = (bool) ($data['is_primary'] ?? false);
            $hasAnyNumber = CompanyMetaNumber::query()->where('company_id', (int) $company->id)->exists();

            if (! $hasAnyNumber) {
                $setPrimary = true;
            }

            if ($setPrimary) {
                CompanyMetaNumber::query()
                    ->where('company_id', (int) $company->id)
                    ->where('is_primary', true)
                    ->update(['is_primary' => false, 'updated_by' => $actor->id, 'updated_at' => now()]);
            }

            $number = CompanyMetaNumber::query()->create([
                'company_id' => (int) $company->id,
                'phone_number' => $phoneNumber,
                'display_name' => $this->normalizeDisplayName($data['display_name'] ?? null),
                'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : true,
                'is_primary' => $setPrimary,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            AuditService::log(
                AuditActions::COMPANY_META_NUMBER_CREATED,
                'company_meta_number',
                $number->id,
                null,
                [
                    'actor_user_id' => (int) $actor->id,
                    'company_id' => (int) $company->id,
                    'entity_type' => 'company_meta_number',
                    'entity_id' => (int) $number->id,
                    'after' => $number->only(['id', 'company_id', 'phone_number', 'display_name', 'is_active', 'is_primary']),
                    'reason' => $data['reason'] ?? null,
                ]
            );

            return $number;
        });
    }

    /**
     * @param  array{phone_number?:?string,display_name?:?string,is_active?:?bool,is_primary?:?bool,reason?:?string}  $data
     */
    public function updateNumber(int $companyId, int $numberId, array $data, User $actor): CompanyMetaNumber
    {
        $company = $this->loadCompanyWithPermission($companyId, $actor);

        return DB::transaction(function () use ($company, $numberId, $data, $actor): CompanyMetaNumber {
            $number = CompanyMetaNumber::query()
                ->where('company_id', (int) $company->id)
                ->whereKey($numberId)
                ->lockForUpdate()
                ->firstOrFail();

            $before = $number->only(['id', 'company_id', 'phone_number', 'display_name', 'is_active', 'is_primary']);

            if (array_key_exists('phone_number', $data)) {
                $phoneNumber = $this->normalizePhoneNumber((string) $data['phone_number']);
                if ($phoneNumber === '') {
                    throw new RuntimeException('META_NUMBER_INVALID_PHONE');
                }
                $number->phone_number = $phoneNumber;
            }

            if (array_key_exists('display_name', $data)) {
                $number->display_name = $this->normalizeDisplayName($data['display_name']);
            }

            if (array_key_exists('is_active', $data)) {
                $number->is_active = (bool) $data['is_active'];
            }

            if (array_key_exists('is_primary', $data) && (bool) $data['is_primary'] === true) {
                CompanyMetaNumber::query()
                    ->where('company_id', (int) $company->id)
                    ->where('is_primary', true)
                    ->where('id', '!=', (int) $number->id)
                    ->update(['is_primary' => false, 'updated_by' => $actor->id, 'updated_at' => now()]);
                $number->is_primary = true;
            }

            $number->updated_by = $actor->id;
            $number->save();

            AuditService::log(
                AuditActions::COMPANY_META_NUMBER_UPDATED,
                'company_meta_number',
                $number->id,
                [
                    'actor_user_id' => (int) $actor->id,
                    'company_id' => (int) $company->id,
                    'entity_type' => 'company_meta_number',
                    'entity_id' => (int) $number->id,
                    'before' => $before,
                ],
                [
                    'actor_user_id' => (int) $actor->id,
                    'company_id' => (int) $company->id,
                    'entity_type' => 'company_meta_number',
                    'entity_id' => (int) $number->id,
                    'after' => $number->only(['id', 'company_id', 'phone_number', 'display_name', 'is_active', 'is_primary']),
                    'reason' => $data['reason'] ?? null,
                ]
            );

            return $number;
        });
    }

    public function setPrimary(int $companyId, int $numberId, User $actor): CompanyMetaNumber
    {
        $company = $this->loadCompanyWithPermission($companyId, $actor);

        return DB::transaction(function () use ($company, $numberId, $actor): CompanyMetaNumber {
            $target = CompanyMetaNumber::query()
                ->where('company_id', (int) $company->id)
                ->whereKey($numberId)
                ->lockForUpdate()
                ->firstOrFail();

            CompanyMetaNumber::query()
                ->where('company_id', (int) $company->id)
                ->where('is_primary', true)
                ->update(['is_primary' => false, 'updated_by' => $actor->id, 'updated_at' => now()]);

            $target->is_primary = true;
            $target->updated_by = $actor->id;
            $target->save();

            AuditService::log(
                AuditActions::COMPANY_META_NUMBER_PRIMARY_CHANGED,
                'company_meta_number',
                $target->id,
                null,
                [
                    'actor_user_id' => (int) $actor->id,
                    'company_id' => (int) $company->id,
                    'entity_type' => 'company_meta_number',
                    'entity_id' => (int) $target->id,
                    'after' => $target->only(['id', 'company_id', 'phone_number', 'is_primary']),
                ]
            );

            return $target;
        });
    }

    public function deactivateOrRemove(int $companyId, int $numberId, User $actor, ?string $strategy = 'deactivate'): void
    {
        $company = $this->loadCompanyWithPermission($companyId, $actor);
        $startedAt = microtime(true);

        DB::transaction(function () use ($company, $numberId, $actor, $strategy, $startedAt): void {
            $target = CompanyMetaNumber::query()
                ->where('company_id', (int) $company->id)
                ->whereKey($numberId)
                ->lockForUpdate()
                ->firstOrFail();

            $activeNumbers = CompanyMetaNumber::query()
                ->where('company_id', (int) $company->id)
                ->where('is_active', true)
                ->orderByDesc('is_primary')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $remainingActive = $activeNumbers
                ->filter(fn (CompanyMetaNumber $item): bool => (int) $item->id !== (int) $target->id)
                ->values();

            $replacement = null;
            if ($remainingActive->count() === 1) {
                $replacement = $remainingActive->first();
            } elseif ($remainingActive->count() > 1) {
                $replacement = $remainingActive->firstWhere('is_primary', true);
                if (! $replacement instanceof CompanyMetaNumber) {
                    $replacement = $remainingActive->sortBy('id')->first();
                }
            }

            $replacementId = $replacement?->id;

            $affectedContacts = DB::table('contacts')
                ->where('company_id', (int) $company->id)
                ->where('meta_number_id', (int) $target->id)
                ->update(['meta_number_id' => $replacementId, 'updated_at' => now()]);

            if ($strategy === 'remove') {
                $target->updated_by = $actor->id;
                $target->save();
                $target->delete();
            } else {
                $target->is_active = false;
                $target->is_primary = false;
                $target->updated_by = $actor->id;
                $target->save();
            }

            if ($replacementId !== null && (bool) ($replacement?->is_primary) === false) {
                $hasPrimary = CompanyMetaNumber::query()
                    ->where('company_id', (int) $company->id)
                    ->where('is_active', true)
                    ->where('is_primary', true)
                    ->exists();
                if (! $hasPrimary) {
                    CompanyMetaNumber::query()
                        ->whereKey((int) $replacementId)
                        ->update(['is_primary' => true, 'updated_by' => $actor->id, 'updated_at' => now()]);
                }
            }

            AuditService::log(
                AuditActions::CONTACT_META_NUMBER_BULK_REASSIGNED,
                'contact',
                (string) $company->id,
                null,
                [
                    'actor_user_id' => (int) $actor->id,
                    'company_id' => (int) $company->id,
                    'entity_type' => 'contact',
                    'entity_id' => (string) $company->id,
                    'before' => ['removed_meta_number_id' => (int) $target->id],
                    'after' => ['replacement_meta_number_id' => $replacementId],
                    'affected_contacts' => (int) $affectedContacts,
                ]
            );

            AuditService::log(
                $strategy === 'remove' ? AuditActions::COMPANY_META_NUMBER_REMOVED : AuditActions::COMPANY_META_NUMBER_DEACTIVATED,
                'company_meta_number',
                $target->id,
                null,
                [
                    'actor_user_id' => (int) $actor->id,
                    'company_id' => (int) $company->id,
                    'entity_type' => 'company_meta_number',
                    'entity_id' => (int) $target->id,
                    'after' => [
                        'is_active' => false,
                        'is_primary' => false,
                        'replacement_meta_number_id' => $replacementId,
                        'strategy' => $strategy === 'remove' ? 'remove' : 'deactivate',
                        'affected_contacts' => (int) $affectedContacts,
                    ],
                ]
            );

            $stillHasActiveNumber = CompanyMetaNumber::query()
                ->where('company_id', (int) $company->id)
                ->where('is_active', true)
                ->exists();
            if (! $stillHasActiveNumber) {
                $this->observability->alertCompanyWithoutActiveNumber((int) $company->id);
            }

            $this->observability->logReassignmentTiming(
                (int) $company->id,
                (int) $target->id,
                $replacementId !== null ? (int) $replacementId : null,
                (int) $affectedContacts,
                (microtime(true) - $startedAt) * 1000
            );
        });
    }

    /** @return \Illuminate\Support\Collection<int, CompanyMetaNumber> */
    public function listActive(int $companyId): \Illuminate\Support\Collection
    {
        return CompanyMetaNumber::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->get();
    }

    public function assertBelongsToCompanyAndActive(int $companyId, int $metaNumberId): CompanyMetaNumber
    {
        $number = CompanyMetaNumber::withoutCompanyScope()->find($metaNumberId);
        if (! $number) {
            throw new RuntimeException('META_NUMBER_NOT_FOUND');
        }

        if ((int) $number->company_id !== $companyId) {
            throw new RuntimeException('META_NUMBER_COMPANY_MISMATCH');
        }

        if (! $number->is_active) {
            throw new RuntimeException('META_NUMBER_INACTIVE');
        }

        return $number;
    }

    private function loadCompanyWithPermission(int $companyId, User $actor): Company
    {
        $company = Company::query()->findOrFail($companyId);

        if ($actor->isSystemAdmin()) {
            return $company;
        }

        if (! $actor->isResellerAdmin()) {
            throw new AuthorizationException('FORBIDDEN_SCOPE');
        }

        if ((int) ($actor->reseller_id ?? 0) <= 0 || (int) $company->reseller_id !== (int) $actor->reseller_id) {
            throw new AuthorizationException('FORBIDDEN_SCOPE');
        }

        return $company;
    }

    private function normalizePhoneNumber(string $phoneNumber): string
    {
        return PhoneNumberNormalizer::normalizeBrazil($phoneNumber);
    }

    private function normalizeDisplayName(mixed $value): ?string
    {
        $displayName = trim((string) $value);
        return $displayName === '' ? null : mb_substr($displayName, 0, 120);
    }
}
