<?php

declare(strict_types=1);


namespace App\Support;

use App\Support\Enums\UserRole;

class UserPermissions
{
    const PAGE_INBOX          = 'page_inbox';
    const PAGE_CONTACTS       = 'page_contacts';
    const PAGE_CAMPAIGNS      = 'page_campaigns';
    const PAGE_INTERNAL_CHAT  = 'page_internal_chat';
    const PAGE_APPOINTMENTS   = 'page_appointments';
    const PAGE_QUICK_REPLIES  = 'page_quick_replies';
    const PAGE_TAGS           = 'page_tags';
    const PAGE_AUDIT          = 'page_audit';
    const PAGE_SIMULATOR      = 'page_simulator';
    const PAGE_IXC_CLIENTS    = 'page_ixc_clients';
    const IXC_CLIENTS_VIEW    = 'ixc_clients_view';
    const IXC_INVOICES_VIEW   = 'ixc_invoices_view';
    const IXC_INVOICES_DOWNLOAD = 'ixc_invoices_download';
    const IXC_INVOICES_SEND_EMAIL = 'ixc_invoices_send_email';
    const IXC_INVOICES_SEND_SMS = 'ixc_invoices_send_sms';
    const IXC_INTEGRATION_MANAGE = 'ixc_integration_manage';

    const ACTION_MANAGE_CONTACTS       = 'action_manage_contacts';
    const ACTION_SEND_CAMPAIGNS        = 'action_send_campaigns';
    const ACTION_MANAGE_QUICK_REPLIES  = 'action_manage_quick_replies';
    const ACTION_MANAGE_APPOINTMENTS   = 'action_manage_appointments';
    const ACTION_MANAGE_TAGS           = 'action_manage_tags';

    /**
     * All assignable permission keys.
     *
     * @var list<string>
     */
    const ALL = [
        self::PAGE_INBOX,
        self::PAGE_CONTACTS,
        self::PAGE_CAMPAIGNS,
        self::PAGE_INTERNAL_CHAT,
        self::PAGE_APPOINTMENTS,
        self::PAGE_QUICK_REPLIES,
        self::PAGE_TAGS,
        self::PAGE_AUDIT,
        self::PAGE_SIMULATOR,
        self::PAGE_IXC_CLIENTS,
        self::IXC_CLIENTS_VIEW,
        self::IXC_INVOICES_VIEW,
        self::IXC_INVOICES_DOWNLOAD,
        self::IXC_INVOICES_SEND_EMAIL,
        self::IXC_INVOICES_SEND_SMS,
        self::IXC_INTEGRATION_MANAGE,
        self::ACTION_MANAGE_CONTACTS,
        self::ACTION_SEND_CAMPAIGNS,
        self::ACTION_MANAGE_QUICK_REPLIES,
        self::ACTION_MANAGE_APPOINTMENTS,
        self::ACTION_MANAGE_TAGS,
    ];

    /**
     * Permissions granted to agents by default (when none are explicitly set).
     * All pages and all actions are enabled out of the box.
     *
     * @var list<string>
     */
    const AGENT_DEFAULTS = [
        self::PAGE_INBOX,
        self::PAGE_CONTACTS,
        self::PAGE_CAMPAIGNS,
        self::PAGE_INTERNAL_CHAT,
        self::PAGE_APPOINTMENTS,
        self::PAGE_QUICK_REPLIES,
        self::PAGE_TAGS,
        self::PAGE_AUDIT,
        self::PAGE_SIMULATOR,
        self::ACTION_MANAGE_CONTACTS,
        self::ACTION_SEND_CAMPAIGNS,
        self::ACTION_MANAGE_QUICK_REPLIES,
        self::ACTION_MANAGE_APPOINTMENTS,
        self::ACTION_MANAGE_TAGS,
    ];

    /**
     * Human-readable labels for each permission key.
     *
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::PAGE_INBOX         => 'Conversas',
            self::PAGE_CONTACTS      => 'Contatos',
            self::PAGE_CAMPAIGNS     => 'Campanhas',
            self::PAGE_INTERNAL_CHAT => 'Chat interno (Equipe)',
            self::PAGE_APPOINTMENTS  => 'Agendamentos',
            self::PAGE_QUICK_REPLIES => 'Respostas rápidas',
            self::PAGE_TAGS          => 'Tags',
            self::PAGE_AUDIT         => 'Auditoria',
            self::PAGE_SIMULATOR     => 'Testar bot',
            self::PAGE_IXC_CLIENTS   => 'Clientes IXC',
            self::IXC_CLIENTS_VIEW => 'Ver clientes IXC',
            self::IXC_INVOICES_VIEW => 'Ver boletos IXC',
            self::IXC_INVOICES_DOWNLOAD => 'Baixar boletos IXC',
            self::IXC_INVOICES_SEND_EMAIL => 'Enviar boleto por e-mail',
            self::IXC_INVOICES_SEND_SMS => 'Enviar boleto por SMS',
            self::IXC_INTEGRATION_MANAGE => 'Gerenciar integracao IXC',

            self::ACTION_MANAGE_CONTACTS      => 'Criar / editar / excluir contatos',
            self::ACTION_SEND_CAMPAIGNS       => 'Disparar campanhas',
            self::ACTION_MANAGE_QUICK_REPLIES => 'Criar / editar / excluir respostas rápidas',
            self::ACTION_MANAGE_APPOINTMENTS  => 'Criar / editar / excluir agendamentos',
            self::ACTION_MANAGE_TAGS          => 'Criar / editar / excluir tags',
        ];
    }

    /**
     * Resolve the effective permissions for a user.
     * Admins always get every permission; agents use their explicit list or the defaults.
     *
     * @param  string       $role
     * @param  mixed        $storedPermissions  Raw value from the database (array|null)
     * @return list<string>
     */
    public static function resolve(string $role, mixed $storedPermissions): array
    {
        $normalizedRole = UserRole::normalize($role);

        if (in_array($normalizedRole, [UserRole::SYSTEM_ADMIN->value, UserRole::COMPANY_ADMIN->value], true)) {
            return self::ALL;
        }

        if ($storedPermissions === null) {
            return self::AGENT_DEFAULTS;
        }

        $stored = is_array($storedPermissions) ? $storedPermissions : [];

        return array_values(array_intersect($stored, self::ALL));
    }

    /**
     * Sanitise an incoming permissions payload from a request.
     * Returns null when the list equals the full defaults (saves storage).
     *
     * @param  mixed $value
     * @return list<string>|null
     */
    public static function sanitize(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $cleaned = array_values(array_intersect($value, self::ALL));

        sort($cleaned);
        $defaults = self::AGENT_DEFAULTS;
        sort($defaults);

        if ($cleaned === $defaults) {
            return null;
        }

        return $cleaned;
    }
}
