import { useCallback, useEffect, useMemo, useState } from 'react';
import realtimeClient from '@/services/realtimeClient';
import notificationService from '@/services/notificationService';
import { NOTIFICATION_MODULE, NOTIFICATION_REFERENCE_TYPE } from '@/constants/notifications';

function toIsoStringOrNull(value) {
  const normalized = String(value ?? '').trim();
  if (!normalized) {
    return null;
  }

  const timestamp = new Date(normalized).getTime();
  return Number.isFinite(timestamp) ? new Date(timestamp).toISOString() : null;
}

function normalizeNotification(input) {
  if (!input || typeof input !== 'object') {
    return null;
  }

  const id = Number.parseInt(String(input.id ?? ''), 10);
  const userId = Number.parseInt(String(input.user_id ?? ''), 10);
  if (!id || !userId) {
    return null;
  }

  return {
    id,
    user_id: userId,
    type: String(input.type ?? 'generic'),
    module: String(input.module ?? 'general'),
    title: String(input.title ?? ''),
    text: String(input.text ?? ''),
    is_read: Boolean(input.is_read),
    reference_type: input.reference_type ?? null,
    reference_id:
      input.reference_id === null || input.reference_id === undefined
        ? null
        : Number.parseInt(String(input.reference_id), 10) || null,
    reference_meta: input.reference_meta && typeof input.reference_meta === 'object' ? input.reference_meta : null,
    read_at: toIsoStringOrNull(input.read_at),
    created_at: toIsoStringOrNull(input.created_at),
    updated_at: toIsoStringOrNull(input.updated_at),
  };
}

function sortNotificationsByDate(items) {
  return [...items].sort((a, b) => {
    const aTime = new Date(a.created_at ?? a.updated_at ?? 0).getTime() || 0;
    const bTime = new Date(b.created_at ?? b.updated_at ?? 0).getTime() || 0;

    if (bTime !== aTime) {
      return bTime - aTime;
    }

    return Number(b.id) - Number(a.id);
  });
}

function mergeNotification(items, notification) {
  const index = items.findIndex((item) => Number(item.id) === Number(notification.id));
  if (index < 0) {
    return sortNotificationsByDate([notification, ...items]);
  }

  const next = [...items];
  next[index] = {
    ...next[index],
    ...notification,
  };

  return sortNotificationsByDate(next);
}

function normalizeUnreadByModule(input) {
  if (!input || typeof input !== 'object') {
    return {};
  }

  return Object.entries(input).reduce((acc, [module, total]) => {
    const normalizedModule = String(module ?? '').trim();
    if (!normalizedModule) {
      return acc;
    }

    const count = Number.parseInt(String(total ?? ''), 10);
    acc[normalizedModule] = count > 0 ? count : 0;
    return acc;
  }, {});
}

export default function useNotifications(options = {}) {
  const {
    enabled = true,
    autoLoad = true,
    limit = 50,
  } = options;

  const [notifications, setNotifications] = useState([]);
  const [unreadByModule, setUnreadByModule] = useState({});
  const [totalUnread, setTotalUnread] = useState(0);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const [loadedAt, setLoadedAt] = useState(null);

  const applyUnreadCounters = useCallback((byModulePayload, totalPayload) => {
    const normalizedByModule = normalizeUnreadByModule(byModulePayload);
    const normalizedTotal = Number.parseInt(String(totalPayload ?? ''), 10);
    const total = Number.isFinite(normalizedTotal)
      ? Math.max(0, normalizedTotal)
      : Object.values(normalizedByModule).reduce((sum, value) => sum + value, 0);

    setUnreadByModule(normalizedByModule);
    setTotalUnread(total);
  }, []);

  const loadNotifications = useCallback(async (listOptions = {}) => {
    if (!enabled) {
      return [];
    }

    setLoading(true);
    setError('');
    try {
      const response = await notificationService.list({
        limit,
        ...listOptions,
      });

      const normalized = (response.notifications ?? [])
        .map((item) => normalizeNotification(item))
        .filter(Boolean);

      setNotifications(sortNotificationsByDate(normalized));
      setLoadedAt(new Date().toISOString());

      return normalized;
    } catch (err) {
      setError(err?.response?.data?.message || 'Falha ao carregar notificacoes.');
      return [];
    } finally {
      setLoading(false);
    }
  }, [enabled, limit]);

  const loadUnreadCounts = useCallback(async () => {
    if (!enabled) {
      return { unread_by_module: {}, total_unread: 0 };
    }

    try {
      const response = await notificationService.unreadCounts();
      applyUnreadCounters(response.unread_by_module, response.total_unread);
      return response;
    } catch (err) {
      setError((prev) => prev || err?.response?.data?.message || 'Falha ao carregar contadores de notificacoes.');
      return { unread_by_module: {}, total_unread: 0 };
    }
  }, [applyUnreadCounters, enabled]);

  const refresh = useCallback(async () => {
    if (!enabled) {
      return;
    }

    await Promise.all([loadNotifications(), loadUnreadCounts()]);
  }, [enabled, loadNotifications, loadUnreadCounts]);

  const markAsRead = useCallback(async (notificationId) => {
    const id = Number.parseInt(String(notificationId ?? ''), 10);
    if (!id || !enabled) {
      return null;
    }

    try {
      const response = await notificationService.markAsRead(id);
      const normalized = normalizeNotification(response.notification);

      if (normalized) {
        setNotifications((prev) => mergeNotification(prev, normalized));
      }

      applyUnreadCounters(response.unread_by_module, response.total_unread);

      return response;
    } catch (err) {
      setError(err?.response?.data?.message || 'Falha ao marcar notificação como lida.');
      return null;
    }
  }, [applyUnreadCounters, enabled]);

  const markReadByReference = useCallback(async (module, referenceType, referenceId) => {
    const normalizedModule = String(module ?? '').trim();
    const normalizedReferenceType = String(referenceType ?? '').trim();
    const normalizedReferenceId = Number.parseInt(String(referenceId ?? ''), 10);

    if (!enabled || !normalizedModule || !normalizedReferenceType || !normalizedReferenceId) {
      return null;
    }

    try {
      const response = await notificationService.markReadByReference({
        module: normalizedModule,
        referenceType: normalizedReferenceType,
        referenceId: normalizedReferenceId,
      });

      if (response?.ok) {
        const nowIso = new Date().toISOString();

        setNotifications((prev) =>
          prev.map((item) => {
            if (
              item.module !== normalizedModule ||
              item.reference_type !== normalizedReferenceType ||
              Number(item.reference_id) !== normalizedReferenceId
            ) {
              return item;
            }

            if (item.is_read) {
              return item;
            }

            return {
              ...item,
              is_read: true,
              read_at: nowIso,
            };
          })
        );
      }

      applyUnreadCounters(response?.unread_by_module, response?.total_unread);

      return response;
    } catch (err) {
      setError(err?.response?.data?.message || 'Falha ao marcar notificacoes por referencia.');
      return null;
    }
  }, [applyUnreadCounters, enabled]);

  useEffect(() => {
    if (!enabled || !autoLoad) {
      return undefined;
    }

    void refresh();

    return undefined;
  }, [autoLoad, enabled, refresh]);

  useEffect(() => {
    if (!enabled) {
      return undefined;
    }

    const unsubscribe = realtimeClient.on('notification.created', (envelope) => {
      const payload = envelope?.payload ?? {};
      const normalized = normalizeNotification(payload.notification);
      if (!normalized) {
        return;
      }

      setNotifications((prev) => mergeNotification(prev, normalized));
      applyUnreadCounters(payload.unreadByModule, payload.totalUnread);
    });

    return () => {
      unsubscribe();
    };
  }, [applyUnreadCounters, enabled]);

  const unreadModules = useMemo(() => Object.keys(unreadByModule), [unreadByModule]);
  const unreadConversationIds = useMemo(() => {
    const ids = notifications
      .filter((item) =>
        !item.is_read &&
        item.module === NOTIFICATION_MODULE.INBOX &&
        item.reference_type === NOTIFICATION_REFERENCE_TYPE.CONVERSATION &&
        Number(item.reference_id) > 0
      )
      .map((item) => Number(item.reference_id));

    return [...new Set(ids)];
  }, [notifications]);

  return {
    notifications,
    unreadByModule,
    unreadModules,
    unreadConversationIds,
    totalUnread,
    loading,
    error,
    loadedAt,
    loadNotifications,
    loadUnreadCounts,
    refresh,
    markAsRead,
    markReadByReference,
    setNotifications,
  };
}
