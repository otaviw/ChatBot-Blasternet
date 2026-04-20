import { useCallback, useEffect, useState } from 'react';
import api from '@/services/api';

function usePageData(url, initial = null) {
  const [data, setData] = useState(initial);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [refreshToken, setRefreshToken] = useState(0);

  // refetch() re-executa a busca sem mudar a URL.
  // Exposto principalmente para o botão "Tentar novamente" do ErrorPanel.
  const refetch = useCallback(() => setRefreshToken((t) => t + 1), []);

  useEffect(() => {
    let canceled = false;
    setLoading(true);
    api
      .get(url)
      .then((response) => {
        if (canceled) return;
        const payload = response.data;
        if (payload?.redirect) {
          window.location.href = payload.redirect;
          return;
        }
        setData(payload);
        setError(null);
      })
      .catch((err) => {
        if (canceled) return;
        const redirect = err.response?.data?.redirect;
        const status = Number(err?.status ?? err?.response?.status ?? 0);
        const shouldRedirectToAuth = status === 401 || status === 419;

        if (redirect && shouldRedirectToAuth) {
          window.location.href = redirect;
          return;
        }
        setError(err);
      })
      .finally(() => {
        if (!canceled) {
          setLoading(false);
        }
      });

    return () => {
      canceled = true;
    };
  }, [url, refreshToken]);

  return { data, loading, error, refetch };
}

export default usePageData;

