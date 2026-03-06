import './SupportRequestPage.css';
import { useState } from 'react';
import Layout from '@/components/layout/Layout/Layout.jsx';
import usePageData from '@/hooks/usePageData';
import useLogout from '@/hooks/useLogout';
import api from '@/services/api';

function formatTicketNumber(value) {
  const number = Number.parseInt(String(value ?? ''), 10);
  if (!number || number < 0) {
    return '-';
  }

  return String(number).padStart(6, '0');
}

function SupportRequestPage() {
  const { data, loading, error } = usePageData('/dashboard');
  const { logout } = useLogout();
  const [subject, setSubject] = useState('');
  const [message, setMessage] = useState('');
  const [imageFiles, setImageFiles] = useState([]);
  const [imagePreviews, setImagePreviews] = useState([]);
  const [busy, setBusy] = useState(false);
  const [actionError, setActionError] = useState('');
  const [successTicket, setSuccessTicket] = useState(null);

  const handleImageChange = (e) => {
    const files = Array.from(e.target.files ?? []);
    if (!files.length) return;
    setImagePreviews((prev) => {
      prev.forEach((u) => URL.revokeObjectURL(u));
      return [];
    });
    const newFiles = [...imageFiles, ...files].slice(0, 5);
    setImageFiles(newFiles);
    setImagePreviews(newFiles.map((f) => URL.createObjectURL(f)));
    e.target.value = '';
  };

  const removeImage = (index) => {
    setImageFiles((prev) => prev.filter((_, i) => i !== index));
    setImagePreviews((prev) => {
      if (prev[index]) URL.revokeObjectURL(prev[index]);
      return prev.filter((_, i) => i !== index);
    });
  };

  const submitTicket = async (event) => {
    event.preventDefault();
    setBusy(true);
    setActionError('');
    setSuccessTicket(null);

    try {
      const formData = new FormData();
      formData.append('subject', subject.trim());
      formData.append('message', message.trim());
      imageFiles.forEach((file) => formData.append('images[]', file));

      const response = await api.post('/suporte/solicitacoes', formData);

      setSuccessTicket(response.data?.ticket ?? null);
      setSubject('');
      setMessage('');
      setImageFiles([]);
      imagePreviews.forEach((u) => URL.revokeObjectURL(u));
      setImagePreviews([]);
    } catch (err) {
      setActionError(err.response?.data?.message || 'Falha ao enviar solicitação de suporte.');
    } finally {
      setBusy(false);
    }
  };

  if (loading) {
    return (
      <Layout>
        <p className="text-sm text-[#64748b]">Carregando suporte...</p>
      </Layout>
    );
  }

  if (error || !data?.authenticated) {
    return (
      <Layout>
        <p className="text-sm text-red-600">Não foi possível carregar o módulo de suporte.</p>
      </Layout>
    );
  }

  const role = data.role === 'admin' ? 'admin' : 'company';
  const companyLabel = data.role === 'company'
    ? data.companyName ?? 'Empresa'
    : 'Sistema';

  return (
    <Layout
      role={role}
      companyName={data.role === 'company' ? companyLabel : undefined}
      onLogout={logout}
    >
      <h1 className="text-xl font-medium mb-2">Abrir solicitação de suporte</h1>
      <p className="text-sm text-[#64748b] mb-6">
        Envie um chamado para o time de suporte com o máximo de detalhes possível.
      </p>

      <section className="border border-[#e3e3e0] rounded-lg p-4 mb-6">
        <h2 className="font-medium mb-3">Dados do solicitante</h2>
        <ul className="text-sm space-y-1">
          <li>Nome: <strong>{data.user?.name ?? '-'}</strong></li>
          <li>Contato: <strong>{data.user?.email ?? '-'}</strong></li>
          <li>Empresa: <strong>{companyLabel}</strong></li>
        </ul>
      </section>

      <section className="border border-[#e3e3e0] rounded-lg p-4">
        <form onSubmit={submitTicket} className="space-y-4 max-w-3xl">
          <label className="block text-sm">
            Título da solicitação (assunto)
            <input
              type="text"
              value={subject}
              onChange={(event) => setSubject(event.target.value)}
              required
              maxLength={190}
              placeholder="Ex.: erro ao enviar resposta manual"
              className="mt-1 w-full rounded border border-[#d5d5d2] px-3 py-2 bg-white"
            />
          </label>

          <label className="block text-sm">
            Descrição completa do problema
            <textarea
              value={message}
              onChange={(event) => setMessage(event.target.value)}
              required
              rows={7}
              maxLength={8000}
              placeholder="Descreva o problema, quando acontece e o impacto no atendimento."
              className="mt-1 w-full rounded border border-[#d5d5d2] px-3 py-2 bg-white"
            />
          </label>

          <div className="block">
            <span className="text-sm font-medium text-[#525252]">Fotos (opcional)</span>
            <p className="text-xs text-[#737373] mb-2">
              Anexe até 5 imagens para ilustrar o problema (máx. 5MB cada)
            </p>
            <div className="flex flex-wrap gap-3 items-start">
              <label className="cursor-pointer px-4 py-2 rounded-lg border border-[#d5d5d2] bg-white hover:bg-[#fafafa] text-sm">
                Selecionar imagens
                <input
                  type="file"
                  accept="image/*"
                  multiple
                  onChange={handleImageChange}
                  className="hidden"
                />
              </label>
              {imagePreviews.map((url, i) => (
                <div key={i} className="relative inline-block">
                  <img
                    src={url}
                    alt={`Anexo ${i + 1}`}
                    className="w-20 h-20 object-cover rounded-lg border border-[#e5e5e5]"
                  />
                  <button
                    type="button"
                    onClick={() => removeImage(i)}
                    className="absolute -top-2 -right-2 w-6 h-6 rounded-full bg-red-500 text-white text-xs flex items-center justify-center hover:bg-red-600"
                  >
                    ×
                  </button>
                </div>
              ))}
            </div>
          </div>

          <button
            type="submit"
            disabled={busy}
            className="px-4 py-2 rounded bg-[#2563eb] text-white disabled:opacity-60"
          >
            {busy ? 'Enviando...' : 'Enviar solicitação'}
          </button>
        </form>

        {actionError && <p className="text-sm text-red-600 mt-3">{actionError}</p>}
        {successTicket && (
          <p className="text-sm text-green-700 mt-3">
            Solicitação criada com sucesso. Número #{formatTicketNumber(successTicket.ticket_number)}.
          </p>
        )}
      </section>
    </Layout>
  );
}

export default SupportRequestPage;

