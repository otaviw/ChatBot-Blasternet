function InboxBackButton({
  onClick,
  label = 'Conversas',
  className = 'lg:hidden inbox-back-btn',
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      className={className}
      aria-label="Voltar à lista de conversas"
    >
      <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5">
        <path d="M19 12H5M12 19l-7-7 7-7" />
      </svg>
      {label}
    </button>
  );
}

export default InboxBackButton;
