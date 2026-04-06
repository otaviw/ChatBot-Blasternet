import api from '@/services/api';

function buildListParams({ limit = 50, module = '', unread = null } = {}) {
  const params = {};

  if (Number.isFinite(limit)) {
    params.limit = Math.max(1, Math.min(200, Number(limit)));
  }

  const normalizedModule = String(module ?? '').trim();
  if (normalizedModule) {
    params.module = normalizedModule;
  }

  if (typeof unread === 'boolean') {
    params.unread = unread;
  }

  return params;
}

const notificationService = {
  async list(options = {}) {
    const response = await api.get('/notifications', { params: buildListParams(options) });
    return response.data ?? { ok: false, notifications: [] };
  },

  async markAsRead(notificationId) {
    const response = await api.post(`/notifications/${notificationId}/read`);
    return response.data ?? { ok: false };
  },

  async markAllRead() {
    const response = await api.post('/notifications/read-all');
    return response.data ?? { ok: false };
  },

  async deleteMany(ids) {
    const response = await api.delete('/notifications/bulk', {
      data: { ids },
    });
    return response.data ?? { ok: false };
  },

  async markReadByReference({ module, referenceType, referenceId }) {
    const response = await api.post('/notifications/read-by-reference', {
      module,
      reference_type: referenceType,
      reference_id: referenceId,
    });

    return response.data ?? { ok: false, marked_count: 0, unread_by_module: {}, total_unread: 0 };
  },

  async unreadCounts() {
    const response = await api.get('/notifications/unread-counts');
    return response.data ?? { ok: false, unread_by_module: {}, total_unread: 0 };
  },

  async getPreferences() {
    const response = await api.get('/notifications/preferences');
    return response.data ?? { ok: false, preferences: {}, all_types: [] };
  },

  async updatePreferences(preferences) {
    const response = await api.put('/notifications/preferences', { preferences });
    return response.data ?? { ok: false, preferences: {} };
  },
};

export default notificationService;
