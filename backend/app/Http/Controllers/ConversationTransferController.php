<?php

namespace App\Http\Controllers;

use App\Http\Requests\TransferConversationRequest;
use App\Models\Area;
use App\Models\Conversation;
use App\Models\ConversationTransfer;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\TransferConversationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConversationTransferController extends Controller
{
    public function __construct(
        private TransferConversationService $transferService,
        private AuditLogService $auditLog
    ) {}

    public function index(Request $request, Conversation $conversation): JsonResponse
    {
        $user = $request->user();
        if (! $user || ! $user->isCompanyUser()) {
            return response()->json([
                'authenticated' => false,
                'redirect' => '/entrar',
            ], 403);
        }

        $this->authorize('viewTransfers', $conversation);

        $transfers = ConversationTransfer::query()
            ->where('conversation_id', $conversation->id)
            ->latest('id')
            ->get();

        $userIds = $transfers
            ->flatMap(fn(ConversationTransfer $item) => [
                $item->from_assigned_type === 'user' ? $item->from_assigned_id : null,
                $item->to_assigned_type === 'user' ? $item->to_assigned_id : null,
                $item->transferred_by_user_id,
            ])
            ->filter()
            ->unique()
            ->values();

        $areaIds = $transfers
            ->flatMap(fn(ConversationTransfer $item) => [
                $item->from_assigned_type === 'area' ? $item->from_assigned_id : null,
                $item->to_assigned_type === 'area' ? $item->to_assigned_id : null,
            ])
            ->filter()
            ->unique()
            ->values();

        $usersById = User::query()
            ->whereIn('id', $userIds->all())
            ->get(['id', 'name'])
            ->keyBy('id');

        $areasById = Area::query()
            ->whereIn('id', $areaIds->all())
            ->get(['id', 'name'])
            ->keyBy('id');

        $history = $transfers->map(function (ConversationTransfer $item) use ($usersById, $areasById) {
            return [
                'id' => $item->id,
                'conversation_id' => $item->conversation_id,
                'company_id' => $item->company_id,
                'from_assigned_type' => $item->from_assigned_type,
                'from_assigned_id' => $item->from_assigned_id,
                'from_assigned_name' => $this->resolveAssignedName(
                    $item->from_assigned_type,
                    $item->from_assigned_id,
                    $usersById->all(),
                    $areasById->all()
                ),
                'to_assigned_type' => $item->to_assigned_type,
                'to_assigned_id' => $item->to_assigned_id,
                'to_assigned_name' => $this->resolveAssignedName(
                    $item->to_assigned_type,
                    $item->to_assigned_id,
                    $usersById->all(),
                    $areasById->all()
                ),
                'transferred_by_user_id' => $item->transferred_by_user_id,
                'transferred_by_user_name' => $item->transferred_by_user_id
                    ? ($usersById[$item->transferred_by_user_id]->name ?? null)
                    : null,
                'created_at' => $item->created_at,
            ];
        })->values();

        return response()->json([
            'authenticated' => true,
            'transfers' => $history,
        ]);
    }

    public function store(TransferConversationRequest $request, Conversation $conversation): JsonResponse
    {
        $user = $request->user();
        if (! $user || ! $user->isCompanyUser()) {
            return response()->json([
                'authenticated' => false,
                'redirect' => '/entrar',
            ], 403);
        }

        $this->authorize('transfer', $conversation);

        $result = $this->transferService->transfer(
            $conversation,
            $user,
            (string) $request->input('type'),
            (int) $request->input('id'),
            (bool) $request->boolean('send_outbound', true)
        );

        $transfer = $result['transfer'];
        $this->auditLog->record($request, 'conversation.transferred', $conversation->company_id, [
            'conversation_id' => $conversation->id,
            'from_assigned_type' => $transfer->from_assigned_type,
            'from_assigned_id' => $transfer->from_assigned_id,
            'to_assigned_type' => $transfer->to_assigned_type,
            'to_assigned_id' => $transfer->to_assigned_id,
            'transferred_by_user_id' => $transfer->transferred_by_user_id,
            'was_sent' => $result['was_sent'],
        ]);

        return response()->json([
            'ok' => true,
            'conversation' => $result['conversation'],
            'transfer' => $transfer,
            'system_message' => $result['message'],
            'was_sent' => $result['was_sent'],
        ]);
    }

    /**
     * @param  array<int, User>  $usersById
     * @param  array<int, Area>  $areasById
     */
    private function resolveAssignedName(
        string $assignedType,
        ?int $assignedId,
        array $usersById,
        array $areasById
    ): ?string {
        if (! $assignedId) {
            return match ($assignedType) {
                'bot' => 'Bot',
                'unassigned' => 'Nao atribuido',
                default => null,
            };
        }

        if ($assignedType === 'user') {
            return $usersById[$assignedId]->name ?? null;
        }

        if ($assignedType === 'area') {
            return $areasById[$assignedId]->name ?? null;
        }

        return null;
    }
}

