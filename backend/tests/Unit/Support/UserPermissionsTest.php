<?php

namespace Tests\Unit\Support;

use App\Support\Enums\UserRole;
use App\Support\UserPermissions;
use PHPUnit\Framework\TestCase;

class UserPermissionsTest extends TestCase
{
    public function test_resolve_returns_all_permissions_for_admin_profiles(): void
    {
        $this->assertSame(UserPermissions::ALL, UserPermissions::resolve(UserRole::SYSTEM_ADMIN->value, []));
        $this->assertSame(UserPermissions::ALL, UserPermissions::resolve(UserRole::COMPANY_ADMIN->value, []));
        $this->assertSame(UserPermissions::ALL, UserPermissions::resolve('admin', []));
    }

    public function test_resolve_returns_agent_defaults_when_permissions_are_null(): void
    {
        $this->assertSame(
            UserPermissions::AGENT_DEFAULTS,
            UserPermissions::resolve(UserRole::AGENT->value, null)
        );
    }

    public function test_resolve_filters_unknown_permissions_for_agents(): void
    {
        $resolved = UserPermissions::resolve(UserRole::AGENT->value, [
            UserPermissions::PAGE_INBOX,
            'invalid_permission',
            UserPermissions::ACTION_MANAGE_TAGS,
        ]);

        $this->assertSame([
            UserPermissions::PAGE_INBOX,
            UserPermissions::ACTION_MANAGE_TAGS,
        ], $resolved);
    }
}

