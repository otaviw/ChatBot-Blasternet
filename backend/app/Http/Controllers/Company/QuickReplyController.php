<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Http\Requests\Company\StoreQuickReplyRequest;
use App\Http\Requests\Company\UpdateQuickReplyRequest;
use App\Models\QuickReply;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class QuickReplyController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        // CompanyScope já filtra por company_id do usuário autenticado
        $replies = QuickReply::orderBy('title')->get();

        return response()->json([
            'authenticated' => true,
            'quick_replies' => $replies,
        ]);
    }

    public function store(StoreQuickReplyRequest $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validated();

        $reply = QuickReply::create([
            'company_id' => $user->company_id,
            'title'      => $validated['title'],
            'text'       => $validated['text'],
        ]);

        return response()->json([
            'ok'          => true,
            'quick_reply' => $reply,
        ], 201);
    }

    public function update(UpdateQuickReplyRequest $request, QuickReply $quickReply): JsonResponse
    {
        $user = $request->user();

        if ((int) $quickReply->company_id !== (int) $user->company_id) {
            return response()->json(['message' => 'Não autorizado.'], 403);
        }

        $validated = $request->validated();

        $quickReply->update($validated);

        return response()->json([
            'ok'          => true,
            'quick_reply' => $quickReply,
        ]);
    }

    public function destroy(Request $request, QuickReply $quickReply): JsonResponse
    {
        $user = $request->user();

        if ((int) $quickReply->company_id !== (int) $user->company_id) {
            return response()->json(['message' => 'Não autorizado.'], 403);
        }

        $quickReply->delete();

        return response()->json(['ok' => true]);
    }
}
