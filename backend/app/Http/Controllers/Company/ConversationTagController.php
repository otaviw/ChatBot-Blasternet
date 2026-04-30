<?php

declare(strict_types=1);


namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Http\Requests\Company\AttachConversationTagRequest;
use App\Http\Requests\Company\StoreConversationTagRequest;
use App\Http\Requests\Company\UpdateConversationTagRequest;
use App\Models\Conversation;
use App\Models\Tag;
use App\Services\AuditLogService;
use App\Services\RealtimePublisher;
use App\Support\CacheKeys;
use App\Support\RealtimeEvents;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

class ConversationTagController extends Controller
{
    public function __construct(
        private readonly AuditLogService $auditLog,
        private readonly RealtimePublisher $realtime
    ) {}

    // -------------------------------------------------------------------------
    // Tag CRUD
    // -------------------------------------------------------------------------

    /** GET /minha-conta/tags */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $tags = Tag::query()
            ->where('company_id', (int) $user->company_id)
            ->orderBy('name')
            ->get(['id', 'name', 'color']);

        return response()->json(['ok' => true, 'tags' => $tags]);
    }

    /** POST /minha-conta/tags */
    public function store(StoreConversationTagRequest $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validated();

        $name = mb_strtolower(trim($validated['name']));

        $exists = Tag::query()
            ->where('company_id', (int) $user->company_id)
            ->where('name', $name)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'name' => ['Já existe uma tag com esse nome.'],
            ]);
        }

        $tag = Tag::create([
            'company_id' => (int) $user->company_id,
            'name'       => $name,
            'color'      => $validated['color'],
        ]);

        $this->auditLog->record($request, 'company.tag.created', (int) $user->company_id, [
            'tag_id' => $tag->id,
            'name'   => $tag->name,
            'color'  => $tag->color,
        ]);

        Cache::forget(CacheKeys::companyTags((int) $user->company_id));

        return response()->json(['ok' => true, 'tag' => $this->serializeTag($tag)], 201);
    }

    /** PUT /minha-conta/tags/{tag} */
    public function update(UpdateConversationTagRequest $request, Tag $tag): JsonResponse
    {
        $user = $request->user();

        if ((int) $tag->company_id !== (int) $user->company_id) {
            return response()->json(['message' => 'Tag não encontrada.'], 404);
        }

        $validated = $request->validated();

        $name = mb_strtolower(trim($validated['name']));

        $conflict = Tag::query()
            ->where('company_id', (int) $user->company_id)
            ->where('name', $name)
            ->where('id', '!=', $tag->id)
            ->exists();

        if ($conflict) {
            throw ValidationException::withMessages([
                'name' => ['Já existe outra tag com esse nome.'],
            ]);
        }

        $tag->update(['name' => $name, 'color' => $validated['color']]);

        $this->auditLog->record($request, 'company.tag.updated', (int) $user->company_id, [
            'tag_id' => $tag->id,
            'name'   => $tag->name,
            'color'  => $tag->color,
        ]);

        Cache::forget(CacheKeys::companyTags((int) $user->company_id));

        return response()->json(['ok' => true, 'tag' => $this->serializeTag($tag)]);
    }

    /** DELETE /minha-conta/tags/{tag} */
    public function destroy(Request $request, Tag $tag): JsonResponse|Response
    {
        $user = $request->user();

        if ((int) $tag->company_id !== (int) $user->company_id) {
            return response()->json(['message' => 'Tag não encontrada.'], 404);
        }

        $tagId = $tag->id;
        $companyId = (int) $user->company_id;
        $tag->delete(); // cascade deletes conversation_tag rows

        $this->auditLog->record($request, 'company.tag.deleted', $companyId, [
            'tag_id' => $tagId,
        ]);

        Cache::forget(CacheKeys::companyTags($companyId));

        return response()->noContent();
    }

    // -------------------------------------------------------------------------
    // Attach / Detach tags to a conversation
    // -------------------------------------------------------------------------

    /** POST /minha-conta/conversas/{conversationId}/tags */
    public function attach(AttachConversationTagRequest $request, int $conversationId): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validated();

        $conversation = Conversation::query()
            ->where('company_id', (int) $user->company_id)
            ->find($conversationId);

        if (! $conversation) {
            return response()->json(['message' => 'Conversa não encontrada.'], 404);
        }

        $tag = Tag::query()
            ->where('company_id', (int) $user->company_id)
            ->find((int) $validated['tag_id']);

        if (! $tag) {
            return response()->json(['message' => 'Tag não encontrada.'], 404);
        }

        $conversation->tags()->syncWithoutDetaching([$tag->id]);

        $tags = $this->loadConversationTags($conversation);
        $this->publishTagsUpdated($conversation, $tags);

        $this->auditLog->record($request, 'company.conversation.tag_attached', (int) $user->company_id, [
            'conversation_id' => $conversation->id,
            'tag_id'          => $tag->id,
        ]);

        return response()->json(['ok' => true, 'tags' => $tags]);
    }

    /** DELETE /minha-conta/conversas/{conversationId}/tags/{tagId} */
    public function detach(Request $request, int $conversationId, int $tagId): JsonResponse
    {
        $user = $request->user();

        $conversation = Conversation::query()
            ->where('company_id', (int) $user->company_id)
            ->find($conversationId);

        if (! $conversation) {
            return response()->json(['message' => 'Conversa não encontrada.'], 404);
        }

        $tag = Tag::query()
            ->where('company_id', (int) $user->company_id)
            ->find($tagId);

        if (! $tag) {
            return response()->json(['message' => 'Tag não encontrada.'], 404);
        }

        $conversation->tags()->detach($tag->id);

        $tags = $this->loadConversationTags($conversation);
        $this->publishTagsUpdated($conversation, $tags);

        $this->auditLog->record($request, 'company.conversation.tag_detached', (int) $user->company_id, [
            'conversation_id' => $conversation->id,
            'tag_id'          => $tag->id,
        ]);

        return response()->json(['ok' => true, 'tags' => $tags]);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** @return array<int, array{id:int,name:string,color:string}> */
    private function loadConversationTags(Conversation $conversation): array
    {
        return $conversation->tags()
            ->select('tags.id', 'tags.name', 'tags.color')
            ->orderBy('tags.name')
            ->get()
            ->map(fn(Tag $t) => $this->serializeTag($t))
            ->values()
            ->toArray();
    }

    /** @return array{id:int,name:string,color:string} */
    private function serializeTag(Tag $tag): array
    {
        return [
            'id'    => (int) $tag->id,
            'name'  => (string) $tag->name,
            'color' => (string) $tag->color,
        ];
    }

    /** @param array<int, array{id:int,name:string,color:string}> $tags */
    private function publishTagsUpdated(Conversation $conversation, array $tags): void
    {
        $this->realtime->publish(
            RealtimeEvents::CONVERSATION_TAGS_UPDATED,
            [
                "company:{$conversation->company_id}",
                "conversation:{$conversation->id}",
            ],
            [
                'conversation_id' => (int) $conversation->id,
                'tags'            => $tags,
            ]
        );
    }

}
