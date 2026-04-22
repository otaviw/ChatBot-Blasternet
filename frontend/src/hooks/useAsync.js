import { useCallback, useMemo, useState } from 'react';

function useAsync({ initialLoading = false, initialError = null } = {}) {
  const [loading, setLoading] = useState(Boolean(initialLoading));
  const [error, setError] = useState(initialError);

  const clearError = useCallback(() => {
    setError(null);
  }, []);

  const run = useCallback(async (operation, { mapError } = {}) => {
    if (typeof operation !== 'function') {
      throw new Error('useAsync.run espera uma funcao assincorna.');
    }

    setLoading(true);
    setError(null);

    try {
      const data = await operation();
      return { data, error: null };
    } catch (caughtError) {
      const normalizedError =
        typeof mapError === 'function' ? mapError(caughtError) : caughtError;
      setError(normalizedError);
      return { data: null, error: normalizedError };
    } finally {
      setLoading(false);
    }
  }, []);

  return useMemo(
    () => ({
      loading,
      error,
      run,
      setError,
      setLoading,
      clearError,
    }),
    [clearError, error, loading, run]
  );
}

export default useAsync;
