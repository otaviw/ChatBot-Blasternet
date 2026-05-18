import api from '@/services/api';

export async function fetchCompanyMetaNumbers(companyId) {
  const path = companyId
    ? `/admin/companies/${companyId}/meta-numbers`
    : '/minha-conta/meta-numbers';
  const response = await api.get(path);
  return Array.isArray(response?.data?.items) ? response.data.items : [];
}

export async function createCompanyMetaNumber(companyId, payload) {
  const response = await api.post(`/admin/companies/${companyId}/meta-numbers`, payload);
  return response?.data?.item ?? null;
}

export async function updateCompanyMetaNumber(companyId, numberId, payload) {
  const response = await api.patch(`/admin/companies/${companyId}/meta-numbers/${numberId}`, payload);
  return response?.data?.item ?? null;
}

export async function setCompanyMetaNumberPrimary(companyId, numberId) {
  const response = await api.patch(`/admin/companies/${companyId}/meta-numbers/${numberId}/set-primary`);
  return response?.data?.item ?? null;
}

export async function removeCompanyMetaNumber(companyId, numberId, strategy = 'deactivate') {
  await api.delete(`/admin/companies/${companyId}/meta-numbers/${numberId}`, { params: { strategy } });
}
