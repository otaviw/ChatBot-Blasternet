import { useMemo, useState } from 'react';
import Layout from '@/components/layout/Layout/Layout.jsx';
import PageHeader from '@/components/ui/PageHeader/PageHeader.jsx';
import usePageData from '@/hooks/usePageData';
import useLogout from '@/hooks/useLogout';
import { useNotificationsContext } from '@/hooks/useNotificationsContext';
import { NOTIFICATION_MODULE, NOTIFICATION_REFERENCE_TYPE } from '@/constants/notifications';
import NotificationPreferencesPanel from './NotificationPreferencesPanel.jsx';

function resolveUiRole(userRole) {
  return String(userRole ?? '') === 'system_admin' ? 'admin' : 'company';
}

function buildNotificationTarget(notification, uiRole) {
  const referenceType = String(notification?.reference_type ?? '');
  const referenceId = Number.parseInt(String(notification?.reference_id ?? ''), 10);
  const module = String(notification?.module ?? '');

  if (referenceType === NOTIFICATION_REFERENCE_TYPE.CONVERSATION && referenceId > 0) {
    if (uiRole === 'company') {
      return `/minha-conta/conversas?conversationId=${referenceId}`;
    }

    return '/admin/conversas';
  }

  if (referenceType === NOTIFICATION_REFERENCE_TYPE.CHAT_CONVERSATION && referenceId > 0) {
    if (uiRole === 'company') {
      return `/minha-conta/chat-interno?conversationId=${referenceId}`;
    }

    return `/admin/chat-interno?conversationId=${referenceId}`;
  }

  if (referenceType === NOTIFICATION_REFERENCE_TYPE.SUPPORT_TICKET && referenceId > 0) {
    if (uiRole === 'admin') {
      return `/admin/suporte/solicitacoes/${referenceId}`;
    }

    return `/minha-conta/suporte/solicitacoes/${referenceId}`;
  }

  if (module === NOTIFICATION_MODULE.INBOX) {
    return uiRole === 'company' ? '/minha-conta/conversas' : '/admin/conversas';
  }

  if (module === NOTIFICATION_MODULE.INTERNAL_CHAT) {
    return uiRole === 'company' ? '/minha-conta/chat-interno' : '/admin/chat-interno';
  }

  if (module === NOTIFICATION_MODULE.SUPPORT) {
    return uiRole === 'admin' ? '/admin/suporte' : '/minha-conta/suporte/solicitacoes';
  }

  return null;
}

function moduleLabel(module) {
  const value = String(module ?? '').trim();

  if (value === NOTIFICATION_MODULE.INBOX) return 'Conversas';
  if (value === NOTIFICATION_MODULE.INTERNAL_CHAT) return 'Equipe interna';
  if (value === NOTIFICATION_MODULE.SUPPORT) return 'Chamados';
  if (value === NOTIFICATION_MODULE.GENERAL) return 'Geral';

  return value || 'Geral';
}

function formatDate(dateValue) {
  if (!dateValue) {
    return '-';
  }

  const timestamp = new Date(dateValue).getTime();
  if (!Number.isFinite(timestamp)) {
    return '-';
  }

  return new Date(timestamp).toLocaleString('pt-BR');
}

function NotificationsPage() {
  const { data, loading, error } = usePageData('/me');
  const { logout } = useLogout();
  const {
    notifications,
    totalUnread,
    loading: notificationsLoading,
    error: notificationsError,
    refresh,
    markAsRead,
    markAllRead,
    deleteMany,
  } = useNotificationsContext();
  const [busyById, setBusyById] = useState({});
  const [selectedIds, setSelectedIds] = useState([]);
  const [prefsOpen, setPrefsOpen] = useState(false);

  const uiRole = useMemo(() => resolveUiRole(data?.user?.role), [data?.user?.role]);

  const handleMarkAsRead = async (notificationId) => {
    const id = Number.parseInt(String(notificationId ?? ''), 10);
    if (!id) {
      return;
    }

    setBusyById((prev) => ({ ...prev, [id]: true }));
    try {
      await markAsRead(id);
    } finally {
      setBusyById((prev) => ({ ...prev, [id]: false }));
    }
  };

  const handleOpenTarget = async (notification) => {
    const id = Number.parseInt(String(notification?.id ?? ''), 10);
    if (!id) {
      return;
    }

    const targetHref = buildNotificationTarget(notification, uiRole);
    if (!targetHref) {
      return;
    }

    setBusyById((prev) => ({ ...prev, [id]: true }));
    try {
      if (!notification?.is_read) {
        await markAsRead(id);
      }
    } finally {
      setBusyById((prev) => ({ ...prev, [id]: false }));
    }

    window.location.href = targetHref;
  };

  const handleToggleSelected = (notificationId) => {
    const id = Number.parseInt(String(notificationId ?? ''), 10);
    if (!id) {
      return;
    }

    setSelectedIds((prev) =>
      prev.includes(id) ? prev.filter((value) => value !== id) : [...prev, id]
    );
  };

  const handleSelectAll = () => {
    if (!notifications.length) return;
    const allIds = notifications.map((n) => Number(n.id));
    const allSelected = selectedIds.length === allIds.length;
    setSelectedIds(allSelected ? [] : allIds);
  };

  const handleMarkAllRead = async () => {
    await markAllRead();
    setSelectedIds([]);
  };

  const handleDeleteSelected = async () => {
    if (!selectedIds.length) return;
    await deleteMany(selectedIds);
    setSelectedIds([]);
  };

  if (loading) {
    return (
      <Layout>
        <p className="text-sm text-[#64748b]">Carregando notificacoes...</p>
      </Layout>
    );
  }

  if (error || !data?.authenticated) {
    return (
      <Layout>
        <p className="text-sm text-red-600">Não foi possível carregar as notificações.</p>
      </Layout>
    );
  }

  return (
    <Layout role={uiRole} companyName={data?.user?.company_name ?? null} onLogout={logout}>
      <PageHeader
        title="Central de notificacoes"
        subtitle="Acompanhe pendencias por modulo e acesse rapidamente os itens relacionados."
      />

      <NotificationPreferencesPanel open={prefsOpen} onClose={() => setPrefsOpen(false)} />

      <section className="app-panel space-y-4">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div className="flex flex-wrap items-center gap-3">
            <div className="text-sm text-[#334155]">
              Não lidas: <strong>{totalUnread}</strong>
            </div>
            <button
              type="button"
              onClick={handleMarkAllRead}
              className="app-btn-secondary text-xs"
              disabled={notificationsLoading || !notifications.length}
            >
              Marcar todas como lidas
            </button>
            <button
              type="button"
              onClick={handleDeleteSelected}
              className="app-btn-danger text-xs disabled:opacity-60"
              disabled={!selectedIds.length}
            >
              Apagar selecionadas
            </button>
            {notifications.length > 0 && (
              <button
                type="button"
                onClick={handleSelectAll}
                className="text-xs text-[#2563eb] underline-offset-2 hover:underline"
              >
                {selectedIds.length === notifications.length ? 'Limpar seleção' : 'Selecionar todas'}
              </button>
            )}
          </div>
          <div className="flex flex-wrap items-center gap-2">
            <button
              type="button"
              onClick={() => void refresh()}
              className="app-btn-secondary text-xs"
              disabled={notificationsLoading}
            >
              {notificationsLoading ? 'Atualizando...' : 'Atualizar'}
            </button>
            <button
              type="button"
              onClick={() => setPrefsOpen(true)}
              className="app-btn-secondary text-xs flex items-center gap-1.5"
            >
              <svg width="13" height="13" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                <circle cx="10" cy="10" r="3" stroke="currentColor" strokeWidth="1.6"/>
                <path d="M10 2v2M10 16v2M2 10h2M16 10h2M4.22 4.22l1.42 1.42M14.36 14.36l1.42 1.42M4.22 15.78l1.42-1.42M14.36 5.64l1.42-1.42" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round"/>
              </svg>
              Configurar notificações
            </button>
          </div>
        </div>

        {notificationsError ? (
          <p className="text-sm text-red-600">{notificationsError}</p>
        ) : null}

        {!notifications.length ? (
          <p className="text-sm text-[#64748b]">Nenhuma notificação no momento.</p>
        ) : (
          <ul className="space-y-2">
            {notifications.map((notification) => {
              const targetHref = buildNotificationTarget(notification, uiRole);
              const isBusy = Boolean(busyById[notification.id]);
              const isSelected = selectedIds.includes(Number(notification.id));

              return (
                <li
                  key={notification.id}
                  className={`rounded-lg border p-3 ${
                    notification.is_read
                      ? 'border-[#e2e8f0] bg-[#f8fafc]'
                      : 'border-[#fecaca] bg-[#fff1f2]'
                  }`}
                >
                  <div className="flex flex-wrap items-start justify-between gap-2">
                    <div className="space-y-1">
                      <div className="flex items-center gap-2">
                        <input
                          type="checkbox"
                          checked={isSelected}
                          onChange={() => handleToggleSelected(notification.id)}
                          className="h-4 w-4"
                        />
                        <span className="rounded-full bg-[#e2e8f0] px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-[#334155]">
                          {moduleLabel(notification.module)}
                        </span>
                        {!notification.is_read ? (
                          <span className="rounded-full bg-[#dc2626] px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-white">
                            Nova
                          </span>
                        ) : null}
                      </div>
                      <p className="text-sm font-semibold text-[#0f172a]">{notification.title}</p>
                      <p className="text-sm text-[#475569]">{notification.text}</p>
                      <p className="text-xs text-[#64748b]">
                        {formatDate(notification.created_at)}
                        {notification.read_at ? ` | lida em ${formatDate(notification.read_at)}` : ''}
                      </p>
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                      {!notification.is_read ? (
                        <button
                          type="button"
                          className="app-btn-secondary text-xs"
                          onClick={() => void handleMarkAsRead(notification.id)}
                          disabled={isBusy}
                        >
                          {isBusy ? 'Salvando...' : 'Marcar como lida'}
                        </button>
                      ) : null}

                      {targetHref ? (
                        <button
                          type="button"
                          className="app-btn-primary text-xs"
                          onClick={() => void handleOpenTarget(notification)}
                          disabled={isBusy}
                        >
                          Abrir item
                        </button>
                      ) : null}
                    </div>
                  </div>
                </li>
              );
            })}
          </ul>
        )}
      </section>
    </Layout>
  );
}

export default NotificationsPage;
