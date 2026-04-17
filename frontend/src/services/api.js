import axios from 'axios';

const api = axios.create({
  baseURL: import.meta.env.VITE_API_BASE ?? '/api',
  withCredentials: true,
  headers: { Accept: 'application/json' },
});

const LOGIN_ROUTE = '/entrar';
const AUTH_ENDPOINTS = ['/login', '/forgot-password', '/reset-password', '/sanctum/csrf-cookie'];
let isRedirectingToLogin = false;

function readObject(value) {
  return value && typeof value === 'object' ? value : {};
}

function readString(value) {
  const normalized = String(value ?? '').trim();
  return normalized || '';
}

function resolveErrorMessage(payload, status, originalError) {
  const payloadMessage = readString(payload?.message);
  if (payloadMessage) return payloadMessage;

  const payloadError = readString(payload?.error);
  if (payloadError) return payloadError;

  if (status === 401) {
    return 'Sessao expirada. Faca login novamente.';
  }

  if (originalError?.code === 'ECONNABORTED') {
    return 'A requisicao demorou demais para responder.';
  }

  if (originalError?.code === 'ERR_CANCELED') {
    return 'Requisicao cancelada.';
  }

  if (!status) {
    return 'Falha de conexao. Verifique sua internet e tente novamente.';
  }

  return 'Nao foi possivel concluir a operacao.';
}

function resolveErrorCode(payload, status, originalError) {
  const payloadCode = readString(payload?.code || payload?.error_code);
  if (payloadCode) return payloadCode;

  const axiosCode = readString(originalError?.code);
  if (axiosCode) return axiosCode;

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
  if (typeof window === 'undefined') return;
  if (isRedirectingToLogin) return;
  if (window.location.pathname === LOGIN_ROUTE) return;

  isRedirectingToLogin = true;
  window.location.assign(LOGIN_ROUTE);
}

function normalizeApiError(error) {
  const response = error?.response ?? null;
  const payload = readObject(response?.data);
  const status = Number(response?.status) || null;
  const message = resolveErrorMessage(payload, status, error);

  return {
    message,
    error: readString(payload?.error) || message,
    code: resolveErrorCode(payload, status, error),
    status,
    response,
    request: error?.request ?? null,
    config: error?.config ?? null,
    isAxiosError: Boolean(error?.isAxiosError),
  };
}

api.interceptors.response.use(
  (response) => response,
  (error) => {
    const normalizedError = normalizeApiError(error);

    if (normalizedError.status === 401 && shouldHandleAuthRedirect(error)) {
      redirectToLogin();
    }

    return Promise.reject(normalizedError);
  }
);

export default api;
