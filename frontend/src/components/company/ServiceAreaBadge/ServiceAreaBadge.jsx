import { useState, useEffect } from 'react';
import { getServiceAreaPaletteEntry, getServiceAreaPaletteEntryDark } from '@/utils/serviceAreaColors';

/**
 * Etiqueta colorida para o nome de uma área de atendimento (alinhada à ordem em `serviceAreaNames`).
 */
function ServiceAreaBadge({ areaName, serviceAreaNames = [], className = '' }) {
  const [isDark, setIsDark] = useState(() => document.body.classList.contains('theme-dark'));

  useEffect(() => {
    const observer = new MutationObserver(() => {
      setIsDark(document.body.classList.contains('theme-dark'));
    });
    observer.observe(document.body, { attributes: true, attributeFilter: ['class'] });
    return () => observer.disconnect();
  }, []);

  if (!areaName) return null;
  const { bg, border, text } = isDark
    ? getServiceAreaPaletteEntryDark(areaName, serviceAreaNames)
    : getServiceAreaPaletteEntry(areaName, serviceAreaNames);

  return (
    <span
      className={`inline-flex max-w-full items-center truncate rounded-md border px-1.5 py-0.5 text-[11px] font-semibold leading-tight ${className}`}
      style={{
        backgroundColor: bg,
        borderColor: border,
        color: text,
      }}
      title={areaName}
    >
      {areaName}
    </span>
  );
}

export default ServiceAreaBadge;
