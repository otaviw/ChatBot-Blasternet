<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ProcessWhatsAppWebhookJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WebhookDispatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_handle_dispatches_job_only_for_messages_field(): void
    {
        config()->set('whatsapp.app_secret', 'test-secret');
        Queue::fake();

        $payload = [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'changes' => [
                    ['field' => 'statuses', 'value' => ['statuses' => [['id' => 'wamid.1']]]],
                    ['field' => 'messages', 'value' => ['messages' => [['id' => 'wamid.2']]]],
                    ['field' => 'message_template_status_update', 'value' => ['event' => 'x']],
                ],
            ]],
        ];

        $this->webhookPost($payload)->assertOk();

        Queue::assertPushed(ProcessWhatsAppWebhookJob::class, 1);
    }

    public function test_handle_does_not_dispatch_for_non_whatsapp_object(): void
    {
        config()->set('whatsapp.app_secret', 'test-secret');
        Queue::fake();

        $payload = [
            'object' => 'page',
            'entry' => [[
                'changes' => [
                    ['field' => 'messages', 'value' => ['messages' => [['id' => 'wamid.3']]]],
                ],
            ]],
        ];

        $this->webhookPost($payload)->assertOk();

        Queue::assertNothingPushed();
    }
}
