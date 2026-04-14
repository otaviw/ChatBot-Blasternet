import { useEffect } from 'react';
import {
  formatDocumentTitle,
  restoreOriginalFavicon,
  updateFaviconBadge,
} from '@/utils/faviconBadge';

export default function useFaviconBadge(count) {
  useEffect(() => {
    const title = formatDocumentTitle(count);
    document.title = title;
    void updateFaviconBadge(count);
  }, [count]);

  useEffect(() => {
    return () => {
      document.title = formatDocumentTitle(0);
      void restoreOriginalFavicon();
    };
  }, []);
}

