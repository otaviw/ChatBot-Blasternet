import { useEffect, useState } from 'react';
import api from '@/services/api';

function usePageData(url, initial = null) {
  const [data, setData] = useState(initial);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

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
        if (redirect) {
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
  }, [url]);

  return { data, loading, error };
}

export default usePageData;

