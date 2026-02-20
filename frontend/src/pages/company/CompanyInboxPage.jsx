import React, { useEffect, useState } from 'react';
import Layout from '../../components/Layout';
import usePageData from '../../hooks/usePageData';
import useLogout from '../../hooks/useLogout';
import api from '../../lib/api';

function CompanyInboxPage() {
  const { data, loading, error } = usePageData('/minha-conta/conversas');
  const { logout } = useLogout();
  const [conversations, setConversations] = useState([]);
  const [selectedId, setSelectedId] = useState(null);
  const [detail, setDetail] = useState(null);
  const [detailLoading, setDetailLoading] = useState(false);
  const [detailError, setDetailError] = useState('');
  const [manualText, setManualText] = useState('');
  const [manualBusy, setManualBusy] = useState(false);
  const [manualError, setManualError] = useState('');
  const [actionBusy, setActionBusy] = useState(false);
  const [tagInput, setTagInput] = useState('');
  const [showTemplates, setShowTemplates] = useState(false);
  const [quickReplies, setQuickReplies] = useState([]);

  useEffect(() => {
    setConversations(data?.conversations ?? []);
  }, [data]);

  // Carrega templates ao montar
  useEffect(() => {
    api.get('/minha-conta/respostas-rapidas').then((r) => {
      setQuickReplies(r.data?.quick_replies ?? []);
    });
  }, []);

  const refreshConversations = async () => {
    const response = await api.get('/minha-conta/conversas');
    setConversations(response.data?.conversations ?? []);
  };

  const openConversation = async (conversationId) => {
    setSelectedId(conversationId);
    setDetailLoading(true);
    setDetailError('');
    setDetail(null);
    setShowTemplates(false);
    try {
      const response = await api.get(`/minha-conta/conversas/${conversationId}`);
      setDetail(response.data?.conversation ?? null);
    } catch (err) {
      setDetailError(err.response?.data?.message || 'Falha ao carregar conversa.');
    } finally {
      setDetailLoading(false);
    }
  };

  const assumeConversation = async () => {
    if (!detail?.id) return;
    setActionBusy(true);
    try {
      const response = await api.post(`/minha-conta/conversas/${detail.id}/assumir`);
      setDetail((prev) => ({ ...(prev ?? {}), ...response.data?.conversation }));
      await refreshConversations();
    } catch (err) {
      setDetailError(err.response?.data?.message || 'Falha ao assumir conversa.');
    } finally {
      setActionBusy(false);
    }
  };

  const releaseConversation = async () => {
    if (!detail?.id) return;
    setActionBusy(true);
    try {
      const response = await api.post(`/minha-conta/conversas/${detail.id}/soltar`);
      setDetail((prev) => ({ ...(prev ?? {}), ...response.data?.conversation }));
      await refreshConversations();
    } catch (err) {
      setDetailError(err.response?.data?.message || 'Falha ao soltar conversa.');
    } finally {
      setActionBusy(false);
    }
  };

  const closeConversation = async () => {
    if (!detail?.id) return;
    setActionBusy(true);
    try {
      const response = await api.post(`/minha-conta/conversas/${detail.id}/encerrar`);
      setDetail((prev) => ({ ...(prev ?? {}), ...response.data?.conversation }));
      await refreshConversations();
    } catch (err) {
      setDetailError(err.response?.data?.message || 'Falha ao encerrar conversa.');
    } finally {
      setActionBusy(false);
    }
  };

  const addTag = async (tag) => {
    if (!detail?.id || !tag.trim()) return;
    const currentTags = detail.tags ?? [];
    if (currentTags.includes(tag.toLowerCase())) return;

    const newTags = [...currentTags, tag.toLowerCase().trim()];
    try {
      await api.put(`/minha-conta/conversas/${detail.id}/tags`, { tags: newTags });
      setDetail((prev) => ({ ...(prev ?? {}), tags: newTags }));
      setTagInput('');
    } catch (err) {
      setDetailError('Falha ao adicionar tag.');
    }
  };

  const removeTag = async (tag) => {
    if (!detail?.id) return;
    const newTags = (detail.tags ?? []).filter((t) => t !== tag);
    try {
      await api.put(`/minha-conta/conversas/${detail.id}/tags`, { tags: newTags });
      setDetail((prev) => ({ ...(prev ?? {}), tags: newTags }));
    } catch (err) {
      setDetailError('Falha ao remover tag.');
    }
  };

  const sendManualReply = async (event) => {
    event.preventDefault();
    if (!detail?.id || !manualText.trim()) return;

    setManualBusy(true);
    setManualError('');
    try {
      const response = await api.post(`/minha-conta/conversas/${detail.id}/responder-manual`, {
        text: manualText.trim(),
        send_outbound: true,
      });
      const message = response.data?.message;
      setDetail((prev) => ({
        ...(prev ?? {}),
        ...response.data?.conversation,
        messages: [...(prev?.messages ?? []), message],
      }));
      setManualText('');
      await refreshConversations();
    } catch (err) {
      setManualError(err.response?.data?.message || 'Falha ao enviar resposta manual.');
    } finally {
      setManualBusy(false);
    }
  };

  if (loading) {
    return (
      <Layout role="company" onLogout={logout}>
        <p className="text-sm text-[#706f6c]">Carregando inbox...</p>
      </Layout>
    );
  }

  if (error || !data?.authenticated) {
    return (
      <Layout>
        <p className="text-sm text-red-600 dark:text-red-400">Nao foi possivel carregar a inbox.</p>
      </Layout>
    );
  }

  return (
    <Layout role="company" onLogout={logout}>
      <h1 className="text-xl font-medium mb-4">Inbox da empresa</h1>
      <div className="grid grid-cols-1 md:grid-cols-2 gap-4">

        {/* Lista de conversas */}
        <section className="border border-[#e3e3e0] dark:border-[#3E3E3A] rounded-lg p-4">
          <h2 className="font-medium mb-3">Conversas</h2>
          {!conversations.length && (
            <p className="text-sm text-[#706f6c]">Nenhuma conversa.</p>
          )}
          <ul className="space-y-2 text-sm">
            {conversations.map((conv) => (
              <li key={conv.id}>
                <button
                  type="button"
                  onClick={() => openConversation(conv.id)}
                  className={`w-full text-left px-3 py-2 rounded border ${
                    selectedId === conv.id
                      ? 'border-[#f53003]'
                      : conv.status === 'closed'
                      ? 'border-[#e3e3e0] opacity-50'
                      : 'border-[#e3e3e0]'
                  }`}
                >
                  <div>{conv.customer_phone} - {conv.status} ({conv.messages_count ?? 0} msg)</div>
                  <div className="text-xs text-[#706f6c] mt-0.5">
                    {conv.status === 'closed'
                      ? 'encerrada'
                      : conv.handling_mode === 'manual'
                      ? 'manual'
                      : 'bot'}
                    {(conv.tags ?? []).length > 0 && (
                      <span className="ml-2">{conv.tags.join(', ')}</span>
                    )}
                  </div>
                </button>
              </li>
            ))}
          </ul>
        </section>

        {/* Detalhe */}
        <section className="border border-[#e3e3e0] dark:border-[#3E3E3A] rounded-lg p-4">
          <h2 className="font-medium mb-3">Mensagens</h2>
          {detailLoading && <p className="text-sm text-[#706f6c]">Carregando conversa...</p>}
          {detailError && <p className="text-sm text-red-600">{detailError}</p>}
          {!detailLoading && !detail && !detailError && (
            <p className="text-sm text-[#706f6c]">Selecione uma conversa.</p>
          )}
          {!!detail && (
            <>
              {/* Modo e operador */}
              <div className="mb-3 text-xs text-[#706f6c]">
                Modo: <strong>{detail.handling_mode === 'manual' ? 'Manual' : 'Bot'}</strong>{' '}
                {detail.assigned_user ? `| Assumida por: ${detail.assigned_user.name}` : ''}
              </div>

              {/* Ações */}
              <div className="flex gap-2 mb-3">
                <button
                  type="button"
                  onClick={assumeConversation}
                  disabled={actionBusy}
                  className="px-3 py-1 text-sm rounded border border-[#d5d5d2]"
                >
                  Assumir
                </button>
                <button
                  type="button"
                  onClick={releaseConversation}
                  disabled={actionBusy}
                  className="px-3 py-1 text-sm rounded border border-[#d5d5d2]"
                >
                  Soltar para bot
                </button>
                <button
                  type="button"
                  onClick={closeConversation}
                  disabled={actionBusy || detail?.status === 'closed'}
                  className="px-3 py-1 text-sm rounded border border-red-300 text-red-700 disabled:opacity-50"
                >
                  Encerrar
                </button>
              </div>

              {/* Tags */}
              <div className="mb-3">
                <p className="text-xs text-[#706f6c] mb-1">Tags</p>
                <div className="flex flex-wrap gap-1 mb-2">
                  {(detail.tags ?? []).length === 0 && (
                    <span className="text-xs text-[#706f6c]">Nenhuma tag.</span>
                  )}
                  {(detail.tags ?? []).map((tag) => (
                    <span
                      key={tag}
                      className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-[#f0f0ef] dark:bg-[#2a2a28] text-xs"
                    >
                      {tag}
                      <button
                        type="button"
                        onClick={() => removeTag(tag)}
                        className="text-[#706f6c] hover:text-red-600"
                      >
                        ×
                      </button>
                    </span>
                  ))}
                </div>
                <div className="flex gap-2">
                  <input
                    type="text"
                    value={tagInput}
                    onChange={(e) => setTagInput(e.target.value)}
                    onKeyDown={(e) => e.key === 'Enter' && (e.preventDefault(), addTag(tagInput))}
                    placeholder="Nova tag..."
                    className="flex-1 rounded border border-[#d5d5d2] px-2 py-1 text-xs bg-white dark:bg-[#161615]"
                  />
                  <button
                    type="button"
                    onClick={() => addTag(tagInput)}
                    className="px-3 py-1 text-xs rounded border border-[#d5d5d2]"
                  >
                    Adicionar
                  </button>
                </div>
              </div>

              {/* Mensagens */}
              <ul className="space-y-2 text-sm mb-3 max-h-80 overflow-y-auto pr-1">
                {(detail.messages ?? []).map((msg) => (
                  <li key={msg.id} className="border border-[#e3e3e0] rounded p-2">
                    <strong>{msg.direction === 'in' ? 'Cliente' : 'Atendente/Bot'}:</strong> {msg.text}
                  </li>
                ))}
              </ul>

              {/* Resposta manual com templates */}
              <form onSubmit={sendManualReply} className="space-y-2">

                {/* Botão de templates */}
                <div className="relative">
                  <button
                    type="button"
                    onClick={() => setShowTemplates((p) => !p)}
                    className="px-3 py-1 text-xs rounded border border-[#d5d5d2]"
                  >
                    Respostas rápidas ▾
                  </button>

                  {showTemplates && (
                    <div className="absolute bottom-8 left-0 z-10 w-72 bg-white dark:bg-[#161615] border border-[#e3e3e0] dark:border-[#3E3E3A] rounded-lg shadow-lg max-h-48 overflow-y-auto">
                      {!quickReplies.length && (
                        <p className="text-xs text-[#706f6c] p-3">Nenhum template cadastrado.</p>
                      )}
                      {quickReplies.map((reply) => (
                        <button
                          key={reply.id}
                          type="button"
                          onClick={() => {
                            setManualText(reply.text);
                            setShowTemplates(false);
                          }}
                          className="w-full text-left px-3 py-2 hover:bg-[#f5f5f3] dark:hover:bg-[#2a2a28] border-b border-[#e3e3e0] dark:border-[#3E3E3A] last:border-0"
                        >
                          <p className="text-xs font-medium">{reply.title}</p>
                          <p className="text-xs text-[#706f6c] truncate">{reply.text}</p>
                        </button>
                      ))}
                    </div>
                  )}
                </div>

                <textarea
                  value={manualText}
                  onChange={(e) => setManualText(e.target.value)}
                  rows={3}
                  placeholder="Digite resposta manual ou use um template..."
                  className="w-full rounded border border-[#d5d5d2] px-3 py-2 bg-white dark:bg-[#161615] text-sm"
                />
                <button
                  type="submit"
                  disabled={manualBusy}
                  className="px-3 py-1.5 text-sm rounded bg-[#f53003] text-white disabled:opacity-60"
                >
                  {manualBusy ? 'Enviando...' : 'Enviar resposta manual'}
                </button>
                {manualError && <p className="text-sm text-red-600">{manualError}</p>}
              </form>
            </>
          )}
        </section>

      </div>
    </Layout>
  );
}

export default CompanyInboxPage;