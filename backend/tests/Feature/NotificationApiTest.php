<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_list_only_their_notifications(): void
    {
        $company = Company::create(['name' => 'Empresa Notif']);

        $user = User::create([
            'name' => 'User Owner',
            'email' => 'owner-notif@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_COMPANY_ADMIN,
            'company_id' => $company->id,
            'is_active' => true,
        ]);

        $other = User::create([
            'name' => 'User Other',
            'email' => 'other-notif@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_COMPANY_ADMIN,
            'company_id' => $company->id,
            'is_active' => true,
        ]);

        $first = Notification::create([
            'user_id' => $user->id,
            'type' => 'conversation_message',
            'module' => 'inbox',
            'title' => 'Nova mensagem',
            'text' => 'Mensagem 1',
            'is_read' => true,
        ]);

        $second = Notification::create([
            'user_id' => $user->id,
            'type' => 'conversation_transfer',
            'module' => 'inbox',
            'title' => 'Transferencia',
            'text' => 'Mensagem 2',
        ]);

        Notification::create([
            'user_id' => $other->id,
            'type' => 'support_ticket',
            'module' => 'support',
            'title' => 'Solicitacao',
            'text' => 'Mensagem 3',
        ]);

        $response = $this->actingAs($user)->getJson('/api/notifications');

        $response->assertOk();
        $response->assertJsonPath('ok', true);
        $response->assertJsonCount(2, 'notifications');
        $response->assertJsonPath('notifications.0.id', $second->id);
        $response->assertJsonPath('notifications.1.id', $first->id);

        $unreadOnly = $this->actingAs($user)->getJson('/api/notifications?unread=1');
        $unreadOnly->assertOk();
        $unreadOnly->assertJsonCount(1, 'notifications');
        $unreadOnly->assertJsonPath('notifications.0.id', $second->id);
    }

    public function test_user_can_mark_own_notification_as_read(): void
    {
        $company = Company::create(['name' => 'Empresa Read']);

        $user = User::create([
            'name' => 'User Read',
            'email' => 'user-read@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_COMPANY_ADMIN,
            'company_id' => $company->id,
            'is_active' => true,
        ]);

        $notification = Notification::create([
            'user_id' => $user->id,
            'type' => 'support_ticket',
            'module' => 'support',
            'title' => 'Ticket aberto',
            'text' => 'Novo ticket',
            'is_read' => false,
        ]);

        $response = $this->actingAs($user)->postJson("/api/notifications/{$notification->id}/read");

        $response->assertOk();
        $response->assertJsonPath('ok', true);
        $response->assertJsonPath('notification.is_read', true);
        $response->assertJsonPath('total_unread', 0);

        $notification->refresh();
        $this->assertTrue((bool) $notification->is_read);
        $this->assertNotNull($notification->read_at);
    }

    public function test_user_cannot_mark_other_user_notification_as_read(): void
    {
        $company = Company::create(['name' => 'Empresa Owner']);

        $owner = User::create([
            'name' => 'Owner',
            'email' => 'owner-read@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_COMPANY_ADMIN,
            'company_id' => $company->id,
            'is_active' => true,
        ]);

        $intruder = User::create([
            'name' => 'Intruder',
            'email' => 'intruder-read@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_COMPANY_ADMIN,
            'company_id' => $company->id,
            'is_active' => true,
        ]);

        $notification = Notification::create([
            'user_id' => $owner->id,
            'type' => 'generic',
            'module' => 'general',
            'title' => 'Privada',
            'text' => 'Apenas dono',
            'is_read' => false,
        ]);

        $response = $this->actingAs($intruder)->postJson("/api/notifications/{$notification->id}/read");

        $response->assertStatus(404);
        $notification->refresh();
        $this->assertFalse((bool) $notification->is_read);
        $this->assertNull($notification->read_at);
    }

    public function test_user_receives_unread_counts_grouped_by_module(): void
    {
        $company = Company::create(['name' => 'Empresa Count']);

        $user = User::create([
            'name' => 'User Count',
            'email' => 'count-notif@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_COMPANY_ADMIN,
            'company_id' => $company->id,
            'is_active' => true,
        ]);

        Notification::create([
            'user_id' => $user->id,
            'type' => 'conversation_message',
            'module' => 'inbox',
            'title' => 'N1',
            'text' => 'N1',
            'is_read' => false,
        ]);

        Notification::create([
            'user_id' => $user->id,
            'type' => 'conversation_transfer',
            'module' => 'inbox',
            'title' => 'N2',
            'text' => 'N2',
            'is_read' => false,
        ]);

        Notification::create([
            'user_id' => $user->id,
            'type' => 'support_ticket',
            'module' => 'support',
            'title' => 'N3',
            'text' => 'N3',
            'is_read' => false,
        ]);

        Notification::create([
            'user_id' => $user->id,
            'type' => 'support_ticket',
            'module' => 'support',
            'title' => 'N4',
            'text' => 'N4',
            'is_read' => true,
        ]);

        $response = $this->actingAs($user)->getJson('/api/notifications/unread-counts');

        $response->assertOk();
        $response->assertJsonPath('ok', true);
        $response->assertJsonPath('unread_by_module.inbox', 2);
        $response->assertJsonPath('unread_by_module.support', 1);
        $response->assertJsonPath('total_unread', 3);
    }

    public function test_user_can_mark_notifications_as_read_by_reference(): void
    {
        $company = Company::create(['name' => 'Empresa Ref']);

        $user = User::create([
            'name' => 'User Ref',
            'email' => 'ref-notif@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_COMPANY_ADMIN,
            'company_id' => $company->id,
            'is_active' => true,
        ]);

        Notification::create([
            'user_id' => $user->id,
            'type' => 'customer_message',
            'module' => 'inbox',
            'title' => 'N1',
            'text' => 'N1',
            'reference_type' => 'conversation',
            'reference_id' => 10,
            'is_read' => false,
        ]);

        Notification::create([
            'user_id' => $user->id,
            'type' => 'conversation_transferred',
            'module' => 'inbox',
            'title' => 'N2',
            'text' => 'N2',
            'reference_type' => 'conversation',
            'reference_id' => 10,
            'is_read' => false,
        ]);

        Notification::create([
            'user_id' => $user->id,
            'type' => 'customer_message',
            'module' => 'inbox',
            'title' => 'N3',
            'text' => 'N3',
            'reference_type' => 'conversation',
            'reference_id' => 99,
            'is_read' => false,
        ]);

        $response = $this->actingAs($user)->postJson('/api/notifications/read-by-reference', [
            'module' => 'inbox',
            'reference_type' => 'conversation',
            'reference_id' => 10,
        ]);

        $response->assertOk();
        $response->assertJsonPath('ok', true);
        $response->assertJsonPath('marked_count', 2);
        $response->assertJsonPath('unread_by_module.inbox', 1);
        $response->assertJsonPath('total_unread', 1);
    }
}

