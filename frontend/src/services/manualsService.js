import api from '@/services/api';

export async function listManuals() {
  const response = await api.get('/manuais');
  return response.data ?? { manuals: [], can_manage: false };
}

export async function createManual(payload) {
  const response = await api.post('/admin/manuais', payload);
  return response.data?.manual ?? null;
}

export async function updateManual(manualId, payload) {
  const response = await api.put(`/admin/manuais/${manualId}`, payload);
  return response.data?.manual ?? null;
}

export async function deleteManual(manualId) {
  await api.delete(`/admin/manuais/${manualId}`);
}

