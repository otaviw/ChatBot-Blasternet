import { afterEach, describe, expect, it, vi } from 'vitest';

async function loadApiClient(pathname = '/painel') {
  vi.resetModules();
  globalThis.window = {
    location: {
      pathname,
      assign: vi.fn(),
    },
  };

  const mod = await import('./apiClient');
  const apiClient = mod.default;
  const requestFulfilled = apiClient.interceptors.request.handlers.find(
    (handler) => typeof handler?.fulfilled === 'function'
  )?.fulfilled;
  const rejected = apiClient.interceptors.response.handlers.find(
    (handler) => typeof handler?.rejected === 'function'
  )?.rejected;

  if (!requestFulfilled || !rejected) {
    throw new Error('Interceptor handlers not found');
  }

  return { requestFulfilled, rejected, windowMock: globalThis.window };
}

function makeAxiosError({ status, data, code, url = '/api/recurso', skipAuthRedirect = false } = {}) {
  return {
    isAxiosError: true,
    code,
    config: {
      url,
      skipAuthRedirect,
    },
    request: { mocked: true },
    response: status
      ? {
          status,
          data,
        }
      : undefined,
  };
}

async function runRejected(rejected, error) {
  try {
    await rejected(error);
    throw new Error('Expected interceptor to reject');
  } catch (normalized) {
    return normalized;
  }
}

describe('apiClient', () => {
  afterEach(() => {
    vi.restoreAllMocks();
    delete globalThis.window;
    delete globalThis.crypto;
  });

  describe('resolveValidationErrors (via normalizeApiError)', () => {
    it('normaliza erros de validação quando payload é válido', async () => {
      const { rejected } = await loadApiClient();
      const responseData = {
        message: 'Dados inválidos.',
        errors: {
          email: ['Obrigatório', 'Formato inválido'],
          role: 'Perfil inválido',
          empty_list: [],
          empty_string: '   ',
          0: ['campo inválido'],
        },
      };

      const normalized = await runRejected(
        rejected,
        makeAxiosError({ status: 422, data: responseData })
      );

      expect(normalized.status).toBe(422);
      expect(normalized.validationErrors).toEqual({
        0: ['campo inválido'],
        email: ['Obrigatório', 'Formato inválido'],
        role: ['Perfil inválido'],
      });
      expect(normalized.response.data.errors).toEqual({
        0: ['campo inválido'],
        email: ['Obrigatório', 'Formato inválido'],
        role: ['Perfil inválido'],
      });
    });

    it('retorna objeto vazio quando payload de validação é inválido', async () => {
      const { rejected } = await loadApiClient();
      const responseData = {
        message: 'Erro de validação',
        errors: ['nao-eh-objeto'],
      };

      const normalized = await runRejected(
        rejected,
        makeAxiosError({ status: 422, data: responseData })
      );

      expect(normalized.status).toBe(422);
      expect(normalized.validationErrors).toEqual({});
      expect(normalized.response.data.errors).toEqual({});
    });
  });

  describe('request-id header injection', () => {
    it('injeta X-Request-ID quando não existir no request', async () => {
      const { requestFulfilled } = await loadApiClient();
      const randomUUID = vi.fn(() => 'uuid-test-123');
      globalThis.crypto = { randomUUID };

      const config = requestFulfilled({ headers: {} });

      expect(config.headers['X-Request-ID']).toBe('uuid-test-123');
      expect(randomUUID).toHaveBeenCalledOnce();
      delete globalThis.crypto;
    });

    it('preserva X-Request-ID existente no request', async () => {
      const { requestFulfilled } = await loadApiClient();
      const config = requestFulfilled({ headers: { 'X-Request-ID': 'frontend-existing-id' } });

      expect(config.headers['X-Request-ID']).toBe('frontend-existing-id');
    });
  });

  describe('resolveErrorMessage (via normalizeApiError)', () => {
    it('resolve mensagens para 401, 422, 429, 503 e falha de rede', async () => {
      const { rejected } = await loadApiClient();

      const unauthorized = await runRejected(
        rejected,
        makeAxiosError({ status: 401, data: {}, url: '/login' })
      );
      expect(unauthorized.message).toBe('Sessao expirada. Faca login novamente.');

      const unprocessable = await runRejected(
        rejected,
        makeAxiosError({ status: 422, data: { message: 'Campo inválido.' } })
      );
      expect(unprocessable.message).toBe('Campo inválido.');

      const tooManyRequests = await runRejected(
        rejected,
        makeAxiosError({ status: 429, data: { message: 'Muitas requisições.' } })
      );
      expect(tooManyRequests.message).toBe('Muitas requisições.');

      const unavailable = await runRejected(
        rejected,
        makeAxiosError({ status: 503, data: {} })
      );
      expect(unavailable.message).toBe('Não foi possível concluir a operação.');

      const network = await runRejected(
        rejected,
        makeAxiosError({ code: 'ERR_NETWORK' })
      );
      expect(network.message).toBe('Falha de conexão. Verifique sua internet e tente novamente.');
    });
  });

  describe('redirect 401', () => {
    it('redireciona para /entrar quando recebe 401 fora de endpoints de auth', async () => {
      const { rejected, windowMock } = await loadApiClient('/dashboard');

      await runRejected(
        rejected,
        makeAxiosError({
          status: 401,
          data: {},
          url: '/api/minha-conta/conversas',
        })
      );

      expect(windowMock.location.assign).toHaveBeenCalledOnce();
      expect(windowMock.location.assign).toHaveBeenCalledWith('/entrar');
    });
  });

  describe('normalizeApiError (estrutura de retorno)', () => {
    it('retorna estrutura normalizada com os campos esperados', async () => {
      const { rejected } = await loadApiClient();

      const normalized = await runRejected(
        rejected,
        makeAxiosError({
          status: 422,
          code: 'ERR_BAD_REQUEST',
          data: {
            message: 'Payload inválido',
            error: 'validation_error',
            code: 'VAL_001',
            errors: {
              ai_model: ['Modelo obrigatório'],
            },
          },
        })
      );

      expect(normalized).toMatchObject({
        message: 'Payload inválido',
        error: 'validation_error',
        code: 'VAL_001',
        status: 422,
        validationErrors: {
          ai_model: ['Modelo obrigatório'],
        },
        isAxiosError: true,
      });
      expect(normalized.response).not.toBeNull();
      expect(normalized.request).not.toBeNull();
      expect(normalized.config).toMatchObject({ url: '/api/recurso' });
    });
  });
});
