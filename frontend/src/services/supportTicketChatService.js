import { get, post } from '@/services/apiClient';

function normalizeTicketId(ticketId) {
  const normalized = Number.parseInt(String(ticketId ?? ''), 10);
  if (!Number.isFinite(normalized) || normalized <= 0) {
    return null;
  }

  return normalized;
}

function normalizeMessage(item) {
  if (!item || typeof item !== 'object') {
    return null;
  }

  const id = Number.parseInt(String(item.id ?? ''), 10);
  if (!id || id <= 0) {
    return null;
  }

  const attachments = Array.isArray(item.attachments)
    ? item.attachments
        .map((attachment) => {
          const attachmentId = Number.parseInt(String(attachment?.id ?? ''), 10);
          if (!attachmentId || attachmentId <= 0) {
            return null;
          }

          return {
            id: attachmentId,
            url: String(attachment?.url ?? ''),
            media_url: String(attachment?.media_url ?? attachment?.url ?? ''),
            mime_type: String(attachment?.mime_type ?? ''),
            size_bytes:
              attachment?.size_bytes === null || attachment?.size_bytes === undefined
                ? null
                : Number.parseInt(String(attachment.size_bytes), 10) || null,
          };
        })
        .filter(Boolean)
    : [];

  return {
    id,
    support_ticket_id: Number.parseInt(String(item.support_ticket_id ?? ''), 10) || null,
    sender_user_id: Number.parseInt(String(item.sender_user_id ?? ''), 10) || null,
    sender_name: String(item.sender_name ?? 'Usuário'),
    sender_is_admin: Boolean(item.sender_is_admin),
    type: String(item.type ?? 'text'),
    content: String(item.content ?? ''),
    attachments,
    created_at: item.created_at ? String(item.created_at) : null,
    updated_at: item.updated_at ? String(item.updated_at) : null,
  };
}

function normalizeMessageList(payload) {
  if (!Array.isArray(payload)) {
    return [];
  }

  return payload
    .map((item) => normalizeMessage(item))
    .filter(Boolean)
    .sort((a, b) => {
      const aId = Number(a?.id ?? 0);
      const bId = Number(b?.id ?? 0);
      return aId - bId;
    });
}

function buildMessageFormData({ message, images }) {
  const formData = new FormData();

  const text = String(message ?? '').trim();
  if (text !== '') {
    formData.append('message', text);
  }

  (images ?? []).forEach((file) => {
    if (file) {
      formData.append('images[]', file);
    }
  });

  return formData;
}

async function list(endpoint) {
  const response = await get(endpoint);
  const data = response.data ?? {};

  return {
    ok: Boolean(data.ok),
    messages: normalizeMessageList(data.messages),
  };
}

async function send(endpoint, payload) {
  const formData = buildMessageFormData(payload);
  const response = await post(endpoint, formData, {
    headers: {
      'Content-Type': 'multipart/form-data',
    },
  });
  const data = response.data ?? {};

  return {
    ok: Boolean(data.ok),
    message: normalizeMessage(data.message),
  };
}

const supportTicketChatService = {
  async listForCompany(ticketId) {
    const normalized = normalizeTicketId(ticketId);
    if (!normalized) {
      return { ok: false, messages: [] };
    }

    return list(`/suporte/minhas-solicitacoes/${normalized}/chat`);
  },

  async sendForCompany(ticketId, payload) {
    const normalized = normalizeTicketId(ticketId);
    if (!normalized) {
      return { ok: false, message: null };
    }

    return send(`/suporte/minhas-solicitacoes/${normalized}/chat`, payload);
  },

  async listForAdmin(ticketId) {
    const normalized = normalizeTicketId(ticketId);
    if (!normalized) {
      return { ok: false, messages: [] };
    }

    return list(`/admin/suporte/solicitacoes/${normalized}/chat`);
  },

  async sendForAdmin(ticketId, payload) {
    const normalized = normalizeTicketId(ticketId);
    if (!normalized) {
      return { ok: false, message: null };
    }

    return send(`/admin/suporte/solicitacoes/${normalized}/chat`, payload);
  },
};

export default supportTicketChatService;
