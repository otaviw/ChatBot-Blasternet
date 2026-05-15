<?php

declare(strict_types=1);

namespace App\Support;

final class AuditActions
{
    public const COMPANY_META_NUMBER_CREATED = 'company_meta_number.created';
    public const COMPANY_META_NUMBER_UPDATED = 'company_meta_number.updated';
    public const COMPANY_META_NUMBER_ACTIVATED = 'company_meta_number.activated';
    public const COMPANY_META_NUMBER_DEACTIVATED = 'company_meta_number.deactivated';
    public const COMPANY_META_NUMBER_PRIMARY_CHANGED = 'company_meta_number.primary_changed';
    public const COMPANY_META_NUMBER_REMOVED = 'company_meta_number.removed';
    public const CONTACT_META_NUMBER_CHANGED = 'contact.meta_number.changed';
    public const CONTACT_META_NUMBER_BULK_REASSIGNED = 'contact.meta_number.bulk_reassigned';
    public const CONVERSATION_SEND_NUMBER_SELECTED = 'conversation.send_number.selected';
    public const CAMPAIGN_SEND_NUMBER_RESOLVED = 'campaign.send_number.resolved';

    private function __construct() {}
}

