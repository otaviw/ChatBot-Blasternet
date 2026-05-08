import api from '@/services/api';

function normalizeIxcMessage(message, fallbackMessage) {
  const text = String(message || '').trim();
  if (!text) {
    return fallbackMessage || 'Falha na integracao IXC.';
  }

  const normalized = text.toLowerCase();
  if (/ixc respondeu com http\s*\d{3}/i.test(text)) {
    return 'Nao foi possivel concluir a consulta na IXC com as permissoes atuais. Verifique o usuario/token da integracao.';
  }
  if (normalized.includes('resposta invalida da api ixc')) {
    return 'A IXC retornou um formato de resposta inesperado. Verifique permissoes e configuracao da integracao.';
  }
  if (normalized.includes('falha de conex')) {
    return 'Nao foi possivel conectar na IXC no momento. Tente novamente em instantes.';
  }
  if (normalized.includes('temporariamente indispon')) {
    return 'A integracao IXC esta temporariamente indisponivel. Tente novamente em instantes.';
  }

  return text;
}

function ensureOk(data, fallbackMessage) {
  if (data && typeof data === 'object' && data.ok === false) {
    throw new Error(normalizeIxcMessage(data.message, fallbackMessage || 'Falha na integracao IXC.'));
  }
  return data;
}

export async function listIxcClients(params = {}) {
  try {
    const response = await api.get('/minha-conta/ixc/clientes', { params });
    return ensureOk(response?.data ?? { ok: false }, 'Falha ao listar clientes IXC.');
  } catch (error) {
    throw new Error(normalizeIxcMessage(error?.message, 'Falha ao listar clientes IXC.'));
  }
}

export async function getIxcClient(clientId) {
  try {
    const response = await api.get(`/minha-conta/ixc/clientes/${clientId}`);
    return ensureOk(response?.data ?? { ok: false }, 'Falha ao consultar cliente IXC.');
  } catch (error) {
    throw new Error(normalizeIxcMessage(error?.message, 'Falha ao consultar cliente IXC.'));
  }
}

export async function listIxcClientInvoices(clientId, params = {}) {
  try {
    const response = await api.get(`/minha-conta/ixc/clientes/${clientId}/boletos`, { params });
    return ensureOk(response?.data ?? { ok: false }, 'Falha ao listar boletos.');
  } catch (error) {
    throw new Error(normalizeIxcMessage(error?.message, 'Falha ao listar boletos.'));
  }
}

export async function getIxcInvoiceDetail(clientId, invoiceId) {
  try {
    const response = await api.get(`/minha-conta/ixc/clientes/${clientId}/boletos/${invoiceId}`);
    return ensureOk(response?.data ?? { ok: false }, 'Falha ao consultar boleto.');
  } catch (error) {
    throw new Error(normalizeIxcMessage(error?.message, 'Falha ao consultar boleto.'));
  }
}

export async function downloadIxcInvoice(clientId, invoiceId) {
  try {
    const response = await api.post(
      `/minha-conta/ixc/clientes/${clientId}/boletos/${invoiceId}/download`,
      {},
      { responseType: 'blob' }
    );
    return response;
  } catch (error) {
    const blob = error?.response?.data;
    if (blob instanceof Blob) {
      try {
        const text = await blob.text();
        const parsed = JSON.parse(text);
        const message = String(parsed?.message || parsed?.error || '').trim();
        if (message) {
          throw { ...error, message: normalizeIxcMessage(message, 'Falha ao baixar boleto.') };
        }
      } catch {
        // ignore parse error and keep original
      }
    }

    if (error && typeof error === 'object') {
      throw { ...error, message: normalizeIxcMessage(error?.message, 'Falha ao baixar boleto.') };
    }

    throw error;
  }
}

export async function sendIxcInvoiceEmail(clientId, invoiceId, email) {
  try {
    const response = await api.post(`/minha-conta/ixc/clientes/${clientId}/boletos/${invoiceId}/enviar-email`, { email });
    return ensureOk(response?.data ?? { ok: false }, 'Falha ao enviar boleto por e-mail.');
  } catch (error) {
    throw new Error(normalizeIxcMessage(error?.message, 'Falha ao enviar boleto por e-mail.'));
  }
}

export async function sendIxcInvoiceSms(clientId, invoiceId, phone) {
  try {
    const response = await api.post(`/minha-conta/ixc/clientes/${clientId}/boletos/${invoiceId}/enviar-sms`, { phone });
    return ensureOk(response?.data ?? { ok: false }, 'Falha ao enviar boleto por SMS.');
  } catch (error) {
    throw new Error(normalizeIxcMessage(error?.message, 'Falha ao enviar boleto por SMS.'));
  }
}
