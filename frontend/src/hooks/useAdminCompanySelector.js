import { useEffect, useState } from 'react';
import api from '@/services/api';

export default function useAdminCompanySelector({ isAdmin }) {
  const [companies, setCompanies] = useState([]);
  const [selectedCompanyId, setSelectedCompanyId] = useState('');

  useEffect(() => {
    if (!isAdmin) return;
    api.get('/admin/empresas')
      .then((res) => {
        const list = Array.isArray(res.data?.companies) ? res.data.companies : [];
        setCompanies(list);
        if (list.length > 0 && selectedCompanyId === '') {
          setSelectedCompanyId(String(list[0].id));
        }
      })
      .catch(() => {});
  }, [isAdmin]);

  return { companies, selectedCompanyId, setSelectedCompanyId };
}
