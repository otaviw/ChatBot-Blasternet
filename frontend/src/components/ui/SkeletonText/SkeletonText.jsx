import LoadingSkeleton from '@/components/ui/LoadingSkeleton/LoadingSkeleton.jsx';
import './SkeletonText.css';

function SkeletonText({
  lines = 2,
  className = '',
  lineClassName = 'h-3 w-full',
  lastLineWidth = 'w-3/4',
}) {
  const safeLines = Math.max(1, Number(lines) || 1);

  return (
    <div className={['skeleton-text', className].filter(Boolean).join(' ')} aria-hidden="true">
      {Array.from({ length: safeLines }).map((_, index) => (
        <LoadingSkeleton
          key={`skeleton-text-line-${index}`}
          className={[
            lineClassName,
            index === safeLines - 1 && safeLines > 1 ? lastLineWidth : '',
          ]
            .filter(Boolean)
            .join(' ')}
        />
      ))}
    </div>
  );
}

export default SkeletonText;
