import { getServiceAreaPaletteEntry } from '@/utils/serviceAreaColors';

/**
 * Etiqueta colorida para o nome de uma área de atendimento (alinhada à ordem em `serviceAreaNames`).
 */
function ServiceAreaBadge({ areaName, serviceAreaNames = [], className = '' }) {
  if (!areaName) return null;
  const { bg, border, text } = getServiceAreaPaletteEntry(areaName, serviceAreaNames);

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
