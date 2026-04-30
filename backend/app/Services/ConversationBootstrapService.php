<?php

declare(strict_types=1);


namespace App\Services;

use App\Models\Company;
use App\Models\Contact;
use App\Models\Conversation;
use App\Support\ConversationAssignedType;
use App\Support\ConversationHandlingMode;
use App\Support\ConversationStatus;
use App\Support\PhoneNumberNormalizer;

class ConversationBootstrapService
{
    public function __construct(
        private ConversationInactivityService $inactivityService
    ) {}

    public function bootstrap(
        ?Company $company,
        string $normalizedPhone,
        ?string $normalizedContactName
    ): Conversation {
        if ($company?->id) {
            $this->inactivityService->closeInactiveConversations((int) $company->id);
        }

        $companyId = (int) ($company?->id ?? 0);
        $variants = PhoneNumberNormalizer::variantsForLookup($normalizedPhone);

        $conversation = Conversation::query()
            ->where('company_id', $companyId)
            ->whereIn('customer_phone', $variants !== [] ? $variants : [$normalizedPhone])
            ->orderByDesc('id')
            ->first();

        if (! $conversation) {
            $conversation = Conversation::create([
                'company_id' => $companyId,
                'customer_phone' => $normalizedPhone,
                'status' => ConversationStatus::OPEN,
                'assigned_type' => ConversationAssignedType::UNASSIGNED,
                'handling_mode' => ConversationHandlingMode::BOT,
                'customer_name' => $normalizedContactName,
            ]);
        }

        if ($normalizedContactName !== null && $conversation->customer_name !== $normalizedContactName) {
            $conversation->customer_name = $normalizedContactName;
        }

        if ($conversation->customer_phone !== $normalizedPhone) {
            $conversation->customer_phone = $normalizedPhone;
        }

        if ($conversation->status === ConversationStatus::CLOSED) {
            $this->reopenClosedConversation($conversation);
        }

        $conversation->last_user_message_at = now();
        $conversation->save();

        if ($companyId > 0) {
            $this->upsertContact($companyId, $normalizedPhone, $normalizedContactName);
        }

        return $conversation;
    }

    private function upsertContact(int $companyId, string $phone, ?string $name): void
    {
        $contact = Contact::firstOrCreate(
            ['company_id' => $companyId, 'phone' => $phone],
            ['name' => $name ?? $phone]
        );

        $contact->last_interaction_at = now();

        if ($name !== null && $contact->name !== $name) {
            $contact->name = $name;
        }

        $contact->save();
    }

    private function reopenClosedConversation(Conversation $conversation): void
    {
        $conversation->status = ConversationStatus::OPEN;
        $conversation->closed_at = null;
        $conversation->handling_mode = ConversationHandlingMode::BOT;
        $conversation->assigned_type = ConversationAssignedType::BOT;
        $conversation->assigned_id = null;
        $conversation->current_area_id = null;
        $conversation->assigned_user_id = null;
        $conversation->assigned_area = null;
        $conversation->assumed_at = null;
        $conversation->clearBotState();
    }
}
