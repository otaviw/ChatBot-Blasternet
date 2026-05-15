<?php

namespace Tests\Unit\Services\Company;

use App\Models\Company;
use App\Models\CompanyMetaNumber;
use App\Models\Contact;
use App\Models\User;
use App\Services\Company\CompanyMetaNumberService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyMetaNumberServiceReassignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_reassigns_contacts_to_only_remaining_active_number(): void
    {
        $admin = $this->makeSystemAdmin();
        $company = Company::create(['name' => 'Empresa Reassign 1']);

        $toRemove = CompanyMetaNumber::create([
            'company_id' => $company->id,
            'phone_number' => '5511999000001',
            'is_active' => true,
            'is_primary' => true,
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);

        $remaining = CompanyMetaNumber::create([
            'company_id' => $company->id,
            'phone_number' => '5511999000002',
            'is_active' => true,
            'is_primary' => false,
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);

        Contact::create([
            'company_id' => $company->id,
            'name' => 'Contato 1',
            'phone' => '5511888880001',
            'meta_number_id' => $toRemove->id,
        ]);

        app(CompanyMetaNumberService::class)->deactivateOrRemove((int) $company->id, (int) $toRemove->id, $admin, 'deactivate');

        $this->assertDatabaseHas('contacts', [
            'company_id' => $company->id,
            'phone' => '5511888880001',
            'meta_number_id' => $remaining->id,
        ]);
    }

    public function test_reassigns_contacts_to_primary_when_multiple_active_numbers_remain(): void
    {
        $admin = $this->makeSystemAdmin();
        $company = Company::create(['name' => 'Empresa Reassign 2']);

        $toRemove = CompanyMetaNumber::create([
            'company_id' => $company->id,
            'phone_number' => '5511999111101',
            'is_active' => true,
            'is_primary' => false,
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);

        $primary = CompanyMetaNumber::create([
            'company_id' => $company->id,
            'phone_number' => '5511999111102',
            'is_active' => true,
            'is_primary' => true,
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);

        CompanyMetaNumber::create([
            'company_id' => $company->id,
            'phone_number' => '5511999111103',
            'is_active' => true,
            'is_primary' => false,
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);

        Contact::create([
            'company_id' => $company->id,
            'name' => 'Contato 2',
            'phone' => '5511777770002',
            'meta_number_id' => $toRemove->id,
        ]);

        app(CompanyMetaNumberService::class)->deactivateOrRemove((int) $company->id, (int) $toRemove->id, $admin, 'deactivate');

        $this->assertDatabaseHas('contacts', [
            'company_id' => $company->id,
            'phone' => '5511777770002',
            'meta_number_id' => $primary->id,
        ]);
    }

    public function test_sets_contacts_meta_number_to_null_when_no_active_number_remains(): void
    {
        $admin = $this->makeSystemAdmin();
        $company = Company::create(['name' => 'Empresa Reassign 3']);

        $toRemove = CompanyMetaNumber::create([
            'company_id' => $company->id,
            'phone_number' => '5511999222201',
            'is_active' => true,
            'is_primary' => true,
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);

        Contact::create([
            'company_id' => $company->id,
            'name' => 'Contato 3',
            'phone' => '5511666660003',
            'meta_number_id' => $toRemove->id,
        ]);

        app(CompanyMetaNumberService::class)->deactivateOrRemove((int) $company->id, (int) $toRemove->id, $admin, 'remove');

        $this->assertDatabaseHas('contacts', [
            'company_id' => $company->id,
            'phone' => '5511666660003',
            'meta_number_id' => null,
        ]);
    }

    private function makeSystemAdmin(): User
    {
        return User::create([
            'name' => 'Sys Admin Reassign',
            'email' => 'sys-admin-reassign-' . uniqid() . '@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_SYSTEM_ADMIN,
            'is_active' => true,
        ]);
    }
}

