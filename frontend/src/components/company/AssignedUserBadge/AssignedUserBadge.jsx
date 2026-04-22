function AssignedUserBadge({ userName, className = '' }) {
  if (!userName) return null;

  return (
    <span
      className={`inline-flex max-w-full items-center gap-1 truncate rounded-md border px-1.5 py-0.5 text-[11px] font-semibold leading-tight ${className}`}
      style={{
        backgroundColor: '#ede9fe',
        borderColor: '#a78bfa',
        color: '#5b21b6',
      }}
      title={`Atribuído a: ${userName}`}
    >
      <svg
        width="10"
        height="10"
        viewBox="0 0 24 24"
        fill="currentColor"
        aria-hidden="true"
        style={{ flexShrink: 0 }}
      >
        <path d="M12 12c2.7 0 4.8-2.1 4.8-4.8S14.7 2.4 12 2.4 7.2 4.5 7.2 7.2 9.3 12 12 12zm0 2.4c-3.2 0-9.6 1.6-9.6 4.8v2.4h19.2v-2.4c0-3.2-6.4-4.8-9.6-4.8z" />
      </svg>
      {userName}
    </span>
  );
}

export default AssignedUserBadge;
