<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Models\UserNotificationPreference;
use App\Services\NotificationPreferenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationPreferenceServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_enabled_recipient_ids_respects_stored_preferences_and_default_true(): void
    {
        $company = Company::create(['name' => 'Empresa Notif Pref']);

        $enabledUser = User::create([
            'name' => 'Enabled User',
            'email' => 'enabled-pref@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_COMPANY_ADMIN,
            'company_id' => $company->id,
            'is_active' => true,
        ]);

        $disabledUser = User::create([
            'name' => 'Disabled User',
            'email' => 'disabled-pref@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_COMPANY_ADMIN,
            'company_id' => $company->id,
            'is_active' => true,
        ]);

        $defaultUser = User::create([
            'name' => 'Default User',
            'email' => 'default-pref@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_COMPANY_ADMIN,
            'company_id' => $company->id,
            'is_active' => true,
        ]);

        UserNotificationPreference::create([
            'user_id' => $enabledUser->id,
            'preferences' => [
                'customer_message' => true,
            ],
        ]);

        UserNotificationPreference::create([
            'user_id' => $disabledUser->id,
            'preferences' => [
                'customer_message' => false,
            ],
        ]);

        $service = app(NotificationPreferenceService::class);

        $result = $service->enabledRecipientIds([
            (int) $enabledUser->id,
            (int) $disabledUser->id,
            (int) $defaultUser->id,
        ], 'customer_message');

        $this->assertSame([
            (int) $enabledUser->id,
            (int) $defaultUser->id,
        ], array_values($result));
    }
}
