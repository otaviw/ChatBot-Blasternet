function ReplyComposer({
  onSendManualReply,
  showTemplates,
  onToggleTemplates,
  quickReplies,
  onApplyQuickReply,
  onManualImageChange,
  manualImageFile,
  onRemoveManualImage,
  manualText,
  onManualTextChange,
  canUseAiSuggestion,
  onRequestAiSuggestion,
  aiSuggestionBusy,
  aiSuggestionStatus,
  aiSuggestionError,
  manualBusy,
  manualImagePreviewUrl,
  manualError,
}) {
  return (
    <form onSubmit={onSendManualReply} className="inbox-reply-form shrink-0 space-y-3">
      <div className="inbox-reply-actions">
        <div className="inbox-reply-template-wrap relative">
          <button
            type="button"
            onClick={onToggleTemplates}
            className="app-btn-secondary text-xs inbox-reply-action-btn"
          >
            Respostas rápidas v
          </button>

          {showTemplates && (
            <div className="absolute bottom-10 left-0 z-10 w-72 bg-white rounded-lg shadow-lg max-h-48 overflow-y-auto">
              {!quickReplies.length && (
                <p className="text-xs text-[#706f6c] p-3">Nenhum template cadastrado.</p>
              )}
              {quickReplies.map((reply) => (
                <button
                  key={reply.id}
                  type="button"
                  onClick={() => onApplyQuickReply(reply.text)}
                  className="w-full text-left px-3 py-2 hover:bg-[#f5f5f5] border-b border-[#eeeeee] last:border-0 text-[#171717]"
                >
                  <p className="text-xs font-medium">{reply.title}</p>
                  <p className="text-xs text-[#706f6c] truncate">{reply.text}</p>
                </button>
              ))}
            </div>
          )}
        </div>

        <div className="company-inbox-upload-row">
          <button
            type="button"
            onClick={onRequestAiSuggestion}
            disabled={manualBusy || aiSuggestionBusy || !canUseAiSuggestion}
            className="app-btn-secondary text-xs inbox-reply-action-btn"
            title={
              canUseAiSuggestion
                ? 'Gerar sugestao de resposta com IA'
                : 'Voce nao tem permissao para usar sugestao de IA'
            }
          >
            {aiSuggestionBusy ? 'IA gerando resposta...' : 'Sugerir resposta com IA'}
          </button>
          <label className="app-btn-secondary text-xs cursor-pointer inbox-reply-action-btn">
            Anexar arquivo
            <input
              type="file"
              name="file"
              accept="image/*,video/*,audio/*,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.zip,.webp"
              onChange={onManualImageChange}
              className="hidden"
            />
          </label>
          {manualImageFile ? (
            <button
              type="button"
              onClick={onRemoveManualImage}
              className="app-btn-danger text-xs"
            >
              Remover arquivo
            </button>
          ) : null}
        </div>
      </div>
      {aiSuggestionBusy ? <p className="text-xs text-[#525252]">IA gerando resposta...</p> : null}
      {aiSuggestionStatus ? <p className="text-xs text-green-700">{aiSuggestionStatus}</p> : null}
      {aiSuggestionError ? <p className="text-sm text-red-600">{aiSuggestionError}</p> : null}

      {manualImageFile ? (
        <div className="inbox-reply-attachment">
          <span className="inbox-reply-attachment-label">Arquivo selecionado</span>
          <span className="inbox-reply-attachment-name" title={manualImageFile.name}>
            {manualImageFile.name}
          </span>
        </div>
      ) : null}

      <div className="inbox-reply-compose">
        <textarea
          value={manualText}
          onChange={(event) => onManualTextChange(event.target.value)}
          rows={2}
          placeholder="Digite resposta manual ou use um template..."
          className="app-input inbox-reply-input"
        />
        <button
          type="submit"
          disabled={manualBusy || (!manualText.trim() && !manualImageFile)}
          className="app-btn-primary inbox-reply-submit"
        >
          {manualBusy ? 'Enviando...' : 'Enviar'}
        </button>
      </div>
      {manualImagePreviewUrl ? (
        <div className="company-inbox-image-preview space-y-2"> 
          {manualImageFile?.type?.startsWith('image/') ? (
            <img src={manualImagePreviewUrl} alt="Prévia" className="max-w-xs rounded" />
          ) : manualImageFile?.type?.startsWith('video/') ? (
            <video controls src={manualImagePreviewUrl} className="max-w-xs rounded">
              Prévia não disponível
            </video>
          ) : manualImageFile?.type?.startsWith('audio/') ? (
            <audio controls src={manualImagePreviewUrl} className="w-full max-w-md">
              Prévia não disponível
            </audio>
          ) : (
            <div className="p-3 bg-gray-100 rounded text-sm">
             {manualImageFile.name} <br />
              <span className="text-xs text-gray-500">Prévia indisponível (PDF/DOC/etc.)</span>
            </div>
          )}
        </div>
      ) : null}
      {manualError && <p className="text-sm text-red-600">{manualError}</p>}
    </form>
  );
}

export default ReplyComposer;
