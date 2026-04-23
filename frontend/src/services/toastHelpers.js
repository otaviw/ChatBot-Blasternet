import { toast } from 'react-hot-toast';

function normalizeMessage(message, fallback) {
  const value = String(message ?? '').trim();
  return value || fallback;
}

export function showSuccessToast(message, options = {}) {
  return toast.success(normalizeMessage(message, 'Operacao concluida com sucesso.'), options);
}

export function showErrorToast(message, options = {}) {
  return toast.error(normalizeMessage(message, 'Não foi possível concluir a operação.'), options);
}

export function showInfoToast(message, options = {}) {
  return toast(normalizeMessage(message, 'Informação'), options);
}
