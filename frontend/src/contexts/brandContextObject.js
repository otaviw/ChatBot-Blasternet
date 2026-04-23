import { createContext } from 'react';

export const DEFAULT_BRANDING = {
  name: 'Blasternet ChatBot',
  logo: null,
  primary_color: null,
};

export const BrandContext = createContext({
  branding: DEFAULT_BRANDING,
});

