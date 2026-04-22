import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { REALTIME_EVENTS } from '@/constants/realtimeEvents';
import realtimeClient from '@/services/realtimeClient';
import notificationService from '@/services/notificationService';
import browserNotificationService from '@/services/browserNotificationService';
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
  if (!id) {
    return null;
  }

  const userId = Number.parseInt(String(input.user_id ?? ''), 10);

  return {
    id,
    user_id: userId > 0 ? userId : null,
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

function parseNotificationId(value) {
  const id = Number.parseInt(String(value ?? ''), 10);
  return id > 0 ? id : 0;
}

function shouldHideNotification(notification, clearedUntilId) {
  if (clearedUntilId <= 0) {
    return false;
  }

  return parseNotificationId(notification?.id) <= clearedUntilId;
}

function filterNotificationsByClearState(items, clearedUntilId) {
  return (items ?? []).filter((item) => !shouldHideNotification(item, clearedUntilId));
}

function buildUnreadCountersFromNotifications(items) {
  return (items ?? []).reduce(
    (acc, item) => {
      if (item?.is_read) {
        return acc;
      }

      const module = String(item?.module ?? NOTIFICATION_MODULE.GENERAL).trim() || NOTIFICATION_MODULE.GENERAL;
      acc.unread_by_module[module] = Number(acc.unread_by_module[module] ?? 0) + 1;
      acc.total_unread += 1;
      return acc;
    },
    { unread_by_module: {}, total_unread: 0 }
  );
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
  const [clearedUntilId, setClearedUntilId] = useState(0);

  const activeConversationIdRef = useRef(0);
  const clearedUntilIdRef = useRef(0);
  const hasPrimedDesktopRef = useRef(false);
  const desktopNotifiedIdsRef = useRef(new Set());

  const rememberDesktopNotified = useCallback((notificationId) => {
    const id = parseNotificationId(notificationId);
    if (!id) {
      return false;
    }

    if (desktopNotifiedIdsRef.current.has(id)) {
      return false;
    }

    desktopNotifiedIdsRef.current.add(id);

    if (desktopNotifiedIdsRef.current.size > 500) {
      const values = [...desktopNotifiedIdsRef.current];
      desktopNotifiedIdsRef.current = new Set(values.slice(values.length - 300));
    }

    return true;
  }, []);

  const setActiveConversationId = useCallback((id) => {
    activeConversationIdRef.current = Number(id) > 0 ? Number(id) : 0;
  }, []);

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

    const { silent = false, ...queryOptions } = listOptions;

    if (!silent) {
      setLoading(true);
      setError('');
    }

    try {
      const response = await notificationService.list({
        limit,
        ...queryOptions,
      });

      const normalized = (response.notifications ?? [])
        .map((item) => normalizeNotification(item))
        .filter(Boolean);

      const sorted = sortNotificationsByDate(normalized);
      const visible = filterNotificationsByClearState(sorted, clearedUntilId);

      setNotifications(visible);
      setLoadedAt(new Date().toISOString());

      if (!hasPrimedDesktopRef.current) {
        for (const item of visible) {
          if (!item?.is_read) {
            rememberDesktopNotified(item.id);
          }
        }
        hasPrimedDesktopRef.current = true;
      } else {
        for (const item of visible) {
          if (!item?.is_read && rememberDesktopNotified(item.id)) {
            void browserNotificationService.notifyFromAppNotification(item);
          }
        }
      }

      return visible;
    } catch (err) {
      if (!silent) {
        setError(err?.response?.data?.message || 'Falha ao carregar notificacoes.');
      }
      return [];
    } finally {
      if (!silent) {
        setLoading(false);
      }
    }
  }, [clearedUntilId, enabled, limit, rememberDesktopNotified]);

  const loadUnreadCounts = useCallback(async () => {
    if (!enabled || clearedUntilId > 0) {
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
  }, [applyUnreadCounters, clearedUntilId, enabled]);

  const refresh = useCallback(async (options = {}) => {
    if (!enabled) {
      return;
    }

    const { silent = false } = options;

    if (clearedUntilId > 0) {
      await loadNotifications({ silent });
      return;
    }

    await Promise.all([loadNotifications({ silent }), loadUnreadCounts()]);
  }, [clearedUntilId, enabled, loadNotifications, loadUnreadCounts]);

  const markAsRead = useCallback(async (notificationId) => {
    const id = Number.parseInt(String(notificationId ?? ''), 10);
    if (!id || !enabled) {
      return null;
    }

    try {
      const response = await notificationService.markAsRead(id);
      const normalized = normalizeNotification(response.notification);

      if (normalized && !shouldHideNotification(normalized, clearedUntilId)) {
        setNotifications((prev) => mergeNotification(prev, normalized));
      }

      if (clearedUntilId <= 0) {
        applyUnreadCounters(response.unread_by_module, response.total_unread);
      }

      return response;
    } catch (err) {
      setError(err?.response?.data?.message || 'Falha ao marcar notificacao como lida.');
      return null;
    }
  }, [applyUnreadCounters, clearedUntilId, enabled]);

  const markAllRead = useCallback(async () => {
    if (!enabled) {
      return null;
    }

    try {
      const response = await notificationService.markAllRead();
      const nowIso = new Date().toISOString();

      setNotifications((prev) =>
        prev.map((item) =>
          item.is_read
            ? item
            : {
                ...item,
                is_read: true,
                read_at: item.read_at ?? nowIso,
              }
        )
      );

      if (clearedUntilId <= 0) {
        applyUnreadCounters(response.unread_by_module, response.total_unread);
      }

      return response;
    } catch (err) {
      setError(err?.response?.data?.message || 'Falha ao marcar todas as notificacoes como lidas.');
      return null;
    }
  }, [applyUnreadCounters, clearedUntilId, enabled]);

  const deleteMany = useCallback(
    async (ids) => {
      if (!enabled) {
        return null;
      }

      const normalizedIds = [
        ...new Set(
          (ids ?? [])
            .map((value) => Number.parseInt(String(value ?? ''), 10))
            .filter((id) => id > 0)
        ),
      ];

      if (!normalizedIds.length) {
        return null;
      }

      try {
        const response = await notificationService.deleteMany(normalizedIds);
        const idSet = new Set(normalizedIds);

        setNotifications((prev) => prev.filter((item) => !idSet.has(Number(item.id))));
        if (clearedUntilId <= 0) {
          applyUnreadCounters(response.unread_by_module, response.total_unread);
        }

        return response;
      } catch (err) {
        setError(err?.response?.data?.message || 'Falha ao apagar notificacoes.');
        return null;
      }
    },
    [applyUnreadCounters, clearedUntilId, enabled]
  );

  const clearAllLocally = useCallback(() => {
    if (!enabled) {
      return;
    }

    const maxNotificationId = notifications.reduce((maxId, item) => {
      const notificationId = parseNotificationId(item?.id);
      return notificationId > maxId ? notificationId : maxId;
    }, clearedUntilId);

    if (maxNotificationId > 0) {
      setClearedUntilId(maxNotificationId);
    }

    setNotifications([]);
    setUnreadByModule({});
    setTotalUnread(0);
  }, [clearedUntilId, enabled, notifications]);

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

      if (clearedUntilId <= 0) {
        applyUnreadCounters(response?.unread_by_module, response?.total_unread);
      }

      return response;
    } catch (err) {
      setError(err?.response?.data?.message || 'Falha ao marcar notificacoes por referencia.');
      return null;
    }
  }, [applyUnreadCounters, clearedUntilId, enabled]);

  useEffect(() => {
    clearedUntilIdRef.current = clearedUntilId;
  }, [clearedUntilId]);

  useEffect(() => {
    if (!enabled) {
      setClearedUntilId(0);
      hasPrimedDesktopRef.current = false;
      desktopNotifiedIdsRef.current = new Set();
      return;
    }

    void browserNotificationService.requestPermission();
  }, [enabled]);

  useEffect(() => {
    if (!enabled) {
      return undefined;
    }

    const intervalId = setInterval(() => {
      void realtimeClient.ensureConnected().catch(() => {});
    }, 25000);

    return () => clearInterval(intervalId);
  }, [enabled]);

  useEffect(() => {
    if (!enabled) {
      return undefined;
    }

    const intervalId = setInterval(() => {
      void refresh({ silent: true });
    }, 30000);

    return () => clearInterval(intervalId);
  }, [enabled, refresh]);

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

    const unsubscribe = realtimeClient.on(REALTIME_EVENTS.NOTIFICATION_CREATED, (envelope) => {
      const payload = envelope?.payload ?? {};
      const normalized = normalizeNotification(payload.notification);
      if (!normalized) {
        return;
      }

      const currentClearedUntilId = clearedUntilIdRef.current;
      if (shouldHideNotification(normalized, currentClearedUntilId)) {
        return;
      }

      const isConversationNotification =
        normalized.module === NOTIFICATION_MODULE.INBOX &&
        normalized.reference_type === NOTIFICATION_REFERENCE_TYPE.CONVERSATION &&
        Number(normalized.reference_id) > 0;

      const isActiveConversation =
        isConversationNotification &&
        Number(normalized.reference_id) === activeConversationIdRef.current;

      if (isActiveConversation) {
        void notificationService.markReadByReference({
          module: normalized.module,
          referenceType: normalized.reference_type,
          referenceId: Number(normalized.reference_id),
        }).catch(() => {});
        return;
      }

      setNotifications((prev) => mergeNotification(prev, normalized));

      if (currentClearedUntilId <= 0) {
        applyUnreadCounters(payload.unreadByModule, payload.totalUnread);
      }

      if (rememberDesktopNotified(normalized.id)) {
        void browserNotificationService.notifyFromAppNotification(normalized);
      }
    });

    return () => {
      unsubscribe();
    };
  }, [applyUnreadCounters, enabled, rememberDesktopNotified]);

  useEffect(() => {
    if (clearedUntilId <= 0) {
      return;
    }

    const localCounters = buildUnreadCountersFromNotifications(notifications);
    setUnreadByModule(localCounters.unread_by_module);
    setTotalUnread(localCounters.total_unread);
  }, [clearedUntilId, notifications]);

  const unreadModules = useMemo(() => Object.keys(unreadByModule), [unreadByModule]);

  const unreadConversationIds = useMemo(() => {
    const ids = notifications
      .filter(
        (item) =>
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
    markAllRead,
    deleteMany,
    clearAllLocally,
    setNotifications,
    setActiveConversationId,
  };
}
