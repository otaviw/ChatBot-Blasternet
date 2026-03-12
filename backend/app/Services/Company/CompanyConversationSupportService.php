<?php

namespace App\Services\Company;

use App\Models\Area;
use App\Models\Conversation;
use App\Models\ConversationTransfer;
use App\Models\User;
use App\Support\ConversationAssignedType;
use App\Support\ConversationHandlingMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class CompanyConversationSupportService
{
    /**
     * @param  array<string, mixed>  $validated
     */
    public function resolveTransferTargetFromLegacyPayload(int $companyId, array $validated): array
    {
        $type = $validated['type'] ?? null;
        $id = $validated['id'] ?? null;

        if ($type && $id) {
            return [(string) $type, (int) $id];
        }

        if (! empty($validated['to_user_id'])) {
            return [ConversationAssignedType::USER, (int) $validated['to_user_id']];
        }

        $toArea = trim((string) ($validated['to_area'] ?? ''));
        if ($toArea !== '') {
            $area = Area::query()
                ->where('company_id', $companyId)
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($toArea)])
                ->first();

            if (! $area) {
                throw ValidationException::withMessages([
                    'to_area' => ['Área destino não encontrada para esta empresa.'],
                ]);
            }

            return [ConversationAssignedType::AREA, (int) $area->id];
        }

        throw ValidationException::withMessages([
            'type' => ['Informe destino de transferencia (user ou area).'],
        ]);
    }

    public function assignConversationToCurrentUser(Conversation $conversation, User $user, ?int $preferredAreaId = null): void
    {
        $areas = $user->areas()->get(['areas.id', 'areas.name']);
        $firstArea = null;

        if ($preferredAreaId && $preferredAreaId > 0) {
            $firstArea = $areas->first(fn (Area $area) => (int) $area->id === $preferredAreaId);
        }

        if (! $firstArea) {
            $firstArea = $areas->sortBy('name')->first();
        }

        $conversation->handling_mode = ConversationHandlingMode::HUMAN;
        $conversation->assigned_type = ConversationAssignedType::USER;
        $conversation->assigned_id = (int) $user->id;
        $conversation->current_area_id = $firstArea?->id;
        $conversation->assigned_user_id = (int) $user->id;
        $conversation->assigned_area = $firstArea?->name;
        $conversation->assumed_at = now();
    }

    public function applyInboxVisibilityScope(Builder $query, User $user): void
    {
        if (! $user->isAgent()) {
            return;
        }

        $userId = (int) $user->id;
        $areaIds = $user->areas()
            ->pluck('areas.id')
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->values()
            ->all();

        $query->where(function (Builder $scope) use ($userId, $areaIds) {
            $scope->where(function (Builder $assignedToUser) use ($userId) {
                $assignedToUser->where(function (Builder $directAssignment) use ($userId) {
                    $directAssignment->where('assigned_type', ConversationAssignedType::USER)
                        ->where('assigned_id', $userId);
                })->orWhere('assigned_user_id', $userId);
            });

            if ($areaIds !== []) {
                $scope->orWhere(function (Builder $unassignedAreaQueue) use ($areaIds) {
                    $unassignedAreaQueue
                        ->whereIn('current_area_id', $areaIds)
                        ->whereNull('assigned_user_id')
                        ->where(function (Builder $noAttendant) {
                            $noAttendant
                                ->where('assigned_type', '!=', ConversationAssignedType::USER)
                                ->orWhereNull('assigned_id');
                        });
                });
            }
        });
    }

    public function normalizeConversationAssignmentRelations(Conversation $conversation): void
    {
        if ($conversation->assigned_type !== ConversationAssignedType::USER) {
            $conversation->setRelation('assignedUser', null);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function loadTransferHistory(Conversation $conversation): array
    {
        $history = ConversationTransfer::query()
            ->where('conversation_id', $conversation->id)
            ->latest('id')
            ->get();

        if ($history->isEmpty()) {
            return [];
        }

        $userIds = $history
            ->flatMap(fn (ConversationTransfer $item) => [
                $item->transferred_by_user_id,
                $item->from_assigned_type === ConversationAssignedType::USER ? $item->from_assigned_id : null,
                $item->to_assigned_type === ConversationAssignedType::USER ? $item->to_assigned_id : null,
            ])
            ->filter()
            ->unique()
            ->values();

        $areaIds = $history
            ->flatMap(fn (ConversationTransfer $item) => [
                $item->from_assigned_type === ConversationAssignedType::AREA ? $item->from_assigned_id : null,
                $item->to_assigned_type === ConversationAssignedType::AREA ? $item->to_assigned_id : null,
            ])
            ->filter()
            ->unique()
            ->values();

        $usersById = User::query()
            ->whereIn('id', $userIds->all())
            ->get(['id', 'name', 'email'])
            ->keyBy('id');

        $areasById = Area::query()
            ->whereIn('id', $areaIds->all())
            ->get(['id', 'name'])
            ->keyBy('id');

        return $history->map(function (ConversationTransfer $item) use ($usersById, $areasById) {
            $fromUser = $item->from_assigned_type === ConversationAssignedType::USER
                ? $usersById->get($item->from_assigned_id)
                : null;
            $toUser = $item->to_assigned_type === ConversationAssignedType::USER
                ? $usersById->get($item->to_assigned_id)
                : null;
            $transferredBy = $usersById->get($item->transferred_by_user_id);

            return [
                'id' => $item->id,
                'from_assigned_type' => $item->from_assigned_type,
                'from_assigned_id' => $item->from_assigned_id,
                'to_assigned_type' => $item->to_assigned_type,
                'to_assigned_id' => $item->to_assigned_id,
                'from_user' => $fromUser,
                'to_user' => $toUser,
                'from_area' => $item->from_assigned_type === ConversationAssignedType::AREA
                    ? ($areasById->get($item->from_assigned_id)?->name)
                    : null,
                'to_area' => $item->to_assigned_type === ConversationAssignedType::AREA
                    ? ($areasById->get($item->to_assigned_id)?->name)
                    : null,
                'transferred_by_user' => $transferredBy,
                'created_at' => $item->created_at,
            ];
        })->values()->all();
    }
}
