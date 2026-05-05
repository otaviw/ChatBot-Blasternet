import api from '@/services/api';

export async function listIxcClients(params = {}) {
  const response = await api.get('/minha-conta/ixc/clientes', { params });
  return response?.data ?? { ok: false, items: [] };
}

export async function getIxcClient(clientId) {
  const response = await api.get(`/minha-conta/ixc/clientes/${clientId}`);
  return response?.data ?? { ok: false, client: null };
}

export async function listIxcClientInvoices(clientId, params = {}) {
  const response = await api.get(`/minha-conta/ixc/clientes/${clientId}/boletos`, { params });
  return response?.data ?? { ok: false, items: [] };
}

export async function getIxcInvoiceDetail(clientId, invoiceId) {
  const response = await api.get(`/minha-conta/ixc/clientes/${clientId}/boletos/${invoiceId}`);
  return response?.data ?? { ok: false, item: null };
}

export async function downloadIxcInvoice(clientId, invoiceId) {
  const response = await api.post(
    `/minha-conta/ixc/clientes/${clientId}/boletos/${invoiceId}/download`,
    {},
    { responseType: 'blob' }
  );
  return response;
}

export async function sendIxcInvoiceEmail(clientId, invoiceId, email) {
  const response = await api.post(`/minha-conta/ixc/clientes/${clientId}/boletos/${invoiceId}/enviar-email`, { email });
  return response?.data ?? { ok: false };
}

export async function sendIxcInvoiceSms(clientId, invoiceId, phone) {
  const response = await api.post(`/minha-conta/ixc/clientes/${clientId}/boletos/${invoiceId}/enviar-sms`, { phone });
  return response?.data ?? { ok: false };
}
