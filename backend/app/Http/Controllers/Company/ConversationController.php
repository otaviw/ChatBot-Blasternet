<?php

declare(strict_types=1);


namespace App\Http\Controllers\Company;

use App\Actions\Company\Conversation\CreateCompanyConversationAction;
use App\Actions\Company\Conversation\GenerateAiSuggestionForConversationAction;
use App\Actions\Company\Conversation\ListCompanyConversationsAction;
use App\Actions\Company\Conversation\ManualReplyAction;
use App\Actions\Company\Conversation\SearchCompanyConversationMessagesAction;
use App\Actions\Company\Conversation\SendConversationTemplateAction;
use App\Actions\Company\Conversation\ServeCompanyConversationMediaAction;
use App\Actions\Company\Conversation\ShowCompanyConversationAction;
use App\Actions\Company\Conversation\TransferCompanyConversationAction;
use App\Actions\Conversation\AssignConversationAgentAction;
use App\Actions\Conversation\SearchConversationsAction;
use App\Actions\Conversation\SyncConversationTagsAction;
use App\Actions\Conversation\ToggleConversationPrivacyAction;
use App\Actions\Conversation\UpdateConversationStatusAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Company\CreateConversationRequest;
use App\Http\Requests\Company\ManualReplyRequest;
use App\Http\Requests\Company\SearchConversationMessagesRequest;
use App\Http\Requests\Company\SearchConversationsRequest;
use App\Http\Requests\Company\SendConversationTemplateRequest;
use App\Http\Requests\Company\TransferConversationRequest;
use App\Http\Requests\Company\UpdateConversationContactRequest;
use App\Http\Requests\Company\UpdateConversationTagsRequest;
use App\Models\Contact;
use App\Models\Conversation;
use App\Services\AuditLogService;
use App\Services\Company\CompanyConversationCountersService;
use App\Services\WhatsApp\WhatsAppSendService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ConversationController extends Controller
{
    public function __construct(
        private readonly AuditLogService $auditLog,
        private readonly CompanyConversationCountersService $countersService,
        private readonly ManualReplyAction $manualReplyAction,
        private readonly CreateCompanyConversationAction $createConversationAction,
        private readonly SendConversationTemplateAction $sendTemplateAction,
        private readonly WhatsAppSendService $whatsAppSend,
    ) {}

    public function index(Request $request, ListCompanyConversationsAction $action): JsonResponse
    {
        $user = $request->user();

        return response()->json($action->handle($user, $request));
    }

    public function search(SearchConversationsRequest $request, SearchConversationsAction $action): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validated();

        return response()->json($action->handleForCompanyUser($user, $validated));
    }

    public function counters(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json($this->countersService->buildForCompany((int) $user->company_id));
    }

    public function searchMessages(
        SearchConversationMessagesRequest $request,
        int $conversationId,
        SearchCompanyConversationMessagesAction $action
    ): JsonResponse {
        $user = $request->user();

        $validated = $request->validated();

        $messagesPerPage = (int) ($validated['messages_per_page'] ?? 25);
        $payload = $action->handle($user, $conversationId, (string) $validated['q'], $messagesPerPage);
        if (! $payload) {
            return response()->json([
                'message' => 'Conversa nao encontrada para esta empresa.',
            ], 404);
        }

        return response()->json($payload);
    }

    public function show(Request $request, int $conversationId, ShowCompanyConversationAction $action): JsonResponse
    {
        $user = $request->user();

        $payload = $action->handle($user, $conversationId, $request);
        if (! $payload) {
            return response()->json([
                'message' => 'Conversa nao encontrada para esta empresa.',
            ], 404);
        }

        return response()->json($payload);
    }

    public function media(Request $request, int $messageId, ServeCompanyConversationMediaAction $action)
    {
        $user = $request->user();

        return $action->handle($user, $messageId);
    }

    public function suggestReply(
        Request $request,
        int $conversationId,
        GenerateAiSuggestionForConversationAction $action
    ): JsonResponse {
        $user = $request->user();

        try {
            $payload = $action->handle($user, $conversationId);
        } catch (ValidationException $exception) {
            $errors = $exception->errors();
            $message = collect($errors)->flatten()->first();

            return response()->json([
                'message' => $message ?: 'Nao foi possivel gerar sugestao da IA.',
                'errors' => $errors,
            ], 422);
        }

        if (! $payload) {
            return response()->json([
                'message' => 'Conversa nao encontrada para esta empresa.',
            ], 404);
        }

        return response()->json($payload);
    }

    public function assume(Request $request, int $conversationId, AssignConversationAgentAction $action): JsonResponse
    {
        $user = $request->user();

        $conversation = $action->handle($request, $user, $conversationId, 'assume');
        if (! $conversation) {
            return response()->json(['message' => 'Conversa nao encontrada para esta empresa.'], 404);
        }

        return response()->json([
            'ok' => true,
            'conversation' => $conversation,
        ]);
    }

    public function release(Request $request, int $conversationId, AssignConversationAgentAction $action): JsonResponse
    {
        $user = $request->user();

        $conversation = $action->handle($request, $user, $conversationId, 'release');
        if (! $conversation) {
            return response()->json(['message' => 'Conversa nao encontrada para esta empresa.'], 404);
        }

        return response()->json([
            'ok' => true,
            'conversation' => $conversation,
        ]);
    }

    public function manualReply(ManualReplyRequest $request, int $conversationId): JsonResponse
    {
        $result = $this->manualReplyAction->handle($request, $request->user(), $conversationId);

        return $result->toResponse();
    }

    public function close(Request $request, int $conversationId, UpdateConversationStatusAction $action): JsonResponse
    {
        $user = $request->user();

        $conversation = $action->handle($request, $user, $conversationId, 'close');
        if (! $conversation) {
            return response()->json(['message' => 'Conversa nao encontrada para esta empresa.'], 404);
        }

        return response()->json([
            'ok' => true,
            'conversation' => $conversation,
        ]);
    }

    public function destroy(Request $request, int $conversationId): JsonResponse
    {
        $user = $request->user();

        $conversation = Conversation::where('id', $conversationId)
            ->where('company_id', $user->company_id)
            ->first();

        if (! $conversation) {
            return response()->json(['message' => 'Conversa nao encontrada.'], 404);
        }

        $conversation->delete();

        return response()->json(['ok' => true]);
    }

    public function updateTags(
        UpdateConversationTagsRequest $request,
        int $conversationId,
        SyncConversationTagsAction $action
    ): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validated();

        $result = $action->handle($request, $user, $conversationId, $validated);
        if (! $result) {
            return response()->json(['message' => 'Conversa nao encontrada para esta empresa.'], 404);
        }

        return response()->json([
            'ok' => true,
            'tags' => $result['tags'],
        ]);
    }
    public function updateContact(UpdateConversationContactRequest $request, int $conversationId): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validated();

        $conversation = Conversation::query()
            ->where('company_id', (int) $user->company_id)
            ->whereKey($conversationId)
            ->first();

        if (! $conversation) {
            return response()->json(['message' => 'Conversa nao encontrada para esta empresa.'], 404);
        }

        $contact = Contact::query()->firstOrCreate(
            [
                'company_id' => (int) $conversation->company_id,
                'phone' => (string) $conversation->customer_phone,
            ],
            [
                'name' => (string) ($conversation->customer_name ?: $conversation->customer_phone),
                'source' => 'manual',
                'added_by_user_id' => auth()->id(),
            ]
        );

        $hasCustomerName = array_key_exists('customer_name', $validated);
        $hasDefaultAttendant = array_key_exists('default_attendant_user_id', $validated);
        $hasSkipBot = array_key_exists('skip_bot_to_default_attendant', $validated);

        $beforeConversationName = $conversation->customer_name;
        $beforeDefaultAttendantId = $contact->default_attendant_user_id !== null
            ? (int) $contact->default_attendant_user_id
            : null;
        $beforeSkipBot = (bool) ($contact->skip_bot_to_default_attendant ?? false);

        if ($hasCustomerName) {
            $customerName = trim((string) ($validated['customer_name'] ?? ''));
            $customerName = $customerName !== '' ? $customerName : null;
            $conversation->customer_name = $customerName;

            if ($customerName !== null) {
                $contact->name = $customerName;
            }
        }

        if ($hasDefaultAttendant) {
            $contact->default_attendant_user_id = isset($validated['default_attendant_user_id'])
                ? (int) $validated['default_attendant_user_id']
                : null;
        }

        if ($hasSkipBot) {
            $contact->skip_bot_to_default_attendant = (bool) ($validated['skip_bot_to_default_attendant'] ?? false);
        }

        if ($contact->default_attendant_user_id === null) {
            $contact->skip_bot_to_default_attendant = false;
        }

        $conversation->save();
        $contact->save();

        if ($hasCustomerName && $beforeConversationName !== $conversation->customer_name) {
            $this->auditLog->record($request, 'company.conversation.contact_updated', $conversation->company_id, [
                'conversation_id' => $conversation->id,
                'before_customer_name' => $beforeConversationName,
                'after_customer_name' => $conversation->customer_name,
            ]);
        }

        $afterDefaultAttendantId = $contact->default_attendant_user_id !== null
            ? (int) $contact->default_attendant_user_id
            : null;
        if (($hasDefaultAttendant || $hasSkipBot) && $beforeDefaultAttendantId !== $afterDefaultAttendantId) {
            $this->auditLog->record($request, 'company.contact.default_attendant_updated', $conversation->company_id, [
                'contact_id' => (int) $contact->id,
                'contact_phone' => (string) $contact->phone,
                'before_default_attendant_user_id' => $beforeDefaultAttendantId,
                'after_default_attendant_user_id' => $afterDefaultAttendantId,
            ]);
        }

        $afterSkipBot = (bool) ($contact->skip_bot_to_default_attendant ?? false);
        if (($hasDefaultAttendant || $hasSkipBot) && $beforeSkipBot !== $afterSkipBot) {
            $this->auditLog->record($request, 'company.contact.skip_bot_updated', $conversation->company_id, [
                'contact_id' => (int) $contact->id,
                'contact_phone' => (string) $contact->phone,
                'before_skip_bot_to_default_attendant' => $beforeSkipBot,
                'after_skip_bot_to_default_attendant' => $afterSkipBot,
            ]);
        }

        $contact->loadMissing('defaultAttendant:id,name');
        $conversationPayload = $conversation->toArray();
        $conversationPayload['default_attendant_user_id'] = $afterDefaultAttendantId;
        $conversationPayload['skip_bot_to_default_attendant'] = $afterSkipBot;
        $conversationPayload['default_attendant'] = $contact->defaultAttendant
            ? [
                'id' => (int) $contact->defaultAttendant->id,
                'name' => (string) $contact->defaultAttendant->name,
            ]
            : null;

        return response()->json([
            'ok' => true,
            'conversation' => $conversationPayload,
        ]);
    }
    public function transfer(TransferConversationRequest $request, int $conversationId, TransferCompanyConversationAction $action): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validated();

        try {
            $payload = $action->handle($request, $user, $conversationId, $validated);
        } catch (ValidationException $exception) {
            $messages = $exception->errors();

            return response()->json([
                'message' => collect($messages)->flatten()->first() ?: 'Transferencia invalida.',
                'errors' => $messages,
            ], 422);
        }

        if (! $payload) {
            return response()->json(['message' => 'Conversa nao encontrada para esta empresa.'], 404);
        }

        return response()->json($payload);
    }

    /**
     * Lista templates aprovados da Meta para a empresa autenticada.
     */
    public function listTemplates(Request $request): JsonResponse
    {
        $user = $request->user();

        $company = \App\Models\Company::find($user->company_id);
        $result  = $this->whatsAppSend->fetchTemplates($company);

        if (! $result['ok']) {
            return response()->json([
                'templates' => [],
                'error'     => $result['error'],
            ], 200); // 200 com lista vazia: frontend trata graciosamente
        }

        return response()->json([
            'templates' => $result['templates'],
        ]);
    }

    /**
     * Cria uma nova conversa (ou reabre existente) e, opcionalmente, envia template de abertura.
     */
    public function createConversation(CreateConversationRequest $request): JsonResponse
    {
        $result = $this->createConversationAction->handle($request, $request->user());

        return $result->toResponse();
    }

    /**
     * Envia template para uma conversa existente (reabre janela de atendimento).
     */
    public function sendTemplate(
        SendConversationTemplateRequest $request,
        int $conversationId
    ): JsonResponse {
        $result = $this->sendTemplateAction->handle($request, $request->user(), $conversationId);

        return $result->toResponse();
    }

    /**
     * Download de midia de mensagem (document/image/video/audio).
     */
    public function downloadMessageMedia(
        Request $request,
        int $conversation,
        int $message,
        ToggleConversationPrivacyAction $action
    )
    {
        $user = $request->user();

        $result = $action->handle($user, $conversation, $message);
        if (isset($result['error'], $result['status'])) {
            return response()->json(['error' => $result['error']], (int) $result['status']);
        }

        return response()->download($result['path'], $result['download_name']);
    }

}





