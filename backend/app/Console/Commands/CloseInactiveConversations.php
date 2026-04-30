<?php

declare(strict_types=1);


namespace App\Console\Commands;

use App\Services\ConversationInactivityService;
use Illuminate\Console\Command;

class CloseInactiveConversations extends Command
{
    protected $signature = 'conversations:close-inactive';
    protected $description = 'Fecha conversas sem mensagem por mais de X horas';

    public function __construct(
        private ConversationInactivityService $conversationInactivityService
    ) {
        parent::__construct();
    }

    public function handle(): void
    {
        $total = $this->conversationInactivityService->closeInactiveConversations();

        $this->info("Fechadas {$total} conversas inativas.");
    }
}
