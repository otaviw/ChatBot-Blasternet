function InternalChatComposer({
  messageFile,
  messageFilePreviewUrl,
  messageText,
  onClearFile,
  onMessageFileChange,
  onMessageTextChange,
  onSubmit,
  sendBusy,
  sendError,
}) {
  return (
    <form className="internal-chat-composer" onSubmit={onSubmit}>
      <div className="internal-chat-composer-actions">
        <label className="app-btn-secondary internal-chat-file-btn">
          Anexar arquivo
          <input type="file" className="hidden" onChange={onMessageFileChange} />
        </label>
        {messageFile ? (
          <button type="button" className="app-btn-danger" onClick={onClearFile}>
            Remover arquivo
          </button>
        ) : null}
      </div>

      {messageFile ? (
        <div className="internal-chat-file-pill">
          <span>{messageFile.name}</span>
        </div>
      ) : null}

      {messageFilePreviewUrl ? (
        <div className="internal-chat-image-preview">
          <img src={messageFilePreviewUrl} alt="Previa do anexo" />
        </div>
      ) : null}

      <div className="internal-chat-composer-row">
        <textarea
          className="app-input internal-chat-textarea"
          value={messageText}
          onChange={(event) => onMessageTextChange(event.target.value)}
          placeholder="Digite sua mensagem..."
          rows={2}
        />
        <button
          type="submit"
          className="app-btn-primary internal-chat-send-btn"
          disabled={sendBusy || (!messageText.trim() && !messageFile)}
        >
          {sendBusy ? 'Enviando...' : 'Enviar'}
        </button>
      </div>

      {sendError ? <p className="internal-chat-error-inline">{sendError}</p> : null}
    </form>
  );
}

export default InternalChatComposer;
