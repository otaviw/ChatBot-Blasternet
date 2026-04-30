import LoadingSkeleton from '@/components/ui/LoadingSkeleton/LoadingSkeleton.jsx';
import SkeletonText from '@/components/ui/SkeletonText/SkeletonText.jsx';
import './SkeletonCard.css';

function SkeletonCard({
  className = '',
  titleWidth = 'w-40',
  lines = 3,
  footerWidth = 'w-24',
  bodyLineClassName = 'h-3 w-full',
}) {
  return (
    <div className={['skeleton-card', className].filter(Boolean).join(' ')} aria-hidden="true">
      <LoadingSkeleton className={`h-5 ${titleWidth}`.trim()} />
      <SkeletonText lines={lines} lineClassName={bodyLineClassName} />
      <LoadingSkeleton className={`h-3 ${footerWidth}`.trim()} />
    </div>
  );
}

export default SkeletonCard;

