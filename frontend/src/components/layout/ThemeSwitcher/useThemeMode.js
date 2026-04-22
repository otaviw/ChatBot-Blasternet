import { useEffect, useState } from 'react';

export default function useThemeMode() {
  const [themeMode, setThemeMode] = useState(() => {
    try {
      const storedMode = String(window.localStorage.getItem('ui-theme-mode') ?? '')
        .trim()
        .toLowerCase();
      return storedMode === 'dark' ? 'dark' : 'light';
    } catch {
      return 'light';
    }
  });

  useEffect(() => {
    const root = document.body;

    if (themeMode === 'dark') {
      root.classList.add('theme-dark');
    } else {
      root.classList.remove('theme-dark');
    }

    window.localStorage.setItem('ui-theme-mode', themeMode);
  }, [themeMode]);

  const toggleThemeMode = () => {
    setThemeMode((previous) => (previous === 'dark' ? 'light' : 'dark'));
  };

  return {
    themeMode,
    toggleThemeMode,
  };
}
