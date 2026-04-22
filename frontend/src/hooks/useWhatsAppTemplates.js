import { useCallback, useState } from 'react';
import api from '@/services/api';

/**
 * Busca os templates aprovados da Meta para a empresa autenticada.
 * Chame `loadTemplates()` ao abrir qualquer modal que precise da lista.
 */
export default function useWhatsAppTemplates() {
  const [templates, setTemplates] = useState([]);
  const [templatesLoading, setTemplatesLoading] = useState(false);
  const [templatesError, setTemplatesError] = useState('');

  const loadTemplates = useCallback(async () => {
    if (templatesLoading) return;
    setTemplatesLoading(true);
    setTemplatesError('');
    try {
      const response = await api.get('/minha-conta/templates');
      setTemplates(response.data?.templates ?? []);
      if ((response.data?.templates ?? []).length === 0 && response.data?.error) {
        setTemplatesError('Não foi possível carregar os templates da Meta.');
      }
    } catch {
      setTemplatesError('Erro ao buscar templates. Verifique as credenciais da empresa.');
      setTemplates([]);
    } finally {
      setTemplatesLoading(false);
    }
  }, [templatesLoading]);

  return { templates, templatesLoading, templatesError, loadTemplates };
}
