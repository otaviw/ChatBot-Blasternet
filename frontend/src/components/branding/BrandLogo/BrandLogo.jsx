import useBrand from '@/hooks/useBrand';

function readString(value) {
  return String(value ?? '').trim();
}

export default function BrandLogo({
  fallback = null,
  alt = '',
  className = '',
  imgClassName = 'h-6 w-auto object-contain',
}) {
  const brand = useBrand();
  const brandName = readString(brand?.name) || 'Blasternet ChatBot';
  const brandLogo = readString(brand?.logo);

  if (brandLogo) {
    return <img src={brandLogo} alt={alt || brandName} className={imgClassName} />;
  }

  if (fallback !== null) {
    return <span className={className}>{fallback}</span>;
  }

  return <span className={className}>{brandName}</span>;
}

