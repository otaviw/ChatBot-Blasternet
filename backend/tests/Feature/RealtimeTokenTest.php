<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RealtimeTokenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'realtime.enabled' => true,
            'realtime.jwt.secret' => 'test-realtime-secret',
            'realtime.jwt.issuer' => 'http://localhost',
            'realtime.jwt.audience' => 'realtime',
            'realtime.jwt.token_ttl_seconds' => 120,
            'realtime.jwt.join_token_ttl_seconds' => 45,
        ]);
    }

    public function test_authenticated_user_receives_short_lived_socket_token(): void
    {
        $company = Company::create(['name' => 'Empresa Token']);
        $user = User::create([
            'name' => 'Realtime User',
            'email' => 'realtime-user@test.local',
            'password' => 'secret123',
            'role' => 'company',
            'company_id' => $company->id,
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)->postJson('/api/realtime/token');

        $response->assertOk();
        $response->assertJsonStructure([
            'token',
            'ttl_seconds',
            'expires_at',
            'socket_url',
            'transports',
        ]);
        $response->assertJsonPath('ttl_seconds', 120);
        $response->assertJsonPath('transports.0', 'websocket');

        $payload = $this->decodeJwtPayload((string) $response->json('token'));
        $this->assertSame((string) $user->id, (string) ($payload['sub'] ?? ''));
        $this->assertSame((int) $company->id, (int) ($payload['companyId'] ?? 0));
        $this->assertSame('socket', (string) ($payload['type'] ?? ''));
        $this->assertSame('realtime', (string) ($payload['aud'] ?? ''));
        $this->assertArrayHasKey('exp', $payload);
    }

    public function test_company_user_cannot_request_join_token_for_other_company_conversation(): void
    {
        $companyA = Company::create(['name' => 'Empresa A']);
        $companyB = Company::create(['name' => 'Empresa B']);

        $user = User::create([
            'name' => 'Company A User',
            'email' => 'company-a-user@test.local',
            'password' => 'secret123',
            'role' => 'company',
            'company_id' => $companyA->id,
            'is_active' => true,
        ]);

        $conversation = Conversation::create([
            'company_id' => $companyB->id,
            'customer_phone' => '5511999999999',
            'status' => 'open',
            'assigned_type' => 'unassigned',
            'handling_mode' => 'bot',
        ]);

        $response = $this->actingAs($user)
            ->postJson("/api/realtime/conversations/{$conversation->id}/join-token");

        $response->assertStatus(403);
    }

    public function test_admin_can_request_conversation_join_token(): void
    {
        $company = Company::create(['name' => 'Empresa Admin']);
        $admin = User::create([
            'name' => 'Admin',
            'email' => 'admin-realtime@test.local',
            'password' => 'secret123',
            'role' => 'admin',
            'company_id' => null,
            'is_active' => true,
        ]);

        $conversation = Conversation::create([
            'company_id' => $company->id,
            'customer_phone' => '5511998888888',
            'status' => 'open',
            'assigned_type' => 'unassigned',
            'handling_mode' => 'bot',
        ]);

        $response = $this->actingAs($admin)
            ->postJson("/api/realtime/conversations/{$conversation->id}/join-token");

        $response->assertOk();
        $response->assertJsonPath('conversation_id', $conversation->id);

        $payload = $this->decodeJwtPayload((string) $response->json('token'));
        $this->assertSame((string) $admin->id, (string) ($payload['sub'] ?? ''));
        $this->assertSame('conversation_join', (string) ($payload['type'] ?? ''));
        $this->assertSame((int) $conversation->id, (int) ($payload['conversationId'] ?? 0));
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJwtPayload(string $token): array
    {
        $parts = explode('.', $token);
        $payload = $parts[1] ?? '';
        $decoded = json_decode($this->base64UrlDecode($payload), true);

        return is_array($decoded) ? $decoded : [];
    }

    private function base64UrlDecode(string $value): string
    {
        $remainder = strlen($value) % 4;
        if ($remainder > 0) {
            $value .= str_repeat('=', 4 - $remainder);
        }

        return base64_decode(strtr($value, '-_', '+/')) ?: '';
    }
}
