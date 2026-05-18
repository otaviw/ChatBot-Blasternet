<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMetaNumber;
use App\Models\Contact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MetaNumbersBackfillCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfill_assigns_primary_to_contacts_without_meta_number(): void
    {
        $company = Company::create(['name' => 'Empresa Backfill']);

        $primary = CompanyMetaNumber::create([
            'company_id' => $company->id,
            'phone_number' => '5511997000001',
            'is_active' => true,
            'is_primary' => true,
        ]);

        Contact::create([
            'company_id' => $company->id,
            'name' => 'Contato 1',
            'phone' => '5511911110001',
            'meta_number_id' => null,
        ]);

        $this->artisan('meta-numbers:backfill-contact-defaults')
            ->assertExitCode(0);

        $this->assertDatabaseHas('contacts', [
            'company_id' => $company->id,
            'phone' => '5511911110001',
            'meta_number_id' => $primary->id,
        ]);
    }
}

