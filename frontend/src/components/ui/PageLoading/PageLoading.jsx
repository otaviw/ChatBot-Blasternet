import LoadingSkeleton from '@/components/ui/LoadingSkeleton/LoadingSkeleton.jsx';
import LoadingSpinner from '@/components/ui/LoadingSpinner/LoadingSpinner.jsx';
import SkeletonCard from '@/components/ui/SkeletonCard/SkeletonCard.jsx';
import SkeletonText from '@/components/ui/SkeletonText/SkeletonText.jsx';

function PageLoading({
  loadingLabel = 'Carregando...',
  titleWidth = 'w-56',
  subtitleWidth = 'w-96 max-w-full',
  rows = 0,
  rowClassName = 'h-16 w-full',
  cards = 0,
  cardClassName = 'h-44 w-full',
  cardsGridClassName = 'grid grid-cols-1 md:grid-cols-2 gap-4',
  className = '',
}) {
  const safeRows = Math.max(0, Number(rows) || 0);
  const safeCards = Math.max(0, Number(cards) || 0);

  return (
    <div className={['space-y-4', className].filter(Boolean).join(' ')}>
      <LoadingSpinner label={loadingLabel} />
      <LoadingSkeleton className={`h-6 ${titleWidth}`.trim()} />
      <SkeletonText
        lines={1}
        lineClassName={`h-4 ${subtitleWidth}`.trim()}
        lastLineWidth={subtitleWidth}
        className="max-w-full"
      />

      {safeRows > 0 ? (
        <div className="space-y-3">
          {Array.from({ length: safeRows }).map((_, index) => (
            <LoadingSkeleton key={`page-loading-row-${index}`} className={rowClassName} />
          ))}
        </div>
      ) : null}

      {safeCards > 0 ? (
        <div className={cardsGridClassName}>
          {Array.from({ length: safeCards }).map((_, index) => (
            <SkeletonCard
              key={`page-loading-card-${index}`}
              className={cardClassName}
              lines={3}
            />
          ))}
        </div>
      ) : null}
    </div>
  );
}

export default PageLoading;
