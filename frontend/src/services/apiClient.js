import axios from 'axios';

const LOGIN_ROUTE = '/entrar';
const AUTH_ENDPOINTS = ['/login', '/forgot-password', '/reset-password', '/sanctum/csrf-cookie'];

let isRedirectingToLogin = false;

const apiClient = axios.create({
  baseURL: import.meta.env.VITE_API_URL ?? import.meta.env.VITE_API_BASE ?? '/api',
  withCredentials: true,
  headers: {
    Accept: 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
  },
});

function generateRequestId() {
  if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
    return crypto.randomUUID();
  }

  return `req-${Date.now()}-${Math.random().toString(16).slice(2, 10)}`;
}

function readObject(value) {
  return value && typeof value === 'object' ? value : {};
}

function readString(value) {
  const normalized = String(value ?? '').trim();
  return normalized || '';
}

function resolveValidationErrors(payload) {
  const source = payload?.errors;
  if (!source || typeof source !== 'object' || Array.isArray(source)) {
    return {};
  }

  const normalized = {};

  for (const [field, value] of Object.entries(source)) {
    if (!field) {
      continue;
    }

    if (Array.isArray(value)) {
      const messages = value
        .map((item) => readString(item))
        .filter(Boolean);
      if (messages.length > 0) {
        normalized[field] = messages;
      }
      continue;
    }

    const single = readString(value);
    if (single) {
      normalized[field] = [single];
    }
  }

  return normalized;
}

function resolveErrorMessage(payload, status, originalError) {
  if (status === 401) {
    return 'Sessao expirada. Faca login novamente.';
  }

  if (status === 422) {
    return readString(payload?.message) || 'Dados inválidos. Revise os campos e tente novamente.';
  }

  if (status === 429) {
    return readString(payload?.message) || 'Muitas requisicoes em pouco tempo. Aguarde alguns segundos e tente novamente.';
  }

  if (status === 502 || status === 503) {
    return readString(payload?.message) || 'Servico temporariamente indisponivel. Tente novamente em instantes.';
  }

  const payloadMessage = readString(payload?.message);
  if (payloadMessage) {
    return payloadMessage;
  }

  const payloadError = readString(payload?.error);
  if (payloadError) {
    return payloadError;
  }

  if (originalError?.code === 'ECONNABORTED') {
    return 'A requisicao demorou demais para responder.';
  }

  if (originalError?.code === 'ERR_CANCELED') {
    return 'Requisicao cancelada.';
  }

  if (!status) {
    return 'Falha de conexão. Verifique sua internet e tente novamente.';
  }

  return 'Não foi possível concluir a operação.';
}

function resolveErrorCode(payload, status, originalError) {
  const payloadCode = readString(payload?.code || payload?.error_code);
  if (payloadCode) {
    return payloadCode;
  }

  const axiosCode = readString(originalError?.code);
  if (axiosCode) {
    return axiosCode;
  }

  if (status) {
    return `HTTP_${status}`;
  }

  return 'UNKNOWN_ERROR';
}

function shouldHandleAuthRedirect(error) {
  const requestUrl = readString(error?.config?.url).toLowerCase();
  if (AUTH_ENDPOINTS.some((path) => requestUrl.includes(path))) {
    return false;
  }

  return !error?.config?.skipAuthRedirect;
}

function redirectToLogin() {
  if (typeof window === 'undefined') {
    return;
  }

  if (isRedirectingToLogin) {
    return;
  }

  if (window.location.pathname === LOGIN_ROUTE) {
    return;
  }

  isRedirectingToLogin = true;
  window.location.assign(LOGIN_ROUTE);
}

function normalizeApiError(error) {
  const response = error?.response ?? null;
  const payload = readObject(response?.data);
  const status = Number(response?.status) || null;
  const validationErrors = status === 422 ? resolveValidationErrors(payload) : null;
  const message = resolveErrorMessage(payload, status, error);

  if (status === 422 && response?.data && typeof response.data === 'object') {
    response.data.errors = validationErrors ?? {};
  }

  return {
    message,
    error: readString(payload?.error) || message,
    code: resolveErrorCode(payload, status, error),
    status,
    validationErrors: validationErrors ?? undefined,
    response,
    request: error?.request ?? null,
    config: error?.config ?? null,
    isAxiosError: Boolean(error?.isAxiosError),
  };
}

apiClient.interceptors.request.use((config) => {
  const headers = config.headers ?? {};
  if (!headers['X-Request-ID'] && !headers['x-request-id']) {
    headers['X-Request-ID'] = generateRequestId();
  }
  config.headers = headers;

  return config;
});

apiClient.interceptors.response.use(
  (response) => response,
  (error) => {
    const normalizedError = normalizeApiError(error);

    if (normalizedError.status === 401 && shouldHandleAuthRedirect(error)) {
      redirectToLogin();
    }

    return Promise.reject(normalizedError);
  }
);

const get = (url, config = {}) => apiClient.get(url, config);
const post = (url, data, config = {}) => apiClient.post(url, data, config);
const put = (url, data, config = {}) => apiClient.put(url, data, config);
const patch = (url, data, config = {}) => apiClient.patch(url, data, config);
const remove = (url, config = {}) => apiClient.delete(url, config);

export { get, post, put, patch, remove as delete };
export default apiClient;

