<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\MetaNumberResolutionException;
use App\Models\CompanyMetaNumber;
use App\Models\Contact;
use App\Support\AuditActions;
use Illuminate\Support\Facades\DB;

class ContactSendNumberResolver
{
    public function __construct(
        private readonly MetaNumberObservabilityService $observability,
    ) {}

    public function resolveForContact(
        Contact $contact,
        bool $selfHeal = false,
        ?int $conversationId = null,
        ?int $campaignId = null
    ): CompanyMetaNumber
    {
        $beforeMetaNumberId = $contact->meta_number_id !== null ? (int) $contact->meta_number_id : null;
        $resolved = $this->resolveFromContactOrPrimary($contact);

        if ($selfHeal && $contact->meta_number_id !== (int) $resolved->id) {
            DB::transaction(function () use ($contact, $resolved): void {
                $before = $contact->meta_number_id !== null ? (int) $contact->meta_number_id : null;
                $contact->meta_number_id = (int) $resolved->id;
                $contact->save();

                AuditService::log(
                    AuditActions::CONTACT_META_NUMBER_CHANGED,
                    'contact',
                    $contact->id,
                    [
                        'company_id' => (int) $contact->company_id,
                        'entity_type' => 'contact',
                        'entity_id' => (int) $contact->id,
                        'before' => ['meta_number_id' => $before],
                    ],
                    [
                        'company_id' => (int) $contact->company_id,
                        'entity_type' => 'contact',
                        'entity_id' => (int) $contact->id,
                        'after' => ['meta_number_id' => (int) $resolved->id],
                        'reason' => 'resolver_fallback_self_heal',
                    ]
                );
            });
        }

        $this->observability->logSendResolution(
            (int) $contact->company_id,
            (int) $contact->id,
            $conversationId,
            $campaignId,
            (int) $resolved->id,
            $beforeMetaNumberId !== null && $beforeMetaNumberId !== (int) $resolved->id
        );

        return $resolved;
    }

    public function resolveForCompanyAndContactId(
        int $companyId,
        int $contactId,
        bool $selfHeal = false,
        ?int $conversationId = null,
        ?int $campaignId = null
    ): CompanyMetaNumber
    {
        $contact = Contact::query()
            ->where('company_id', $companyId)
            ->whereKey($contactId)
            ->firstOrFail();

        return $this->resolveForContact($contact, $selfHeal, $conversationId, $campaignId);
    }

    private function resolveFromContactOrPrimary(Contact $contact): CompanyMetaNumber
    {
        if ($contact->meta_number_id !== null) {
            $contactNumber = CompanyMetaNumber::query()
                ->where('company_id', (int) $contact->company_id)
                ->whereKey((int) $contact->meta_number_id)
                ->where('is_active', true)
                ->first();

            if ($contactNumber) {
                return $contactNumber;
            }
        }

        $primary = CompanyMetaNumber::query()
            ->where('company_id', (int) $contact->company_id)
            ->where('is_active', true)
            ->where('is_primary', true)
            ->first();

        if ($primary) {
            return $primary;
        }

        $firstActive = CompanyMetaNumber::query()
            ->where('company_id', (int) $contact->company_id)
            ->where('is_active', true)
            ->orderBy('id')
            ->first();

        if ($firstActive) {
            return $firstActive;
        }

        $this->observability->logInvalidContactNumber(
            (int) $contact->company_id,
            (int) $contact->id,
            null,
            null
        );
        $this->observability->alertCompanyWithoutActiveNumber((int) $contact->company_id);

        throw new MetaNumberResolutionException();
    }
}
