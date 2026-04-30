<?php

declare(strict_types=1);


namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Http\Requests\Company\StoreQuickReplyRequest;
use App\Http\Requests\Company\UpdateQuickReplyRequest;
use App\Models\QuickReply;
use Illuminate\Http\JsonResponse;

class QuickReplyController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', QuickReply::class);

        $replies = QuickReply::orderBy('title')->get();

        return response()->json([
            'authenticated' => true,
            'quick_replies' => $replies,
        ]);
    }

    public function store(StoreQuickReplyRequest $request): JsonResponse
    {
        $this->authorize('create', QuickReply::class);

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
        $this->authorize('update', $quickReply);

        $validated = $request->validated();

        $quickReply->update($validated);

        return response()->json([
            'ok'          => true,
            'quick_reply' => $quickReply,
        ]);
    }

    public function destroy(QuickReply $quickReply): JsonResponse
    {
        $this->authorize('delete', $quickReply);

        $quickReply->delete();

        return response()->json(['ok' => true]);
    }
}
