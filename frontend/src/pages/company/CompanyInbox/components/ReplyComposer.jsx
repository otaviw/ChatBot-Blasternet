function ConfidenceBadge({ score }) {
  if (score === null || score === undefined) return null;

  let label, className;
  if (score > 0.7) {
    label = 'Alta confiança';
    className = 'inline-flex items-center gap-1 text-xs font-medium text-emerald-700 bg-emerald-50 border border-emerald-200 rounded-full px-2.5 py-0.5';
  } else if (score >= 0.4) {
    label = 'Revisar antes de enviar';
    className = 'inline-flex items-center gap-1 text-xs font-medium text-amber-700 bg-amber-50 border border-amber-200 rounded-full px-2.5 py-0.5';
  } else {
    label = 'Gerada sem contexto específico';
    className = 'inline-flex items-center gap-1 text-xs font-medium text-red-700 bg-red-50 border border-red-200 rounded-full px-2.5 py-0.5';
  }

  const dot = score > 0.7
    ? 'inline-block w-2 h-2 rounded-full bg-emerald-500'
    : score >= 0.4
    ? 'inline-block w-2 h-2 rounded-full bg-amber-500'
    : 'inline-block w-2 h-2 rounded-full bg-red-500';

  return (
    <span className={className}>
      <span className={dot} aria-hidden />
      {label}
    </span>
  );
}

function SuggestionFeedback({ feedbackState, onFeedback }) {
  if (feedbackState !== 'pending') return null;

  return (
    <div className="flex items-center gap-2 text-xs text-[#525252]">
      <span>Esta sugestão foi útil?</span>
      <button
        type="button"
        onClick={() => onFeedback(true)}
        className="hover:text-emerald-600 transition text-base leading-none"
        aria-label="Sim, foi útil"
        title="Útil"
      >
        👍
      </button>
      <button
        type="button"
        onClick={() => onFeedback(false)}
        className="hover:text-red-500 transition text-base leading-none"
        aria-label="Não foi útil"
        title="Não útil"
      >
        👎
      </button>
      <button
        type="button"
        onClick={() => onFeedback(null)}
        className="text-[#a3a3a3] hover:text-[#525252] transition leading-none ml-1"
        aria-label="Fechar"
        title="Fechar"
      >
        ×
      </button>
    </div>
  );
}

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
  aiConfidenceScore,
  aiSuggestionFeedbackState,
  onAiSuggestionFeedback,
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
          {canUseAiSuggestion && (
            <button
              type="button"
              onClick={onRequestAiSuggestion}
              disabled={manualBusy || aiSuggestionBusy}
              className="app-btn-secondary text-xs inbox-reply-action-btn"
              title="Gerar sugestão de resposta com IA"
            >
              {aiSuggestionBusy ? 'IA gerando...' : '✦ Sugerir com IA'}
            </button>
          )}
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

      {/* AI suggestion status row */}
      {(aiSuggestionBusy || aiSuggestionStatus || aiConfidenceScore !== null) && (
        <div className="flex flex-wrap items-center gap-2">
          {aiSuggestionBusy && (
            <p className="text-xs text-[#525252]">IA gerando resposta...</p>
          )}
          {!aiSuggestionBusy && aiSuggestionStatus && (
            <p className="text-xs text-green-700">{aiSuggestionStatus}</p>
          )}
          {!aiSuggestionBusy && aiConfidenceScore !== null && (
            <ConfidenceBadge score={aiConfidenceScore} />
          )}
        </div>
      )}

      {/* Feedback row */}
      {!aiSuggestionBusy && (
        <SuggestionFeedback
          feedbackState={aiSuggestionFeedbackState}
          onFeedback={(helpful) => onAiSuggestionFeedback?.(helpful)}
        />
      )}

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
          onKeyDown={(event) => {
            if (event.key === 'Enter' && !event.shiftKey) {
              event.preventDefault();
              if (!manualBusy && (manualText.trim() || manualImageFile)) {
                event.currentTarget.form.requestSubmit();
              }
            }
          }}
          rows={2}
          placeholder="Digite a resposta manual ou use um modelo..."
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
