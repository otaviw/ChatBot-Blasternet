import { useContext } from 'react';
import { BrandContext, DEFAULT_BRANDING } from '@/contexts/brandContextObject';

export default function useBrand() {
  const context = useContext(BrandContext);
  return context?.branding ?? DEFAULT_BRANDING;
}

