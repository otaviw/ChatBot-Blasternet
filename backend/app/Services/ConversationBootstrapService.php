<?php

declare(strict_types=1);


namespace App\Services;

use App\Models\Company;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\User;
use App\Services\AuditService;
use App\Support\ConversationAssignedType;
use App\Support\ConversationHandlingMode;
use App\Support\ConversationStatus;
use App\Support\PhoneNumberNormalizer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class ConversationBootstrapService
{
    private const INACTIVITY_CHECK_INTERVAL_SECONDS = 300;
    private const DEFAULT_ATTENDANT_UNAVAILABLE_FALLBACK_MESSAGE = 'Seu atendente padrão não está disponível no momento.';

    /** @var array{mode:string,should_send_fallback:bool,fallback_message:?string,attendant_id:?int,attendant_name:?string} */
    private array $lastRoutingDecision = [
        'mode' => 'bot',
        'should_send_fallback' => false,
        'fallback_message' => null,
        'attendant_id' => null,
        'attendant_name' => null,
    ];

    public function __construct(
        private ConversationInactivityService $inactivityService
    ) {}

    public function bootstrap(
        ?Company $company,
        string $normalizedPhone,
        ?string $normalizedContactName
    ): Conversation {
        $this->resetRoutingDecision();

        if ($company?->id) {
            $this->maybeCloseInactiveConversations((int) $company->id);
        }

        $companyId = (int) ($company?->id ?? 0);
        $variants = PhoneNumberNormalizer::variantsForLookup($normalizedPhone);

        $conversation = Conversation::query()
            ->where('company_id', $companyId)
            ->whereIn('customer_phone', $variants !== [] ? $variants : [$normalizedPhone])
            ->orderByDesc('id')
            ->first();

        $isNewConversation = false;
        $wasClosed = false;

        if (! $conversation) {
            $conversation = Conversation::create([
                'company_id' => $companyId,
                'customer_phone' => $normalizedPhone,
                'status' => ConversationStatus::OPEN,
                'assigned_type' => ConversationAssignedType::UNASSIGNED,
                'handling_mode' => ConversationHandlingMode::BOT,
                'customer_name' => $normalizedContactName,
            ]);
            $isNewConversation = true;
        }

        if ($normalizedContactName !== null && $conversation->customer_name !== $normalizedContactName) {
            $conversation->customer_name = $normalizedContactName;
        }

        if ($conversation->customer_phone !== $normalizedPhone) {
            $conversation->customer_phone = $normalizedPhone;
        }

        if ($conversation->status === ConversationStatus::CLOSED) {
            $wasClosed = true;
            $this->reopenClosedConversation($conversation);
        }

        $conversation->last_user_message_at = now();
        $conversation->save();

        if ($companyId > 0) {
            $contact = $this->upsertContact($companyId, $normalizedPhone, $normalizedContactName);
            $this->applyDefaultAttendantRouting(
                $conversation,
                $contact,
                $isNewConversation || $wasClosed,
                $companyId
            );
        }

        return $conversation;
    }

    /**
     * @return array{mode:string,should_send_fallback:bool,fallback_message:?string,attendant_id:?int,attendant_name:?string}
     */
    public function lastRoutingDecision(): array
    {
        return $this->lastRoutingDecision;
    }

    private function maybeCloseInactiveConversations(int $companyId): void
    {
        if ($companyId <= 0) {
            return;
        }

        $key = "conversation:inactivity:close-check:{$companyId}";

        try {
            $shouldRun = Cache::add($key, 1, self::INACTIVITY_CHECK_INTERVAL_SECONDS);
        } catch (Throwable) {
            $shouldRun = true;
        }

        if (! $shouldRun) {
            return;
        }

        $this->inactivityService->closeInactiveConversations($companyId);
    }

    private function upsertContact(int $companyId, string $phone, ?string $name): Contact
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

        return $contact->fresh(['defaultAttendant:id,name,company_id,is_active']) ?? $contact;
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

    private function resetRoutingDecision(): void
    {
        $this->lastRoutingDecision = [
            'mode' => 'bot',
            'should_send_fallback' => false,
            'fallback_message' => null,
            'attendant_id' => null,
            'attendant_name' => null,
        ];
    }

    private function applyDefaultAttendantRouting(
        Conversation $conversation,
        Contact $contact,
        bool $isNewEntry,
        int $companyId
    ): void {
        if (! $isNewEntry) {
            return;
        }

        if (! (bool) ($contact->skip_bot_to_default_attendant ?? false)) {
            return;
        }

        $attendantId = (int) ($contact->default_attendant_user_id ?? 0);
        if ($attendantId <= 0) {
            $this->markUnavailableFallbackDecision($conversation, $contact, null, 'missing_default_attendant');
            return;
        }

        $attendant = $this->resolveValidDefaultAttendant($attendantId, $companyId);
        if (! $attendant) {
            $this->markUnavailableFallbackDecision($conversation, $contact, $attendantId, 'inactive_or_cross_company');
            return;
        }

        $conversation->handling_mode = ConversationHandlingMode::HUMAN;
        $conversation->assigned_type = ConversationAssignedType::USER;
        $conversation->assigned_id = (int) $attendant->id;
        $conversation->assigned_user_id = (int) $attendant->id;
        $conversation->current_area_id = null;
        $conversation->assigned_area = null;
        $conversation->assumed_at = now();
        $conversation->status = ConversationStatus::IN_PROGRESS;
        $conversation->clearBotState();
        $conversation->save();

        $this->lastRoutingDecision = [
            'mode' => 'human_default_attendant',
            'should_send_fallback' => false,
            'fallback_message' => null,
            'attendant_id' => (int) $attendant->id,
            'attendant_name' => (string) ($attendant->name ?? ''),
        ];

        Log::info('default_attendant_routing_applied', [
            'company_id' => $companyId,
            'conversation_id' => (int) $conversation->id,
            'contact_id' => (int) $contact->id,
            'default_attendant_user_id' => (int) $attendant->id,
        ]);

        AuditService::log(
            'company.conversation.default_attendant_auto_routed',
            'conversation',
            (int) $conversation->id,
            null,
            [
                'company_id' => (int) $conversation->company_id,
                'conversation_id' => (int) $conversation->id,
                'contact_id' => (int) $contact->id,
                'default_attendant_user_id' => (int) $attendant->id,
                'default_attendant_name' => (string) ($attendant->name ?? ''),
                'routing_mode' => 'human_default_attendant',
            ]
        );
    }

    private function resolveValidDefaultAttendant(int $attendantId, int $companyId): ?User
    {
        return User::query()
            ->whereKey($attendantId)
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->first();
    }

    private function markUnavailableFallbackDecision(
        Conversation $conversation,
        Contact $contact,
        ?int $attendantId,
        string $reason
    ): void {
        $this->lastRoutingDecision = [
            'mode' => 'bot_fallback_unavailable',
            'should_send_fallback' => true,
            'fallback_message' => self::DEFAULT_ATTENDANT_UNAVAILABLE_FALLBACK_MESSAGE,
            'attendant_id' => $attendantId,
            'attendant_name' => null,
        ];

        Log::info('default_attendant_unavailable_fallback', [
            'company_id' => (int) $conversation->company_id,
            'conversation_id' => (int) $conversation->id,
            'contact_id' => (int) $contact->id,
            'default_attendant_user_id' => $attendantId,
            'reason' => $reason,
        ]);

        AuditService::log(
            'company.conversation.default_attendant_fallback',
            'conversation',
            (int) $conversation->id,
            null,
            [
                'company_id' => (int) $conversation->company_id,
                'conversation_id' => (int) $conversation->id,
                'contact_id' => (int) $contact->id,
                'default_attendant_user_id' => $attendantId,
                'fallback_reason' => $reason,
                'routing_mode' => 'bot_fallback_unavailable',
            ]
        );
    }
}
