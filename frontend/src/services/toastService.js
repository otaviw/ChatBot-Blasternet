import {
  showErrorToast,
  showInfoToast,
  showSuccessToast,
} from '@/components/ui/Toast/toastHelpers.js';
import { toast } from 'react-hot-toast';

export function showSuccess(message, options = {}) {
  return showSuccessToast(message, options);
}

export function showError(message, options = {}) {
  return showErrorToast(message, options);
}

export function showInfo(message, options = {}) {
  return showInfoToast(message, options);
}

export default toast;
