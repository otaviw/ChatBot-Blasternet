<?php

namespace Tests\Feature;

use App\Models\AiAuditLog;
use App\Models\Company;
use App\Services\Ai\AiAuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiAuditLogSanitizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_gate_result_reply_comparison_does_not_persist_full_replies(): void
    {
        $company = Company::create(['name' => 'Empresa Sanitizacao']);

        $service = $this->app->make(AiAuditService::class);
        $service->logMessageSent(
            (int) $company->id,
            null,
            null,
            [
                'gate_result' => [
                    'reply_comparison' => [
                        'legacy_reply' => 'Meu CPF eh 123.456.789-00',
                        'ai_reply' => 'Mensagem ajustada sem PII.',
                        'action' => 'replace_with_ai',
                        'confidence' => 0.91,
                    ],
                ],
            ]
        );

        $log = AiAuditLog::query()->latest('id')->first();
        $this->assertNotNull($log);

        $replyComparison = $log->metadata['gate_result']['reply_comparison'] ?? null;
        $this->assertIsArray($replyComparison);
        $this->assertArrayNotHasKey('legacy_reply', $replyComparison);
        $this->assertArrayNotHasKey('ai_reply', $replyComparison);
        $this->assertSame(25, $replyComparison['legacy_reply_length'] ?? null);
        $this->assertSame(26, $replyComparison['ai_reply_length'] ?? null);
        $this->assertSame(hash('sha256', 'Meu CPF eh 123.456.789-00'), $replyComparison['legacy_reply_hash'] ?? null);
        $this->assertSame(hash('sha256', 'Mensagem ajustada sem PII.'), $replyComparison['ai_reply_hash'] ?? null);
        $this->assertTrue((bool) ($replyComparison['changed_response'] ?? false));
        $this->assertSame('replace_with_ai', $replyComparison['action'] ?? null);
        $this->assertSame(0.91, $replyComparison['confidence'] ?? null);
    }
}
