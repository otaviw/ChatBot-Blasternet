<?php

namespace Tests\Unit\Services\Admin;

use App\Models\Company;
use App\Models\User;
use App\Services\Admin\CompanyOwnershipService;
use PHPUnit\Framework\TestCase;

class CompanyOwnershipServiceTest extends TestCase
{
    public function test_resolve_reseller_id_for_system_admin_returns_null(): void
    {
        $service = new CompanyOwnershipService();
        $user = new User([
            'role' => User::ROLE_SYSTEM_ADMIN,
            'is_active' => true,
        ]);

        $this->assertNull($service->resolveResellerId($user));
    }

    public function test_resolve_reseller_id_for_reseller_admin_uses_user_or_company_scope(): void
    {
        $service = new CompanyOwnershipService();

        $userWithReseller = new User([
            'role' => User::ROLE_RESELLER_ADMIN,
            'is_active' => true,
            'reseller_id' => 15,
        ]);
        $this->assertSame(15, $service->resolveResellerId($userWithReseller));

        $userWithCompanyReseller = new User([
            'role' => User::ROLE_RESELLER_ADMIN,
            'is_active' => true,
            'reseller_id' => null,
        ]);
        $userWithCompanyReseller->setRelation('company', new Company([
            'reseller_id' => 21,
        ]));

        $this->assertSame(21, $service->resolveResellerId($userWithCompanyReseller));
    }

    public function test_can_access_company_obeys_scope_rules(): void
    {
        $service = new CompanyOwnershipService();

        $systemAdmin = new User([
            'role' => User::ROLE_SYSTEM_ADMIN,
            'is_active' => true,
        ]);
        $scopedCompany = new Company(['reseller_id' => 9]);
        $unscopedCompany = new Company(['reseller_id' => null]);

        $this->assertTrue($service->canAccessCompany($systemAdmin, $scopedCompany));
        $this->assertFalse($service->canAccessCompany($systemAdmin, $unscopedCompany));

        $resellerAdmin = new User([
            'role' => User::ROLE_RESELLER_ADMIN,
            'is_active' => true,
            'reseller_id' => 9,
        ]);

        $this->assertTrue($service->canAccessCompany($resellerAdmin, $scopedCompany));
        $this->assertFalse($service->canAccessCompany($resellerAdmin, new Company(['reseller_id' => 10])));
    }
}

