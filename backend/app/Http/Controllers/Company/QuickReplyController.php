<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Models\QuickReply;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class QuickReplyController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user || !$user->isCompanyUser()) {
            return response()->json(['authenticated' => false, 'redirect' => '/entrar'], 403);
        }

        $replies = QuickReply::where('company_id', $user->company_id)
            ->orderBy('title')
            ->get();

        return response()->json([
            'authenticated' => true,
            'quick_replies' => $replies,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user || !$user->isCompanyUser()) {
            return response()->json(['authenticated' => false, 'redirect' => '/entrar'], 403);
        }

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:100'],
            'text'  => ['required', 'string', 'max:2000'],
        ]);

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

    public function update(Request $request, QuickReply $quickReply): JsonResponse
    {
        $user = $request->user();
        if (!$user || !$user->isCompanyUser()) {
            return response()->json(['authenticated' => false, 'redirect' => '/entrar'], 403);
        }

        if ((int) $quickReply->company_id !== (int) $user->company_id) {
            return response()->json(['message' => 'Não autorizado.'], 403);
        }

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:100'],
            'text'  => ['required', 'string', 'max:2000'],
        ]);

        $quickReply->update($validated);

        return response()->json([
            'ok'          => true,
            'quick_reply' => $quickReply,
        ]);
    }

    public function destroy(Request $request, QuickReply $quickReply): JsonResponse
    {
        $user = $request->user();
        if (!$user || !$user->isCompanyUser()) {
            return response()->json(['authenticated' => false, 'redirect' => '/entrar'], 403);
        }

        if ((int) $quickReply->company_id !== (int) $user->company_id) {
            return response()->json(['message' => 'Não autorizado.'], 403);
        }

        $quickReply->delete();

        return response()->json(['ok' => true]);
    }
}
