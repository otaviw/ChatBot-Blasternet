<?php

declare(strict_types=1);


namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Http\Requests\Company\StoreKnowledgeItemRequest;
use App\Http\Requests\Company\UpdateKnowledgeItemRequest;
use App\Models\AiCompanyKnowledge;
use App\Models\Company;
use App\Services\Ai\AiAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiCompanyKnowledgeController extends Controller
{
    private const MAX_ITEMS = 50;

    public function __construct(
        private readonly AiAccessService $aiAccess
    ) {}

    private function resolveCompanyId(Request $request, $actor): int
    {
        if ($actor->isSystemAdmin()) {
            return (int) $request->integer('company_id', 0);
        }

        return (int) $actor->company_id;
    }

    public function index(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor) {
            return response()->json([
                'authenticated' => false,
                'redirect' => '/entrar',
            ], 403);
        }

        if (! $this->aiAccess->canManageAi($actor)) {
            return response()->json([
                'authenticated' => true,
                'message' => 'Acesso restrito a admins da empresa.',
            ], 403);
        }

        $companies = $actor->isSystemAdmin()
            ? Company::orderBy('name')->get(['id', 'name'])
            : null;

        $companyId = $this->resolveCompanyId($request, $actor);

        if ($companyId <= 0) {
            return response()->json([
                'authenticated' => true,
                'is_admin' => true,
                'companies' => $companies,
                'knowledge_items' => [],
                'max_items' => self::MAX_ITEMS,
                'can_manage' => true,
            ]);
        }

        $items = AiCompanyKnowledge::query()
            ->where('company_id', $companyId)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'authenticated' => true,
            'is_admin' => $actor->isSystemAdmin(),
            'companies' => $companies,
            'selected_company_id' => $companyId,
            'knowledge_items' => $items,
            'max_items' => self::MAX_ITEMS,
            'can_manage' => $actor->isCompanyAdmin() || $actor->isSystemAdmin(),
        ]);
    }

    public function store(StoreKnowledgeItemRequest $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor) {
            return response()->json([
                'authenticated' => false,
                'redirect' => '/entrar',
            ], 403);
        }

        if (! $this->aiAccess->canManageAi($actor)) {
            return response()->json([
                'authenticated' => true,
                'message' => 'Somente admin da empresa pode gerenciar a base de conhecimento.',
            ], 403);
        }

        $companyId = $this->resolveCompanyId($request, $actor);
        if ($companyId <= 0) {
            return response()->json(['message' => 'Informe company_id.'], 422);
        }

        $currentCount = AiCompanyKnowledge::query()
            ->where('company_id', $companyId)
            ->count();

        if ($currentCount >= self::MAX_ITEMS) {
            return response()->json([
                'message' => sprintf('Limite atingido. Máximo de %d conteúdos por empresa.', self::MAX_ITEMS),
            ], 422);
        }

        $validated = $request->validated();

        $item = AiCompanyKnowledge::query()->create([
            'company_id' => $companyId,
            'title' => trim((string) $validated['title']),
            'content' => trim((string) $validated['content']),
            'is_active' => (bool) $validated['is_active'],
        ]);

        return response()->json([
            'ok' => true,
            'knowledge_item' => $item,
        ], 201);
    }

    public function update(UpdateKnowledgeItemRequest $request, AiCompanyKnowledge $knowledgeItem): JsonResponse
    {
        $actor = $request->user();
        if (! $actor) {
            return response()->json([
                'authenticated' => false,
                'redirect' => '/entrar',
            ], 403);
        }

        if (! $this->aiAccess->canManageAi($actor)) {
            return response()->json([
                'authenticated' => true,
                'message' => 'Somente admin da empresa pode gerenciar a base de conhecimento.',
            ], 403);
        }

        if (! $actor->isSystemAdmin() && (int) $knowledgeItem->company_id !== (int) $actor->company_id) {
            return response()->json([
                'message' => 'Conteúdo não pertence à empresa.',
            ], 404);
        }

        $validated = $request->validated();

        $knowledgeItem->update([
            'title' => trim((string) $validated['title']),
            'content' => trim((string) $validated['content']),
            'is_active' => (bool) $validated['is_active'],
        ]);

        return response()->json([
            'ok' => true,
            'knowledge_item' => $knowledgeItem->fresh(),
        ]);
    }

    public function destroy(Request $request, AiCompanyKnowledge $knowledgeItem): JsonResponse
    {
        $actor = $request->user();
        if (! $actor) {
            return response()->json([
                'authenticated' => false,
                'redirect' => '/entrar',
            ], 403);
        }

        if (! $this->aiAccess->canManageAi($actor)) {
            return response()->json([
                'authenticated' => true,
                'message' => 'Somente admin da empresa pode gerenciar a base de conhecimento.',
            ], 403);
        }

        if (! $actor->isSystemAdmin() && (int) $knowledgeItem->company_id !== (int) $actor->company_id) {
            return response()->json([
                'message' => 'Conteúdo não pertence à empresa.',
            ], 404);
        }

        $knowledgeItem->delete();

        return response()->json([
            'ok' => true,
        ]);
    }
}
