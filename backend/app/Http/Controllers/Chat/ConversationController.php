<?php

namespace App\Http\Controllers\Chat;

use App\Actions\Chat\CreateConversationAction;
use App\Actions\Chat\DeleteMessageAction;
use App\Actions\Chat\ListConversationsAction;
use App\Actions\Chat\MarkConversationReadAction;
use App\Actions\Chat\SendMessageAction;
use App\Actions\Chat\ShowConversationAction;
use App\Actions\Chat\ToggleReactionAction;
use App\Actions\Chat\UpdateMessageAction;
use App\Http\Controllers\Controller;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\User;
use App\Policies\ChatPolicy;
use App\Services\Chat\InternalChatConversationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConversationController extends Controller
{
    public function __construct(
        private readonly ListConversationsAction $listConversationsAction,
        private readonly ShowConversationAction $showConversationAction,
        private readonly CreateConversationAction $createConversationAction,
        private readonly SendMessageAction $sendMessageAction,
        private readonly UpdateMessageAction $updateMessageAction,
        private readonly DeleteMessageAction $deleteMessageAction,
        private readonly MarkConversationReadAction $markConversationReadAction,
        private readonly ToggleReactionAction $toggleReactionAction,
        private readonly ChatPolicy $chatPolicy,
        private readonly InternalChatConversationService $chatService
    ) {}

    public function index(Request $request): JsonResponse
    {
        return $this->listConversationsAction->handle($request);
    }

    public function show(Request $request, ChatConversation $conversation): JsonResponse
    {
        return $this->showConversationAction->handle($request, $conversation);
    }

    public function store(Request $request): JsonResponse
    {
        return $this->createConversationAction->handle($request);
    }

    public function sendMessage(Request $request, ChatConversation $conversation): JsonResponse
    {
        return $this->sendMessageAction->handle($request, $conversation);
    }

    public function updateMessage(Request $request, ChatConversation $conversation, ChatMessage $message): JsonResponse
    {
        return $this->updateMessageAction->handle($request, $conversation, $message);
    }

    public function deleteMessage(Request $request, ChatConversation $conversation, ChatMessage $message): JsonResponse
    {
        return $this->deleteMessageAction->handle($request, $conversation, $message);
    }

    public function markRead(Request $request, ChatConversation $conversation): JsonResponse
    {
        return $this->markConversationReadAction->handle($request, $conversation);
    }

    public function toggleReaction(Request $request, ChatConversation $conversation, ChatMessage $message): JsonResponse
    {
        return $this->toggleReactionAction->handle($request, $conversation, $message);
    }

    public function users(Request $request): JsonResponse
    {
        $sender = $this->chatService->resolveAuthenticatedUser($request);
        if (! $sender instanceof User) {
            return $this->chatService->unauthenticatedResponse();
        }

        $search = trim((string) $request->query('search', ''));

        $query = User::query()
            ->where('is_active', true)
            ->where('id', '!=', (int) $sender->id);

        if (! $sender->isSystemAdmin()) {
            $companyId = (int) ($sender->company_id ?? 0);

            $query->where(function ($scope) use ($companyId): void {
                $scope->whereIn('role', [User::ROLE_SYSTEM_ADMIN, User::ROLE_LEGACY_ADMIN]);
                if ($companyId > 0) {
                    $scope->orWhere('company_id', $companyId);
                }
            });
        }

        if ($search !== '') {
            $query->where(function ($scope) use ($search): void {
                $scope->where('name', 'like', '%'.$search.'%')
                    ->orWhere('email', 'like', '%'.$search.'%');
            });
        }

        $candidates = $query
            ->orderBy('name')
            ->limit(300)
            ->get(['id', 'name', 'email', 'role', 'company_id', 'is_active']);

        $users = $candidates
            ->filter(function (User $candidate) use ($sender): bool {
                return $this->chatPolicy->canMessage($sender, $candidate);
            })
            ->map(function (User $candidate): array {
                return $this->chatService->serializeUser($candidate);
            })
            ->values()
            ->all();

        return response()->json([
            'authenticated' => true,
            'users' => $users,
        ]);
    }
}
