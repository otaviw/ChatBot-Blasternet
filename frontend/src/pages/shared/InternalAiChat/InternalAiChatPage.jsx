import './InternalAiChatPage.css';
import { useState } from 'react';
import Layout from '@/components/layout/Layout/Layout.jsx';
import usePageData from '@/hooks/usePageData';
import useLogout from '@/hooks/useLogout';
import PageHeader from '@/components/ui/PageHeader/PageHeader.jsx';

const formatDateTime = (value) => {
  const ts = new Date(value).getTime();
  if (!Number.isFinite(ts)) return '';
  return new Date(ts).toLocaleString('pt-BR');
};

function InternalAiChatPage() {
  const { data, loading, error } = usePageData('/me');
  const { logout } = useLogout();
  const [message, setMessage] = useState('');
  const [messages, setMessages] = useState([]);

  const user = data?.user ?? null;
  const role = String(user?.role ?? '').toLowerCase() === 'system_admin' ? 'admin' : 'company';
  const companyName = user?.company_name ?? '';

  const handleSend = async (event) => {
    event.preventDefault();
    const content = String(message ?? '').trim();
    if (!content) return;

    const now = new Date().toISOString();
    const userBubble = {
      id: `u-${Date.now()}`,
      sender: 'voce',
      text: content,
      created_at: now,
    };
    setMessages((previous) => [...previous, userBubble]);
    setMessage('');

    // Placeholder visual: a resposta real sera ligada a IA externa depois.
    const aiBubble = {
      id: `a-${Date.now()}`,
      sender: 'ia',
      text: 'Resposta de IA (placeholder visual). Integracao externa pendente.',
      created_at: new Date().toISOString(),
    };
    setMessages((previous) => [...previous, aiBubble]);
  };

  if (loading) {
    return (
      <Layout role={role} onLogout={logout}>
        <p className="text-sm text-[#737373]">Carregando chat IA...</p>
      </Layout>
    );
  }

  if (error || !data?.authenticated || !user) {
    return (
      <Layout>
        <p className="text-sm text-red-600">Nao foi possivel carregar o chat IA.</p>
      </Layout>
    );
  }

  return (
    <Layout role={role} companyName={role === 'company' ? companyName : undefined} onLogout={logout}>
      <PageHeader
        title="Chat interno com IA"
        subtitle="Apenas interface visual para futura integracao com IA externa."
      />

      <section className="internal-ai-chat">
        <header className="internal-ai-chat__toolbar">
          <p className="internal-ai-chat__hint">
            Interface simples, sem chamada de chatbot/API neste momento.
          </p>
        </header>

        <ul className="internal-ai-chat__messages">
          {!messages.length ? (
            <li className="internal-ai-chat__empty">
              Envie uma mensagem para iniciar o chat com a IA.
            </li>
          ) : null}
          {messages.map((item) => (
            <li
              key={item.id}
              className={`internal-ai-chat__bubble ${
                item.sender === 'voce'
                  ? 'internal-ai-chat__bubble--mine'
                  : 'internal-ai-chat__bubble--ia'
              }`}
            >
              <span className="internal-ai-chat__sender">
                {item.sender === 'voce' ? 'Voce' : 'IA'}
              </span>
              <p className="internal-ai-chat__text">{item.text}</p>
              <span className="internal-ai-chat__time">{formatDateTime(item.created_at)}</span>
            </li>
          ))}
        </ul>

        <form className="internal-ai-chat__composer" onSubmit={handleSend}>
          <textarea
            className="app-input internal-ai-chat__input"
            rows={3}
            value={message}
            onChange={(event) => setMessage(event.target.value)}
            placeholder="Digite sua mensagem..."
          />
          <div className="internal-ai-chat__actions">
            <span />
            <button type="submit" className="app-btn-primary" disabled={!message.trim()}>
              Enviar
            </button>
          </div>
        </form>
      </section>
    </Layout>
  );
}

export default InternalAiChatPage;

