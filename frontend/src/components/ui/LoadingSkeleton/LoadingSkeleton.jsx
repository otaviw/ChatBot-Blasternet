import './LoadingSkeleton.css';

/** @typedef {import('@/types/ui').LoadingSkeletonProps} LoadingSkeletonProps */

/** @param {LoadingSkeletonProps} props */
function LoadingSkeleton({ className = '' }) {
  const classes = ['loading-skeleton', className].filter(Boolean).join(' ');
  return <div className={classes} aria-hidden="true" />;
}

export function LoadingSkeletonLines({
  lines = 3,
  className = '',
  lineClassName = 'h-3 w-full',
}) {
  const safeLines = Math.max(1, Number(lines) || 1);

  return (
    <div className={['space-y-2', className].filter(Boolean).join(' ')} aria-hidden="true">
      {Array.from({ length: safeLines }).map((_, index) => (
        <LoadingSkeleton
          key={`line-${index}`}
          className={`${lineClassName} ${index === safeLines - 1 ? 'w-3/4' : ''}`.trim()}
        />
      ))}
    </div>
  );
}

export default LoadingSkeleton;
