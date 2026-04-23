import { get, post } from '@/services/apiClient';

const toPositiveInt = (...values) => {
  for (const value of values) {
    const parsed = Number.parseInt(String(value ?? ''), 10);
    if (parsed > 0) {
      return parsed;
    }
  }

  return null;
};

const toNullableInt = (...values) => {
  for (const value of values) {
    if (value === null || value === undefined || value === '') {
      continue;
    }

    const parsed = Number.parseInt(String(value), 10);
    if (Number.isFinite(parsed)) {
      return parsed;
    }
  }

  return null;
};

const toTimestamp = (value) => {
  if (!value) {
    return 0;
  }

  const timestamp = new Date(value).getTime();
  return Number.isFinite(timestamp) ? timestamp : 0;
};

const pickObject = (...values) => {
  for (const value of values) {
    if (value && typeof value === 'object' && !Array.isArray(value)) {
      return value;
    }
  }

  return null;
};

const pickArray = (...values) => {
  for (const value of values) {
    if (Array.isArray(value)) {
      return value;
    }
  }

  return [];
};

const normalizePagination = (raw) => {
  const pagination = pickObject(raw);
  if (!pagination) {
    return null;
  }

  return {
    current_page: toPositiveInt(pagination.current_page) ?? 1,
    last_page: toPositiveInt(pagination.last_page) ?? 1,
    per_page: toPositiveInt(pagination.per_page) ?? 1,
    total: toNullableInt(pagination.total) ?? 0,
  };
};

export const normalizeInternalAiMessage = (raw) => {
  if (!raw || typeof raw !== 'object') {
    return null;
  }

  const id = toPositiveInt(raw.id, raw.message_id, raw.messageId);
  if (!id) {
    return null;
  }

  return {
    id,
    ai_conversation_id: toPositiveInt(raw.ai_conversation_id, raw.aiConversationId),
    user_id: toNullableInt(raw.user_id, raw.userId),
    role: String(raw.role ?? 'assistant'),
    content: String(raw.content ?? ''),
    provider: raw.provider ? String(raw.provider) : null,
    model: raw.model ? String(raw.model) : null,
    response_time_ms: toNullableInt(raw.response_time_ms, raw.responseTimeMs),
    meta: raw.meta && typeof raw.meta === 'object' ? raw.meta : {},
    created_at: raw.created_at ?? raw.createdAt ?? null,
    updated_at: raw.updated_at ?? raw.updatedAt ?? null,
  };
};

export const normalizeInternalAiConversation = (raw) => {
  if (!raw || typeof raw !== 'object') {
    return null;
  }

  const id = toPositiveInt(raw.id, raw.ai_conversation_id, raw.aiConversationId);
  if (!id) {
    return null;
  }

  const messages = pickArray(raw.messages)
    .map(normalizeInternalAiMessage)
    .filter(Boolean);
  const lastMessage = normalizeInternalAiMessage(pickObject(raw.last_message, raw.lastMessage));

  return {
    id,
    company_id: toNullableInt(raw.company_id, raw.companyId),
    opened_by_user_id: toNullableInt(raw.opened_by_user_id, raw.openedByUserId),
    origin: String(raw.origin ?? 'internal_chat'),
    title: raw.title ? String(raw.title) : '',
    messages,
    last_message: lastMessage,
    last_message_at:
      raw.last_message_at ??
      raw.lastMessageAt ??
      lastMessage?.created_at ??
      raw.updated_at ??
      raw.created_at ??
      null,
    created_at: raw.created_at ?? raw.createdAt ?? null,
    updated_at: raw.updated_at ?? raw.updatedAt ?? null,
  };
};

export const sortInternalAiConversationsByActivity = (items) =>
  [...(items ?? [])].sort((left, right) => {
    const leftTimestamp = Math.max(
      toTimestamp(left?.last_message_at),
      toTimestamp(left?.updated_at),
      toTimestamp(left?.created_at)
    );
    const rightTimestamp = Math.max(
      toTimestamp(right?.last_message_at),
      toTimestamp(right?.updated_at),
      toTimestamp(right?.created_at)
    );

    if (leftTimestamp !== rightTimestamp) {
      return rightTimestamp - leftTimestamp;
    }

    return Number(right?.id ?? 0) - Number(left?.id ?? 0);
  });

export const sortInternalAiMessagesChronologically = (items) =>
  [...(items ?? [])].sort((left, right) => {
    const leftTimestamp = toTimestamp(left?.created_at);
    const rightTimestamp = toTimestamp(right?.created_at);

    if (leftTimestamp !== rightTimestamp) {
      return leftTimestamp - rightTimestamp;
    }

    return Number(left?.id ?? 0) - Number(right?.id ?? 0);
  });

export const upsertInternalAiConversationInList = (list, incoming) => {
  if (!incoming?.id) {
    return list ?? [];
  }

  let found = false;
  const next = (list ?? []).map((conversation) => {
    if (Number(conversation.id) !== Number(incoming.id)) {
      return conversation;
    }

    found = true;
    return {
      ...conversation,
      ...incoming,
      last_message: incoming.last_message ?? conversation.last_message ?? null,
    };
  });

  return sortInternalAiConversationsByActivity(found ? next : [incoming, ...next]);
};

export async function listInternalAiConversations({ search = '', page = 1, perPage = 15, companyId = null } = {}) {
  const query = {};
  const normalizedSearch = String(search ?? '').trim();
  if (normalizedSearch) {
    query.search = normalizedSearch;
  }

  const normalizedPage = toPositiveInt(page);
  if (normalizedPage) {
    query.page = normalizedPage;
  }

  const normalizedPerPage = toPositiveInt(perPage);
  if (normalizedPerPage) {
    query.per_page = Math.min(normalizedPerPage, 50);
  }

  const normalizedCompanyId = toPositiveInt(companyId);
  if (normalizedCompanyId) {
    query.company_id = normalizedCompanyId;
  }

  const response = await get('/minha-conta/ia/conversas', {
    params: query,
  });
  const payload = response.data ?? {};

  const conversations = sortInternalAiConversationsByActivity(
    pickArray(payload.conversations).map(normalizeInternalAiConversation).filter(Boolean)
  );

  return {
    conversations,
    pagination: normalizePagination(payload.conversations_pagination),
  };
}

export async function createInternalAiConversation({ title = '', companyId = null } = {}) {
  const payload = {};
  const normalizedTitle = String(title ?? '').trim();
  if (normalizedTitle) {
    payload.title = normalizedTitle;
  }

  const normalizedCompanyId = toPositiveInt(companyId);
  if (normalizedCompanyId) {
    payload.company_id = normalizedCompanyId;
  }

  const response = await post('/minha-conta/ia/conversas', payload);
  const body = response.data ?? {};

  return {
    conversation: normalizeInternalAiConversation(
      pickObject(body.conversation, body.data?.conversation, body.data)
    ),
  };
}

export async function getInternalAiConversation({
  conversationId,
  messagesPage = null,
  messagesPerPage = 30,
  companyId = null,
}) {
  const normalizedConversationId = toPositiveInt(conversationId);
  if (!normalizedConversationId) {
    throw new Error('Conversa invalida.');
  }

  const query = {};
  const normalizedMessagesPage = toPositiveInt(messagesPage);
  if (normalizedMessagesPage) {
    query.messages_page = normalizedMessagesPage;
  }

  const normalizedMessagesPerPage = toPositiveInt(messagesPerPage);
  if (normalizedMessagesPerPage) {
    query.messages_per_page = Math.min(normalizedMessagesPerPage, 100);
  }

  const normalizedCompanyId = toPositiveInt(companyId);
  if (normalizedCompanyId) {
    query.company_id = normalizedCompanyId;
  }

  const response = await get(`/minha-conta/ia/conversas/${normalizedConversationId}`, {
    params: query,
  });
  const payload = response.data ?? {};

  const conversation = normalizeInternalAiConversation(
    pickObject(payload.conversation, payload.data?.conversation, payload.data)
  );
  if (conversation) {
    conversation.messages = sortInternalAiMessagesChronologically(conversation.messages ?? []);
  }

  return {
    conversation,
    messagesPagination: normalizePagination(payload.messages_pagination),
  };
}

/**
 * Stream the AI response using Server-Sent Events via fetch.
 *
 * Emits three types of events:
 *  - onDelta(chunk: string)  — called for each text token as it arrives
 *  - onDone({ userMessage, assistantMessage, conversation }) — called when the full response is persisted
 *  - onError(message: string) — called on any error
 *
 * @param {AbortSignal|null} signal - optional AbortController signal to cancel the stream
 */
export async function streamInternalAiConversationMessage({
  conversationId,
  content,
  companyId = null,
  onDelta = null,
  onDone = null,
  onError = null,
  signal = null,
}) {
  const normalizedConversationId = toPositiveInt(conversationId);
  if (!normalizedConversationId) {
    onError?.('Conversa invalida.');
    return;
  }

  const normalizedContent = String(content ?? '').trim();
  if (!normalizedContent) {
    onError?.('Informe uma mensagem para continuar.');
    return;
  }

  const baseUrl = import.meta.env.VITE_API_URL ?? import.meta.env.VITE_API_BASE ?? '/api';
  const url = `${baseUrl}/minha-conta/ia/conversas/${normalizedConversationId}/mensagens/stream`;

  const requestBody = { content: normalizedContent, text: normalizedContent };
  const normalizedCompanyId = toPositiveInt(companyId);
  if (normalizedCompanyId) {
    requestBody.company_id = normalizedCompanyId;
  }

  const headers = {
    'Content-Type': 'application/json',
    Accept: 'text/event-stream',
    'X-Requested-With': 'XMLHttpRequest',
  };

  // Include Laravel's CSRF token (stored in XSRF-TOKEN cookie by Sanctum)
  const xsrfToken = readXsrfToken();
  if (xsrfToken) {
    headers['X-XSRF-TOKEN'] = xsrfToken;
  }

  let response;
  try {
    response = await fetch(url, {
      method: 'POST',
      credentials: 'include',
      headers,
      body: JSON.stringify(requestBody),
      signal,
    });
  } catch (fetchError) {
    if (fetchError?.name === 'AbortError') return;
    onError?.('Não foi possível conectar ao servidor. Tente novamente.');
    return;
  }

  if (!response.ok) {
    let message = 'Não foi possível iniciar o streaming de resposta.';
    try {
      const json = await response.json();
      if (json?.message) message = String(json.message);
    } catch {
      // ignore parse errors
    }
    onError?.(message);
    return;
  }

  const reader = response.body?.getReader();
  if (!reader) {
    onError?.('Streaming não suportado neste navegador. Use um navegador moderno.');
    return;
  }

  const decoder = new TextDecoder();
  let buffer = '';

  try {
    while (true) {
      const { done, value } = await reader.read();
      if (done) break;

      buffer += decoder.decode(value, { stream: true });

      // SSE events are separated by "\n\n"
      const parts = buffer.split('\n\n');
      buffer = parts.pop() ?? '';

      for (const block of parts) {
        const dataLine = block.split('\n').find((line) => line.startsWith('data: '));
        if (!dataLine) continue;

        let parsed;
        try {
          parsed = JSON.parse(dataLine.slice(6));
        } catch {
          continue;
        }

        if (parsed.type === 'delta') {
          onDelta?.(String(parsed.content ?? ''));
        } else if (parsed.type === 'done') {
          const userMessage = normalizeInternalAiMessage(pickObject(parsed.user_message));
          const assistantMessage = normalizeInternalAiMessage(pickObject(parsed.assistant_message));
          const conversation = normalizeInternalAiConversation(pickObject(parsed.conversation));
          onDone?.({ userMessage, assistantMessage, conversation });
        } else if (parsed.type === 'error') {
          onError?.(String(parsed.message ?? 'Erro ao processar resposta da IA.'));
        }
      }
    }
  } catch (readError) {
    if (readError?.name === 'AbortError') return;
    onError?.('Conexao interrompida durante o streaming. Tente novamente.');
  } finally {
    reader.releaseLock();
  }
}

function readXsrfToken() {
  const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
  if (!match) return null;
  try {
    return decodeURIComponent(match[1]);
  } catch {
    return null;
  }
}

export async function sendInternalAiConversationMessage({ conversationId, content, companyId = null }) {
  const normalizedConversationId = toPositiveInt(conversationId);
  if (!normalizedConversationId) {
    throw new Error('Conversa invalida.');
  }

  const normalizedContent = String(content ?? '').trim();
  if (!normalizedContent) {
    throw new Error('Informe uma mensagem para continuar.');
  }

  const requestBody = {
    content: normalizedContent,
    text: normalizedContent,
  };

  const normalizedCompanyId = toPositiveInt(companyId);
  if (normalizedCompanyId) {
    requestBody.company_id = normalizedCompanyId;
  }

  const response = await post(`/minha-conta/ia/conversas/${normalizedConversationId}/mensagens`, requestBody);

  const payload = response.data ?? {};
  const conversation = normalizeInternalAiConversation(
    pickObject(payload.conversation, payload.data?.conversation, payload.data)
  );
  const userMessage = normalizeInternalAiMessage(
    pickObject(payload.user_message, payload.data?.user_message)
  );
  const assistantMessage = normalizeInternalAiMessage(
    pickObject(payload.assistant_message, payload.data?.assistant_message)
  );

  if (conversation && assistantMessage) {
    conversation.last_message = assistantMessage;
    conversation.last_message_at = assistantMessage.created_at ?? conversation.last_message_at;
  }

  return {
    conversation,
    userMessage,
    assistantMessage,
  };
}
