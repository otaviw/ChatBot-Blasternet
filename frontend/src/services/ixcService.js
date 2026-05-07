import api from '@/services/api';

function ensureOk(data, fallbackMessage) {
  if (data && typeof data === 'object' && data.ok === false) {
    throw new Error(String(data.message || fallbackMessage || 'Falha na integração IXC.'));
  }
  return data;
}

export async function listIxcClients(params = {}) {
  const response = await api.get('/minha-conta/ixc/clientes', { params });
  return ensureOk(response?.data ?? { ok: false }, 'Falha ao listar clientes IXC.');
}

export async function getIxcClient(clientId) {
  const response = await api.get(`/minha-conta/ixc/clientes/${clientId}`);
  return ensureOk(response?.data ?? { ok: false }, 'Falha ao consultar cliente IXC.');
}

export async function listIxcClientInvoices(clientId, params = {}) {
  const response = await api.get(`/minha-conta/ixc/clientes/${clientId}/boletos`, { params });
  return ensureOk(response?.data ?? { ok: false }, 'Falha ao listar boletos.');
}

export async function getIxcInvoiceDetail(clientId, invoiceId) {
  const response = await api.get(`/minha-conta/ixc/clientes/${clientId}/boletos/${invoiceId}`);
  return ensureOk(response?.data ?? { ok: false }, 'Falha ao consultar boleto.');
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
          throw { ...error, message };
        }
      } catch {
        // ignore parse error and keep original
      }
    }
    throw error;
  }
}

export async function sendIxcInvoiceEmail(clientId, invoiceId, email) {
  const response = await api.post(`/minha-conta/ixc/clientes/${clientId}/boletos/${invoiceId}/enviar-email`, { email });
  return ensureOk(response?.data ?? { ok: false }, 'Falha ao enviar boleto por e-mail.');
}

export async function sendIxcInvoiceSms(clientId, invoiceId, phone) {
  const response = await api.post(`/minha-conta/ixc/clientes/${clientId}/boletos/${invoiceId}/enviar-sms`, { phone });
  return ensureOk(response?.data ?? { ok: false }, 'Falha ao enviar boleto por SMS.');
}
