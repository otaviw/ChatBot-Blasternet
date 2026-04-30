import LoadingSkeleton from '@/components/ui/LoadingSkeleton/LoadingSkeleton.jsx';
import './SkeletonConversationItem.css';

function SkeletonConversationItem({ className = '', showPreview = true }) {
  return (
    <div className={['skeleton-conversation-item', className].filter(Boolean).join(' ')} aria-hidden="true">
      <div className="skeleton-conversation-item__row">
        <LoadingSkeleton className="h-4 w-8/12" />
        <LoadingSkeleton className="h-3 w-12" />
      </div>
      <div className="skeleton-conversation-item__row">
        <LoadingSkeleton className="h-3 w-6/12" />
        <LoadingSkeleton className="h-4 w-10 rounded-full" />
      </div>
      {showPreview ? <LoadingSkeleton className="h-3 w-11/12" /> : null}
    </div>
  );
}

export default SkeletonConversationItem;

