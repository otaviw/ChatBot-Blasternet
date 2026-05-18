<?php

namespace Tests\Unit\Services\Company;

use App\Models\Company;
use App\Models\CompanyMetaNumber;
use App\Models\User;
use App\Services\Company\CompanyMetaNumberService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyMetaNumberServicePrimaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_set_primary_keeps_uniqueness(): void
    {
        $admin = User::create([
            'name' => 'System Admin',
            'email' => 'sys-admin-primary@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_SYSTEM_ADMIN,
            'is_active' => true,
        ]);

        $company = Company::create(['name' => 'Empresa Primary']);

        $numberA = CompanyMetaNumber::create([
            'company_id' => $company->id,
            'phone_number' => '5511991200001',
            'is_active' => true,
            'is_primary' => true,
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);

        $numberB = CompanyMetaNumber::create([
            'company_id' => $company->id,
            'phone_number' => '5511991200002',
            'is_active' => true,
            'is_primary' => false,
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);

        app(CompanyMetaNumberService::class)->setPrimary((int) $company->id, (int) $numberB->id, $admin);

        $primaryCount = CompanyMetaNumber::query()
            ->where('company_id', $company->id)
            ->where('is_primary', true)
            ->count();

        $this->assertSame(1, $primaryCount);
        $this->assertDatabaseHas('company_meta_numbers', ['id' => $numberA->id, 'is_primary' => 0]);
        $this->assertDatabaseHas('company_meta_numbers', ['id' => $numberB->id, 'is_primary' => 1]);
    }
}

