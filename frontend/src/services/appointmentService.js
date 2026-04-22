import { delete as remove, get, patch, post, put } from '@/services/apiClient';

const basePath = '/minha-conta/agendamentos';

function unwrap(response) {
  return response?.data ?? null;
}

function unwrapError(error, fallback) {
  return (
    error?.response?.data?.message ||
    error?.response?.data?.error ||
    fallback
  );
}

export async function fetchAppointmentSettings() {
  const response = await get(`${basePath}/configuracoes`);
  return unwrap(response);
}

export async function updateAppointmentSettings(payload) {
  const response = await put(`${basePath}/configuracoes`, payload);
  return unwrap(response);
}

export async function fetchAppointmentServices() {
  const response = await get(`${basePath}/servicos`);
  return unwrap(response);
}

export async function createAppointmentService(payload) {
  const response = await post(`${basePath}/servicos`, payload);
  return unwrap(response);
}

export async function updateAppointmentService(serviceId, payload) {
  const response = await put(`${basePath}/servicos/${serviceId}`, payload);
  return unwrap(response);
}

export async function fetchAppointmentStaff() {
  const response = await get(`${basePath}/atendentes`);
  return unwrap(response);
}

export async function updateAppointmentStaff(staffProfileId, payload) {
  const response = await put(`${basePath}/atendentes/${staffProfileId}`, payload);
  return unwrap(response);
}

export async function replaceStaffWorkingHours(staffProfileId, payload) {
  const response = await put(`${basePath}/atendentes/${staffProfileId}/jornada`, payload);
  return unwrap(response);
}

export async function fetchAppointmentAvailability(params) {
  const response = await get(`${basePath}/disponibilidade`, { params });
  return unwrap(response);
}

export async function fetchAppointments(params) {
  const response = await get(basePath, { params });
  return unwrap(response);
}

export async function createAppointment(payload) {
  const response = await post(basePath, payload);
  return unwrap(response);
}

export async function updateAppointmentStatus(appointmentId, status, options = {}) {
  const response = await patch(`${basePath}/${appointmentId}/status`, { status, ...options });
  return unwrap(response);
}

export async function deleteAppointment(appointmentId) {
  const response = await remove(`${basePath}/${appointmentId}`);
  return unwrap(response);
}

export async function fetchTimeOffs(params) {
  const response = await get(`${basePath}/bloqueios`, { params });
  return unwrap(response);
}

export async function createTimeOff(payload) {
  const response = await post(`${basePath}/bloqueios`, payload);
  return unwrap(response);
}

export async function deleteTimeOff(timeOffId) {
  const response = await remove(`${basePath}/bloqueios/${timeOffId}`);
  return unwrap(response);
}

export { unwrapError };
