<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Models\AiCompanyKnowledge;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiCompanyKnowledgeController extends Controller
{
    private const MAX_ITEMS = 50;

    public function index(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor) {
            return response()->json([
                'authenticated' => false,
                'redirect' => '/entrar',
            ], 403);
        }

        if (! $actor->isCompanyUser()) {
            return response()->json([
                'authenticated' => true,
                'message' => 'Acesso restrito a usuários da empresa.',
            ], 403);
        }

        $items = AiCompanyKnowledge::query()
            ->where('company_id', (int) $actor->company_id)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'authenticated' => true,
            'knowledge_items' => $items,
            'max_items' => self::MAX_ITEMS,
            'can_manage' => $actor->isCompanyAdmin(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor) {
            return response()->json([
                'authenticated' => false,
                'redirect' => '/entrar',
            ], 403);
        }

        if (! $actor->isCompanyAdmin()) {
            return response()->json([
                'authenticated' => true,
                'message' => 'Somente admin da empresa pode gerenciar a base de conhecimento.',
            ], 403);
        }

        $companyId = (int) $actor->company_id;
        $currentCount = AiCompanyKnowledge::query()
            ->where('company_id', $companyId)
            ->count();

        if ($currentCount >= self::MAX_ITEMS) {
            return response()->json([
                'message' => sprintf('Limite atingido. Máximo de %d conteúdos por empresa.', self::MAX_ITEMS),
            ], 422);
        }

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:190'],
            'content' => ['required', 'string', 'max:20000'],
            'is_active' => ['required', 'boolean'],
        ]);

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

    public function update(Request $request, AiCompanyKnowledge $knowledgeItem): JsonResponse
    {
        $actor = $request->user();
        if (! $actor) {
            return response()->json([
                'authenticated' => false,
                'redirect' => '/entrar',
            ], 403);
        }

        if (! $actor->isCompanyAdmin()) {
            return response()->json([
                'authenticated' => true,
                'message' => 'Somente admin da empresa pode gerenciar a base de conhecimento.',
            ], 403);
        }

        if ((int) $knowledgeItem->company_id !== (int) $actor->company_id) {
            return response()->json([
                'message' => 'Conteúdo não pertence à empresa.',
            ], 404);
        }

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:190'],
            'content' => ['required', 'string', 'max:20000'],
            'is_active' => ['required', 'boolean'],
        ]);

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

        if (! $actor->isCompanyAdmin()) {
            return response()->json([
                'authenticated' => true,
                'message' => 'Somente admin da empresa pode gerenciar a base de conhecimento.',
            ], 403);
        }

        if ((int) $knowledgeItem->company_id !== (int) $actor->company_id) {
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
<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Models\AiCompanyKnowledge;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiCompanyKnowledgeController extends Controller
{
    private const MAX_ITEMS = 50;

    public function index(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor) {
            return response()->json([
                'authenticated' => false,
                'redirect' => '/entrar',
            ], 403);
        }

        if (! $actor->isCompanyUser()) {
            return response()->json([
                'authenticated' => true,
                'message' => 'Acesso restrito a usuários da empresa.',
            ], 403);
        }

        $items = AiCompanyKnowledge::query()
            ->where('company_id', (int) $actor->company_id)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'authenticated' => true,
            'knowledge_items' => $items,
            'max_items' => self::MAX_ITEMS,
            'can_manage' => $actor->isCompanyAdmin(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor) {
            return response()->json([
                'authenticated' => false,
                'redirect' => '/entrar',
            ], 403);
        }

        if (! $actor->isCompanyAdmin()) {
            return response()->json([
                'authenticated' => true,
                'message' => 'Somente admin da empresa pode gerenciar a base de conhecimento.',
            ], 403);
        }

        $companyId = (int) $actor->company_id;
        $currentCount = AiCompanyKnowledge::query()
            ->where('company_id', $companyId)
            ->count();

        if ($currentCount >= self::MAX_ITEMS) {
            return response()->json([
                'message' => sprintf('Limite atingido. Máximo de %d conteúdos por empresa.', self::MAX_ITEMS),
            ], 422);
        }

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:190'],
            'content' => ['required', 'string', 'max:20000'],
            'is_active' => ['required', 'boolean'],
        ]);

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

    public function update(Request $request, AiCompanyKnowledge $knowledgeItem): JsonResponse
    {
        $actor = $request->user();
        if (! $actor) {
            return response()->json([
                'authenticated' => false,
                'redirect' => '/entrar',
            ], 403);
        }

        if (! $actor->isCompanyAdmin()) {
            return response()->json([
                'authenticated' => true,
                'message' => 'Somente admin da empresa pode gerenciar a base de conhecimento.',
            ], 403);
        }

        if ((int) $knowledgeItem->company_id !== (int) $actor->company_id) {
            return response()->json([
                'message' => 'Conteúdo não pertence à empresa.',
            ], 404);
        }

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:190'],
            'content' => ['required', 'string', 'max:20000'],
            'is_active' => ['required', 'boolean'],
        ]);

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

        if (! $actor->isCompanyAdmin()) {
            return response()->json([
                'authenticated' => true,
                'message' => 'Somente admin da empresa pode gerenciar a base de conhecimento.',
            ], 403);
        }

        if ((int) $knowledgeItem->company_id !== (int) $actor->company_id) {
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
