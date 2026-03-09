function InboxBackButton({
  onClick,
  label = 'Voltar às conversas',
  className = 'lg:hidden inbox-back-btn',
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      className={className}
    >
      <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
        <path d="M19 12H5M12 19l-7-7 7-7" />
      </svg>
      {label}
    </button>
  );
}

export default InboxBackButton;
