import axios from 'axios';

const LOGIN_ROUTE = '/entrar';
const AUTH_ENDPOINTS = ['/login', '/forgot-password', '/reset-password', '/sanctum/csrf-cookie'];
const TOKEN_STORAGE_KEYS = ['auth_token', 'access_token', 'token', 'authToken', 'jwt'];
const TOKEN_COOKIE_KEYS = ['auth_token', 'access_token', 'token'];

let isRedirectingToLogin = false;

const apiClient = axios.create({
  baseURL: import.meta.env.VITE_API_URL ?? import.meta.env.VITE_API_BASE ?? '/api',
  withCredentials: true,
  headers: { Accept: 'application/json' },
});

function readObject(value) {
  return value && typeof value === 'object' ? value : {};
}

function readString(value) {
  const normalized = String(value ?? '').trim();
  return normalized || '';
}

function readTokenFromStorage(storage) {
  if (!storage) {
    return '';
  }

  for (const key of TOKEN_STORAGE_KEYS) {
    const value = readString(storage.getItem(key));
    if (value) {
      return value;
    }
  }

  return '';
}

function readTokenFromCookies() {
  if (typeof document === 'undefined') {
    return '';
  }

  const cookies = readString(document.cookie);
  if (!cookies) {
    return '';
  }

  for (const key of TOKEN_COOKIE_KEYS) {
    const pattern = new RegExp(`(?:^|;\\s*)${key}=([^;]+)`);
    const match = cookies.match(pattern);
    if (!match?.[1]) {
      continue;
    }

    try {
      const decoded = decodeURIComponent(match[1]);
      if (readString(decoded)) {
        return decoded;
      }
    } catch {
      if (readString(match[1])) {
        return match[1];
      }
    }
  }

  return '';
}

function readStoredToken() {
  if (typeof window === 'undefined') {
    return '';
  }

  try {
    const fromLocalStorage = readTokenFromStorage(window.localStorage);
    if (fromLocalStorage) {
      return fromLocalStorage;
    }
  } catch {
    // ignore
  }

  try {
    const fromSessionStorage = readTokenFromStorage(window.sessionStorage);
    if (fromSessionStorage) {
      return fromSessionStorage;
    }
  } catch {
    // ignore
  }

  return readTokenFromCookies();
}

function clearStoredToken() {
  if (typeof window === 'undefined') {
    return;
  }

  const storages = [window.localStorage, window.sessionStorage];
  for (const storage of storages) {
    try {
      for (const key of TOKEN_STORAGE_KEYS) {
        storage?.removeItem(key);
      }
    } catch {
      // ignore
    }
  }

  if (typeof document === 'undefined') {
    return;
  }

  for (const key of TOKEN_COOKIE_KEYS) {
    document.cookie = `${key}=; Max-Age=0; path=/`;
  }
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
  const token = readStoredToken();
  if (!token) {
    return config;
  }

  if (typeof config.headers?.set === 'function') {
    const hasAuthHeader =
      readString(config.headers.get('Authorization')) ||
      readString(config.headers.get('authorization'));
    if (!hasAuthHeader) {
      config.headers.set('Authorization', `Bearer ${token}`);
    }
    return config;
  }

  const headers = readObject(config.headers);
  if (!headers.Authorization && !headers.authorization) {
    config.headers = {
      ...headers,
      Authorization: `Bearer ${token}`,
    };
  }

  return config;
});

apiClient.interceptors.response.use(
  (response) => response,
  (error) => {
    const normalizedError = normalizeApiError(error);

    if (normalizedError.status === 401 && shouldHandleAuthRedirect(error)) {
      clearStoredToken();
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
