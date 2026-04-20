<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Models\AiSuggestionFeedback;
use App\Models\AiUsageLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiSuggestionFeedbackController extends Controller
{
    public function store(Request $request, int $suggestionId): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'helpful' => ['required', 'boolean'],
            'reason'  => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        // Verify the suggestion log belongs to the user's company
        $log = AiUsageLog::query()
            ->where('company_id', (int) $user->company_id)
            ->where('id', $suggestionId)
            ->first();

        if (! $log) {
            return response()->json(['message' => 'Sugestão não encontrada.'], 404);
        }

        AiSuggestionFeedback::updateOrCreate(
            ['suggestion_id' => $suggestionId, 'user_id' => (int) $user->id],
            ['helpful' => (bool) $validated['helpful'], 'reason' => $validated['reason'] ?? null]
        );

        return response()->json(['ok' => true]);
    }
}
