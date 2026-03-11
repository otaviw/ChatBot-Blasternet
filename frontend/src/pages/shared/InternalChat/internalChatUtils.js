const toTimestamp = (value) => {
  if (!value) {
    return 0;
  }

  const parsed = new Date(value).getTime();
  return Number.isFinite(parsed) ? parsed : 0;
};

const formatDateTime = (value) => {
  const timestamp = toTimestamp(value);
  if (!timestamp) {
    return '';
  }

  // Data + hora completas no padrão pt-BR, semelhante ao inbox
  return new Date(timestamp).toLocaleString('pt-BR');
};

const parseRoleFromUser = (user) => {
  const normalized = String(user?.role ?? '').trim().toLowerCase();
  return normalized === 'system_admin' ? 'admin' : 'company';
};

const parseErrorMessage = (error, fallbackText) =>
  String(error?.response?.data?.message ?? fallbackText);

export { formatDateTime, parseErrorMessage, parseRoleFromUser, toTimestamp };
