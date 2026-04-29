<?php

namespace App\Services\Admin;

use App\Models\Company;
use App\Models\Message;
use App\Models\User;
use App\Support\ConversationHandlingMode;

class CompanyMetricsService
{
    /**
     * @return array<string, mixed>
     */
    public function build(Company $company): array
    {
        $conversationIds = $company->conversations()->select('id');

        $byStatus = $company->conversations()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $byMode = $company->conversations()
            ->where('status', 'closed')
            ->selectRaw(
                'CASE WHEN handling_mode = ? THEN ? ELSE handling_mode END as normalized_mode, count(*) as total',
                [ConversationHandlingMode::LEGACY_MANUAL, ConversationHandlingMode::HUMAN]
            )
            ->groupBy('normalized_mode')
            ->pluck('total', 'normalized_mode');

        $byDay = $company->conversations()
            ->selectRaw('DATE(created_at) as day, count(*) as total')
            ->where('created_at', '>=', now()->subDays(30))
            ->groupBy('day')
            ->orderBy('day')
            ->get();

        $totalMessages = Message::whereIn('conversation_id', $conversationIds)->count();
        $totalUsers = User::where('company_id', $company->id)->count();

        return [
            'by_status' => $byStatus,
            'by_mode' => $byMode,
            'by_day' => $byDay,
            'total' => $company->conversations()->count(),
            'total_messages' => $totalMessages,
            'total_users' => $totalUsers,
        ];
    }
}

