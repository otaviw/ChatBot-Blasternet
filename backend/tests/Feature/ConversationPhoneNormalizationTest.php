<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Conversation;
use App\Models\User;
use App\Support\ConversationAssignedType;
use App\Support\ConversationHandlingMode;
use App\Support\ConversationStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConversationPhoneNormalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_conversation_reuses_existing_contact_with_or_without_nine_digit(): void
    {
        $company = Company::create(['name' => 'Empresa Conversa']);
        $admin = User::create([
            'name' => 'Admin Conversa',
            'email' => 'admin-conversa@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_COMPANY_ADMIN,
            'company_id' => $company->id,
            'is_active' => true,
        ]);

        $existing = Conversation::create([
            'company_id' => (int) $company->id,
            'customer_phone' => '5511987654321',
            'customer_name' => 'Cliente Existente',
            'status' => ConversationStatus::OPEN,
            'assigned_type' => ConversationAssignedType::UNASSIGNED,
            'handling_mode' => ConversationHandlingMode::BOT,
        ]);

        $response = $this->actingAs($admin)->postJson('/api/minha-conta/conversas', [
            'customer_phone' => '11 8765-4321',
            'customer_name' => 'Cliente Existente',
            'send_template' => false,
        ]);

        $response->assertOk();
        $response->assertJsonPath('conversation.id', (int) $existing->id);
        $response->assertJsonPath('conversation.customer_phone', '5511987654321');

        $this->assertSame(
            1,
            Conversation::query()->where('company_id', (int) $company->id)->count()
        );
    }
}

