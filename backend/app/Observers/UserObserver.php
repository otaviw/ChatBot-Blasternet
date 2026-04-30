<?php

declare(strict_types=1);


namespace App\Observers;

use App\Models\User;
use App\Services\AuditService;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Http\Request;

class UserObserver implements ShouldHandleEventsAfterCommit
{
    /**
     * Campos relevantes para trilha de auditoria de usuário.
     *
     * @var array<int, string>
     */
    private array $auditableFields = [
        'name',
        'email',
        'role',
        'company_id',
        'reseller_id',
        'is_active',
        'can_use_ai',
        'disabled_at',
    ];

    public function created(User $user): void
    {
        $this->bindTenantContext((int) $user->company_id, (int) ($user->reseller_id ?? 0));

        AuditService::log(
            action: 'create_entity',
            entityType: 'user',
            entityId: $user->id,
            newData: $this->snapshot($user)
        );
    }

    public function updated(User $user): void
    {
        if (! $user->wasChanged($this->auditableFields)) {
            return;
        }

        $this->bindTenantContext((int) $user->company_id, (int) ($user->reseller_id ?? 0));

        $oldData = [];
        $newData = [];
        foreach ($this->auditableFields as $field) {
            if (! $user->wasChanged($field)) {
                continue;
            }

            $oldValue = $user->getOriginal($field);
            $newValue = $user->getAttribute($field);
            $oldData[$field] = $this->normalizeValue($field, $oldValue);
            $newData[$field] = $this->normalizeValue($field, $newValue);
        }

        if ($oldData === [] && $newData === []) {
            return;
        }

        AuditService::log(
            action: 'update_entity',
            entityType: 'user',
            entityId: $user->id,
            oldData: $oldData,
            newData: $newData
        );
    }

    public function deleted(User $user): void
    {
        $this->bindTenantContext((int) $user->company_id, (int) ($user->reseller_id ?? 0));

        AuditService::log(
            action: 'delete_entity',
            entityType: 'user',
            entityId: $user->id,
            oldData: $this->snapshot($user)
        );
    }

    private function bindTenantContext(int $companyId, int $resellerId): void
    {
        if ($companyId <= 0 && $resellerId <= 0) {
            return;
        }

        if (! app()->bound('request')) {
            return;
        }

        $request = request();
        if ($request instanceof Request) {
            if ($companyId > 0) {
                $request->attributes->set('company_id', $companyId);
            }
            if ($resellerId > 0) {
                $request->attributes->set('reseller_id', $resellerId);
            }
        }
    }

    /**
     * Snapshot seguro para auditoria (sem senha/token).
     *
     * @return array<string, mixed>
     */
    private function snapshot(User $user): array
    {
        return [
            'id' => (int) $user->id,
            'company_id' => (int) $user->company_id,
            'reseller_id' => (int) ($user->reseller_id ?? 0),
            'name' => $user->name,
            'email' => $user->email,
            'role' => User::normalizeRole($user->role),
            'is_active' => (bool) $user->is_active,
            'can_use_ai' => (bool) $user->can_use_ai,
            'disabled_at' => $user->disabled_at?->toIso8601String(),
        ];
    }

    private function normalizeValue(string $field, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($field) {
            'company_id', 'reseller_id' => (int) $value,
            'is_active', 'can_use_ai' => (bool) $value,
            'role' => User::normalizeRole((string) $value),
            'disabled_at' => $this->normalizeDateTime($value),
            default => $value,
        };
    }

    private function normalizeDateTime(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        return (string) $value;
    }
}
