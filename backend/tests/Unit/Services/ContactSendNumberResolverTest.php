<?php

namespace Tests\Unit\Services;

use App\Exceptions\MetaNumberResolutionException;
use App\Models\Company;
use App\Models\CompanyMetaNumber;
use App\Models\Contact;
use App\Services\ContactSendNumberResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContactSendNumberResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolver_uses_active_contact_number(): void
    {
        $company = Company::create(['name' => 'Empresa Resolver A']);

        $numberA = CompanyMetaNumber::create([
            'company_id' => $company->id,
            'phone_number' => '5511990000001',
            'is_active' => true,
            'is_primary' => false,
        ]);

        CompanyMetaNumber::create([
            'company_id' => $company->id,
            'phone_number' => '5511990000002',
            'is_active' => true,
            'is_primary' => true,
        ]);

        $contact = Contact::create([
            'company_id' => $company->id,
            'name' => 'Contato A',
            'phone' => '5511888881111',
            'meta_number_id' => $numberA->id,
        ]);

        $resolved = app(ContactSendNumberResolver::class)->resolveForContact($contact, false);

        $this->assertSame((int) $numberA->id, (int) $resolved->id);
    }

    public function test_resolver_fallbacks_to_primary_when_contact_number_is_missing_or_inactive(): void
    {
        $company = Company::create(['name' => 'Empresa Resolver B']);

        $inactive = CompanyMetaNumber::create([
            'company_id' => $company->id,
            'phone_number' => '5511990000011',
            'is_active' => false,
            'is_primary' => false,
        ]);

        $primary = CompanyMetaNumber::create([
            'company_id' => $company->id,
            'phone_number' => '5511990000012',
            'is_active' => true,
            'is_primary' => true,
        ]);

        $contact = Contact::create([
            'company_id' => $company->id,
            'name' => 'Contato B',
            'phone' => '5511777772222',
            'meta_number_id' => $inactive->id,
        ]);

        $resolved = app(ContactSendNumberResolver::class)->resolveForContact($contact, false);

        $this->assertSame((int) $primary->id, (int) $resolved->id);
    }

    public function test_resolver_throws_when_company_has_no_active_number(): void
    {
        $company = Company::create(['name' => 'Empresa Resolver C']);

        Contact::create([
            'company_id' => $company->id,
            'name' => 'Contato C',
            'phone' => '5511666663333',
            'meta_number_id' => null,
        ]);

        $this->expectException(MetaNumberResolutionException::class);
        $this->expectExceptionMessage('NO_ACTIVE_META_NUMBER_FOR_COMPANY');

        $contact = Contact::query()->where('company_id', $company->id)->firstOrFail();
        app(ContactSendNumberResolver::class)->resolveForContact($contact, false);
    }
}

