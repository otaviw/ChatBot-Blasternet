import { useState } from 'react';
import api from '../lib/api';

function useLogout() {
  const [error, setError] = useState(null);

  const logout = async () => {
    try {
      await api.post('/logout');
      window.location.href = '/entrar';
    } catch (err) {
      setError(err);
    }
  };

  return { logout, error };
}

export default useLogout;
