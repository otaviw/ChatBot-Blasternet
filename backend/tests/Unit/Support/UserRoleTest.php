<?php

namespace Tests\Unit\Support;

use App\Support\Enums\UserRole;
use PHPUnit\Framework\TestCase;

class UserRoleTest extends TestCase
{
    public function test_normalize_returns_canonical_role_values(): void
    {
        $this->assertSame(UserRole::SYSTEM_ADMIN->value, UserRole::normalize('system_admin'));
        $this->assertSame(UserRole::RESELLER_ADMIN->value, UserRole::normalize('reseller_admin'));
        $this->assertSame(UserRole::COMPANY_ADMIN->value, UserRole::normalize('company_admin'));
        $this->assertSame(UserRole::AGENT->value, UserRole::normalize('agent'));
    }

    public function test_normalize_maps_legacy_aliases_to_canonical_values(): void
    {
        $this->assertSame(UserRole::SYSTEM_ADMIN->value, UserRole::normalize('admin'));
        $this->assertSame(UserRole::COMPANY_ADMIN->value, UserRole::normalize('company'));
    }
}

