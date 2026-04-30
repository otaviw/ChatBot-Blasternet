<?php

declare(strict_types=1);


namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Http\Requests\Company\StoreAiFeedbackRequest;
use App\Models\AiSuggestionFeedback;
use App\Models\AiUsageLog;
use Illuminate\Http\JsonResponse;

class AiSuggestionFeedbackController extends Controller
{
    public function store(StoreAiFeedbackRequest $request, int $suggestionId): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validated();

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
