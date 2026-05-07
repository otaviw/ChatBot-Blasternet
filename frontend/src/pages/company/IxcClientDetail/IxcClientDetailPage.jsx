import { useEffect, useMemo, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import Layout from '@/components/layout/Layout/Layout.jsx';
import useLogout from '@/hooks/useLogout';
import usePermissions from '@/hooks/usePermissions';
import { PERM } from '@/constants/permissions';
import {
  downloadIxcInvoice,
  getIxcClient,
  getIxcInvoiceDetail,
  listIxcClientInvoices,
  sendIxcInvoiceEmail,
  sendIxcInvoiceSms,
} from '@/services/ixcService';

function IxcClientDetailPage() {
  const { clientId = '' } = useParams();
  const navigate = useNavigate();
  const { logout } = useLogout();
  const { can } = usePermissions();
  const canViewInvoices = can(PERM.IXC_INVOICES_VIEW);
  const canDownloadInvoices = can(PERM.IXC_INVOICES_DOWNLOAD);
  const canSendEmailInvoices = can(PERM.IXC_INVOICES_SEND_EMAIL);
  const canSendSmsInvoices = can(PERM.IXC_INVOICES_SEND_SMS);

  const [loadingClient, setLoadingClient] = useState(false);
  const [loadingInvoices, setLoadingInvoices] = useState(false);
  const [error, setError] = useState('');
  const [client, setClient] = useState(null);
  const [invoices, setInvoices] = useState([]);
  const [invoicePagination, setInvoicePagination] = useState({ page: 1, per_page: 30, total: 0, has_next: false });
  const [status, setStatus] = useState('all');
  const [dateFrom, setDateFrom] = useState('');
  const [dateTo, setDateTo] = useState('');
  const [page, setPage] = useState(1);
  const [invoiceActionBusyId, setInvoiceActionBusyId] = useState(null);
  const [selectedInvoice, setSelectedInvoice] = useState(null);
  const [detailBusy, setDetailBusy] = useState(false);
  const [actionFeedback, setActionFeedback] = useState({ type: '', message: '' });
  const [sendModal, setSendModal] = useState({ type: '', invoice: null });
  const [sendTarget, setSendTarget] = useState('');
  const [sendBusy, setSendBusy] = useState(false);
  const [sendModalMessage, setSendModalMessage] = useState({ type: '', message: '' });

  const canPrev = useMemo(() => page > 1 && !loadingInvoices, [page, loadingInvoices]);
  const canNext = useMemo(() => Boolean(invoicePagination?.has_next) && !loadingInvoices, [invoicePagination?.has_next, loadingInvoices]);

  useEffect(() => {
    let canceled = false;
    setLoadingClient(true);
    setError('');

    getIxcClient(clientId)
      .then((data) => {
        if (canceled) return;
        setClient(data?.client ?? null);
      })
      .catch((err) => {
        if (canceled) return;
        setError(err?.message || 'Falha ao carregar cliente.');
      })
      .finally(() => {
        if (!canceled) setLoadingClient(false);
      });

    return () => {
      canceled = true;
    };
  }, [clientId]);

  useEffect(() => {
    if (!canViewInvoices) return;
    let canceled = false;
    setLoadingInvoices(true);
    setError('');

    listIxcClientInvoices(clientId, {
      status,
      date_from: dateFrom || undefined,
      date_to: dateTo || undefined,
      page,
      per_page: 30,
    })
      .then((data) => {
        if (canceled) return;
        setInvoices(Array.isArray(data?.items) ? data.items : []);
        setInvoicePagination(data?.pagination ?? { page: 1, per_page: 30, total: 0, has_next: false });
      })
      .catch((err) => {
        if (canceled) return;
        setInvoices([]);
        setError(err?.message || 'Falha ao carregar boletos.');
      })
      .finally(() => {
        if (!canceled) setLoadingInvoices(false);
      });

    return () => {
      canceled = true;
    };
  }, [canViewInvoices, clientId, dateFrom, dateTo, page, status]);

  async function readBlobErrorMessage(blobData) {
    if (!(blobData instanceof Blob)) {
      return '';
    }

    const type = String(blobData.type ?? '').toLowerCase();
    if (!(type.includes('json') || type.includes('text'))) {
      return '';
    }

    try {
      const text = await blobData.text();
      if (!text) {
        return '';
      }
      const parsed = JSON.parse(text);
      return String(parsed?.message || parsed?.error || '').trim();
    } catch {
      return '';
    }
  }

  return (
    <Layout role="company" onLogout={logout}>
      <button type="button" className="app-btn-secondary mb-4" onClick={() => navigate('/minha-conta/ixc/clientes')}>
        Voltar para clientes
      </button>

      <h1 className="app-page-title">Cliente IXC #{clientId}</h1>
      <p className="app-page-subtitle mb-4">Detalhes do cliente e boletos (somente leitura na M3).</p>

      {error ? <p className="text-sm text-red-600 mb-3">{error}</p> : null}
      {actionFeedback.message ? (
        <p className={`text-sm mb-3 ${actionFeedback.type === 'error' ? 'text-red-600' : 'text-emerald-700'}`}>
          {actionFeedback.message}
        </p>
      ) : null}

      <section className="app-panel mb-6">
        {loadingClient ? (
          <p className="text-sm text-[#525252]">Carregando dados do cliente...</p>
        ) : !client ? (
          <p className="text-sm text-[#525252]">Cliente não encontrado.</p>
        ) : (
          <div className="grid grid-cols-1 md:grid-cols-2 gap-2 text-sm">
            <p><strong>Razão:</strong> {client.razao || '-'}</p>
            <p><strong>Fantasia:</strong> {client.fantasia || '-'}</p>
            <p><strong>Documento:</strong> {client.cpf_cnpj || '-'}</p>
            <p><strong>E-mail:</strong> {client.email || '-'}</p>
            <p><strong>Celular:</strong> {client.telefone_celular || '-'}</p>
            <p><strong>Ativo:</strong> {client.ativo || '-'}</p>
          </div>
        )}
      </section>

      <section className="app-panel">
        <h2 className="font-medium mb-3">Boletos</h2>
        {!canViewInvoices ? (
          <p className="text-sm text-[#525252]">Seu usuário não possui permissão para visualizar boletos.</p>
        ) : (
          <>
            <div className="grid grid-cols-1 md:grid-cols-4 gap-2 mb-4">
              <label className="text-sm">
                Status
                <select value={status} onChange={(event) => { setPage(1); setStatus(event.target.value); }} className="app-input">
                  <option value="all">Todos</option>
                  <option value="open">Abertos</option>
                  <option value="paid">Pagos</option>
                  <option value="closed">Fechados</option>
                </select>
              </label>
              <label className="text-sm">
                Vencimento de
                <input type="date" value={dateFrom} onChange={(event) => { setPage(1); setDateFrom(event.target.value); }} className="app-input" />
              </label>
              <label className="text-sm">
                Vencimento até
                <input type="date" value={dateTo} onChange={(event) => { setPage(1); setDateTo(event.target.value); }} className="app-input" />
              </label>
            </div>

            {loadingInvoices ? <p className="text-sm text-[#525252]">Carregando boletos...</p> : null}
            {!loadingInvoices && invoices.length === 0 ? <p className="text-sm text-[#525252]">Nenhum boleto encontrado.</p> : null}

            {!loadingInvoices && invoices.length > 0 ? (
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead>
                    <tr className="text-left border-b border-[#e5e5e5]">
                      <th className="py-2 pr-2">ID</th>
                      <th className="py-2 pr-2">Status</th>
                      <th className="py-2 pr-2">Vencimento</th>
                      <th className="py-2 pr-2">Valor</th>
                      <th className="py-2 pr-2">Documento</th>
                      <th className="py-2 pr-2">Ações</th>
                    </tr>
                  </thead>
                  <tbody>
                    {invoices.map((invoice) => (
                      <tr key={invoice.id} className="border-b border-[#f0f0f0]">
                        <td className="py-2 pr-2">{invoice.id}</td>
                        <td className="py-2 pr-2">{invoice.status || '-'}</td>
                        <td className="py-2 pr-2">{invoice.data_vencimento || '-'}</td>
                        <td className="py-2 pr-2">{invoice.valor || '-'}</td>
                        <td className="py-2 pr-2">{invoice.documento || '-'}</td>
                        <td className="py-2 pr-2">
                          <div className="flex gap-2">
                            <button
                              type="button"
                              className="app-btn-secondary text-xs px-2 py-1"
                              disabled={detailBusy}
                              onClick={async () => {
                                setDetailBusy(true);
                                setActionFeedback({ type: '', message: '' });
                                try {
                                  const detail = await getIxcInvoiceDetail(clientId, invoice.id);
                                  if (!detail?.item) {
                                    throw new Error('Nao foi possivel consultar os detalhes do boleto na IXC.');
                                  }
                                  setSelectedInvoice(detail.item);
                                } catch (err) {
                                  setActionFeedback({ type: 'error', message: err?.message || 'Falha ao consultar boleto.' });
                                } finally {
                                  setDetailBusy(false);
                                }
                              }}
                            >
                              Consultar
                            </button>
                            <button
                              type="button"
                              className="app-btn-secondary text-xs px-2 py-1"
                              disabled={!canDownloadInvoices || invoiceActionBusyId === invoice.id}
                              onClick={async () => {
                                setInvoiceActionBusyId(invoice.id);
                                setActionFeedback({ type: '', message: '' });
                                try {
                                  const response = await downloadIxcInvoice(clientId, invoice.id);
                                  const blob = response?.data;
                                  const contentType = String(response?.headers?.['content-type'] ?? 'application/pdf');
                                  const disposition = String(response?.headers?.['content-disposition'] ?? '');
                                  const match = disposition.match(/filename=\"?([^\";]+)\"?/i);
                                  const fallbackDate = String(invoice?.data_vencimento ?? '').replace(/[^0-9]/g, '') || 'arquivo';
                                  const filename = match?.[1] || `boleto_${invoice.id}_${fallbackDate}.pdf`;

                                  const downloadBlob = blob instanceof Blob ? blob : new Blob([blob], { type: contentType });
                                  const errorMessage = await readBlobErrorMessage(downloadBlob);
                                  if (errorMessage) {
                                    throw new Error(errorMessage);
                                  }
                                  const url = URL.createObjectURL(downloadBlob);
                                  const anchor = document.createElement('a');
                                  anchor.href = url;
                                  anchor.download = filename;
                                  document.body.appendChild(anchor);
                                  anchor.click();
                                  anchor.remove();
                                  URL.revokeObjectURL(url);
                                  setActionFeedback({ type: 'success', message: `Boleto ${invoice.id} baixado com sucesso.` });
                                } catch (err) {
                                  setActionFeedback({ type: 'error', message: err?.message || 'Falha ao baixar boleto.' });
                                } finally {
                                  setInvoiceActionBusyId(null);
                                }
                              }}
                            >
                              {invoiceActionBusyId === invoice.id ? 'Baixando...' : 'Baixar'}
                            </button>
                            <button
                              type="button"
                              className="app-btn-secondary text-xs px-2 py-1"
                              disabled={!canSendEmailInvoices}
                              onClick={() => {
                                setSendModal({ type: 'email', invoice });
                                setSendTarget(String(client?.email ?? '').trim());
                                setSendModalMessage({ type: '', message: '' });
                              }}
                            >
                              Enviar e-mail
                            </button>
                            <button
                              type="button"
                              className="app-btn-secondary text-xs px-2 py-1"
                              disabled={!canSendSmsInvoices}
                              onClick={() => {
                                setSendModal({ type: 'sms', invoice });
                                setSendTarget(String(client?.telefone_celular ?? '').trim());
                                setSendModalMessage({ type: '', message: '' });
                              }}
                            >
                              Enviar SMS
                            </button>
                          </div>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            ) : null}

            <div className="mt-4 flex items-center gap-2">
              <button type="button" className="app-btn-secondary" disabled={!canPrev} onClick={() => setPage((prev) => prev - 1)}>
                Anterior
              </button>
              <button type="button" className="app-btn-secondary" disabled={!canNext} onClick={() => setPage((prev) => prev + 1)}>
                Próxima
              </button>
              <span className="text-xs text-[#737373]">
                Página {invoicePagination?.page || page} - total {invoicePagination?.total ?? 0}
              </span>
            </div>
          </>
        )}
      </section>

      {selectedInvoice ? (
        <div
          className="fixed inset-0 z-50 bg-black/40 flex items-center justify-center p-4"
          onClick={() => setSelectedInvoice(null)}
        >
          <section className="app-panel w-full max-w-2xl" onClick={(event) => event.stopPropagation()}>
            <div className="flex items-center justify-between mb-3">
              <h2 className="font-medium">Detalhe do boleto #{selectedInvoice.id}</h2>
              <button type="button" className="app-btn-secondary" onClick={() => setSelectedInvoice(null)}>
                Fechar
              </button>
            </div>
            <div className="grid grid-cols-1 md:grid-cols-2 gap-2 text-sm">
              <p><strong>Status:</strong> {selectedInvoice.status || '-'}</p>
              <p><strong>Valor:</strong> {selectedInvoice.valor || '-'}</p>
              <p><strong>Vencimento:</strong> {selectedInvoice.data_vencimento || '-'}</p>
              <p><strong>Documento:</strong> {selectedInvoice.documento || '-'}</p>
              <p className="md:col-span-2"><strong>Linha digitável:</strong> {selectedInvoice.linha_digitavel || '-'}</p>
            </div>
          </section>
        </div>
      ) : null}

      {sendModal?.invoice ? (
        <section className="app-panel mt-6">
          <div className="flex items-center justify-between mb-3">
            <h2 className="font-medium">
              {sendModal.type === 'email' ? 'Enviar boleto por e-mail' : 'Enviar boleto por SMS'} #{sendModal.invoice.id}
            </h2>
            <button
              type="button"
              className="app-btn-secondary"
              onClick={() => {
                if (sendBusy) return;
                setSendModal({ type: '', invoice: null });
                setSendTarget('');
                setSendModalMessage({ type: '', message: '' });
              }}
            >
              Fechar
            </button>
          </div>

          <label className="text-sm block mb-3">
            {sendModal.type === 'email' ? 'E-mail de destino' : 'Telefone de destino'}
            <input
              type={sendModal.type === 'email' ? 'email' : 'text'}
              value={sendTarget}
              onChange={(event) => setSendTarget(event.target.value)}
              className="app-input"
              placeholder={sendModal.type === 'email' ? 'cliente@dominio.com' : '5511999999999'}
            />
          </label>

          {sendModalMessage.message ? (
            <p className={`text-sm mb-3 ${sendModalMessage.type === 'error' ? 'text-red-600' : 'text-emerald-700'}`}>
              {sendModalMessage.message}
            </p>
          ) : null}

          <button
            type="button"
            className="app-btn-primary"
            disabled={sendBusy || String(sendTarget ?? '').trim() === ''}
            onClick={async () => {
              setSendBusy(true);
              setActionFeedback({ type: '', message: '' });
              setSendModalMessage({ type: '', message: '' });
              try {
                if (sendModal.type === 'email') {
                  const response = await sendIxcInvoiceEmail(clientId, sendModal.invoice.id, String(sendTarget).trim());
                  const message = response?.message || 'Boleto enviado por e-mail com sucesso.';
                  setActionFeedback({ type: 'success', message });
                  setSendModalMessage({ type: 'success', message });
                } else {
                  const response = await sendIxcInvoiceSms(clientId, sendModal.invoice.id, String(sendTarget).trim());
                  const message = response?.message || 'Boleto enviado por SMS com sucesso.';
                  setActionFeedback({ type: 'success', message });
                  setSendModalMessage({ type: 'success', message });
                }
                setSendModal({ type: '', invoice: null });
                setSendTarget('');
              } catch (err) {
                const message = err?.message || 'Falha ao enviar boleto.';
                setActionFeedback({ type: 'error', message });
                setSendModalMessage({ type: 'error', message });
              } finally {
                setSendBusy(false);
              }
            }}
          >
            {sendBusy ? 'Enviando...' : 'Confirmar envio'}
          </button>
        </section>
      ) : null}
    </Layout>
  );
}

export default IxcClientDetailPage;
