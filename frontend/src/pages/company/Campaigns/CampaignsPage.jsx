import './CampaignsPage.css';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import CampaignForm from '@/components/sections/campaigns/CampaignForm/CampaignForm.jsx';
import Layout from '@/components/layout/Layout/Layout.jsx';
import { REALTIME_EVENTS } from '@/constants/realtimeEvents';
import EmptyState from '@/components/ui/EmptyState/EmptyState.jsx';
import ErrorMessage from '@/components/ui/ErrorMessage/ErrorMessage.jsx';
import LoadingSpinner from '@/components/ui/LoadingSpinner/LoadingSpinner.jsx';
import useLogout from '@/hooks/useLogout';
import usePageData from '@/hooks/usePageData';
import useWhatsAppTemplates from '@/pages/company/CompanyInbox/hooks/useWhatsAppTemplates';
import api from '@/services/api';
import realtimeClient from '@/services/realtimeClient';
import { showError, showSuccess } from '@/services/toastService';

async function fetchAllCompanyContacts() {
  const contacts = [];
  let page = 1;
  let lastPage = 1;

  do {
    const response = await api.get('/minha-conta/contatos', { params: { page } });
    const payload = response?.data ?? {};

    if (Array.isArray(payload?.data)) {
      contacts.push(...payload.data);
    }

    const parsedLastPage = Number(payload?.last_page ?? 1);
    lastPage = Number.isFinite(parsedLastPage) && parsedLastPage > 0 ? parsedLastPage : 1;
    page += 1;
  } while (page <= lastPage);

  return contacts;
}

const formatDate = (value) => {
  if (!value) return '-';
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return '-';
  return date.toLocaleString('pt-BR');
};

const readPositiveInt = (...values) => {
  for (const value of values) {
    const parsed = Number.parseInt(String(value ?? ''), 10);
    if (Number.isFinite(parsed) && parsed > 0) {
      return parsed;
    }
  }

  return null;
};

const readNonNegativeInt = (...values) => {
  for (const value of values) {
    const parsed = Number.parseInt(String(value ?? ''), 10);
    if (Number.isFinite(parsed) && parsed >= 0) {
      return parsed;
    }
  }

  return null;
};

const toDisplayCount = (value) => readNonNegativeInt(value) ?? 0;

const parseCampaignRealtimeUpdate = (payload) => {
  if (!payload || typeof payload !== 'object') {
    return null;
  }

  const campaignId = readPositiveInt(payload?.campaignId, payload?.campaign_id, payload?.id);
  if (campaignId === null) {
    return null;
  }

  const status = String(payload?.status ?? '').trim();

  return {
    id: campaignId,
    status: status || null,
    sent_count: readNonNegativeInt(
      payload?.sentCount,
      payload?.sent_count,
      payload?.counters?.sent,
      payload?.counters?.sent_count
    ),
    failed_count: readNonNegativeInt(
      payload?.failedCount,
      payload?.failed_count,
      payload?.counters?.failed,
      payload?.counters?.failed_count
    ),
    skipped_count: readNonNegativeInt(
      payload?.skippedCount,
      payload?.skipped_count,
      payload?.ignoredCount,
      payload?.ignored_count,
      payload?.counters?.skipped,
      payload?.counters?.skipped_count
    ),
    pending_count: readNonNegativeInt(
      payload?.pendingCount,
      payload?.pending_count,
      payload?.counters?.pending,
      payload?.counters?.pending_count
    ),
  };
};

function CampaignsPage() {
  const { data, loading: meLoading, error: meError } = usePageData('/me');
  const { logout } = useLogout();
  const { templates, templatesLoading, templatesError, loadTemplates } = useWhatsAppTemplates();

  const [campaigns, setCampaigns] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [createBusy, setCreateBusy] = useState(false);
  const [startingId, setStartingId] = useState(null);
  const [isCreateModalOpen, setIsCreateModalOpen] = useState(false);
  const [modalError, setModalError] = useState('');
  const [contacts, setContacts] = useState([]);
  const [contactsLoading, setContactsLoading] = useState(false);
  const [contactsError, setContactsError] = useState('');
  const [contactsImportBusy, setContactsImportBusy] = useState(false);
  const [contactsImportError, setContactsImportError] = useState('');
  const campaignsRef = useRef([]);
  const lastRealtimeUpdateAtRef = useRef(0);

  const loadCampaigns = useCallback(async ({ silent = false } = {}) => {
    if (!silent) {
      setLoading(true);
      setError('');
    }

    try {
      const response = await api.get('/minha-conta/campanhas');
      const items = Array.isArray(response?.data?.campaigns) ? response.data.campaigns : [];
      setCampaigns(items);
      setError('');
      return true;
    } catch (err) {
      if (!silent) {
        setCampaigns([]);
        setError(err?.response?.data?.message ?? 'Nao foi possivel carregar campanhas.');
      }
      return false;
    } finally {
      if (!silent) {
        setLoading(false);
      }
    }
  }, []);

  useEffect(() => {
    void loadCampaigns();
  }, [loadCampaigns]);

  useEffect(() => {
    campaignsRef.current = campaigns;
  }, [campaigns]);

  const orderedCampaigns = useMemo(() => {
    return [...campaigns].sort((a, b) => {
      const aTime = new Date(a?.created_at ?? 0).getTime();
      const bTime = new Date(b?.created_at ?? 0).getTime();
      return bTime - aTime;
    });
  }, [campaigns]);

  useEffect(() => {
    const unsubscribe = realtimeClient.on(REALTIME_EVENTS.CAMPAIGN_UPDATED, (envelope) => {
      const update = parseCampaignRealtimeUpdate(envelope?.payload);
      if (!update) {
        return;
      }

      lastRealtimeUpdateAtRef.current = Date.now();

      const hasCampaign = campaignsRef.current.some(
        (campaign) => Number(campaign?.id) === update.id
      );

      if (!hasCampaign) {
        void loadCampaigns({ silent: true });
        return;
      }

      setCampaigns((previous) =>
        previous.map((campaign) => {
          if (Number(campaign?.id) !== update.id) {
            return campaign;
          }

          return {
            ...campaign,
            ...(update.status ? { status: update.status } : {}),
            ...(update.sent_count !== null ? { sent_count: update.sent_count } : {}),
            ...(update.failed_count !== null ? { failed_count: update.failed_count } : {}),
            ...(update.skipped_count !== null ? { skipped_count: update.skipped_count } : {}),
            ...(update.pending_count !== null ? { pending_count: update.pending_count } : {}),
          };
        })
      );
    });

    return () => {
      unsubscribe();
    };
  }, [loadCampaigns]);

  useEffect(() => {
    const intervalId = window.setInterval(() => {
      const sinceLastRealtimeMs = Date.now() - lastRealtimeUpdateAtRef.current;
      if (sinceLastRealtimeMs < 4500) {
        return;
      }

      void loadCampaigns({ silent: true });
    }, 5000);

    return () => window.clearInterval(intervalId);
  }, [loadCampaigns]);

  const openCreateModal = () => {
    setIsCreateModalOpen(true);
    setModalError('');
    setContactsError('');
    setContactsImportError('');
    setContactsLoading(true);
    setContacts([]);
    void loadTemplates();
    void fetchAllCompanyContacts()
      .then((items) => {
        setContacts(items);
      })
      .catch((err) => {
        setContacts([]);
        setContactsError(err?.response?.data?.message ?? 'Nao foi possivel carregar contatos.');
      })
      .finally(() => {
        setContactsLoading(false);
      });
  };

  const closeCreateModal = () => {
    if (createBusy) return;
    setIsCreateModalOpen(false);
    setModalError('');
  };

  const handleImportContactsCsv = async (file) => {
    const formData = new FormData();
    formData.append('file', file);

    setContactsImportBusy(true);
    setContactsImportError('');

    try {
      const response = await api.post('/contacts/import', formData, {
        headers: { 'Content-Type': 'multipart/form-data' },
      });
      const imported = Number(response?.data?.imported ?? 0);
      const skipped = Number(response?.data?.skipped ?? 0);
      const refreshedContacts = await fetchAllCompanyContacts();
      setContacts(refreshedContacts);
      showSuccess(`Importacao concluida: ${imported} importados, ${skipped} ignorados.`);
    } catch (err) {
      const message = err?.response?.data?.message ?? 'Nao foi possivel importar CSV de contatos.';
      setContactsImportError(message);
      showError(message);
    } finally {
      setContactsImportBusy(false);
    }
  };

  const handleCreateCampaign = async (formData) => {
    setModalError('');

    setCreateBusy(true);
    try {
      const payload = {
        name: String(formData?.name ?? '').trim(),
        type: String(formData?.type ?? '').trim(),
        contact_ids: Array.isArray(formData?.contactIds) ? formData.contactIds : [],
      };

      if (payload.type === 'template') {
        payload.template_id = String(formData?.templateTemplateId ?? '').trim() || null;
        const variablesText = Array.isArray(formData?.templateVariables)
          ? formData.templateVariables.join(' | ').trim()
          : '';
        payload.message = variablesText || null;
      } else if (payload.type === 'open') {
        payload.template_id = String(formData?.openTemplateId ?? '').trim() || null;
        payload.message = String(formData?.openPostResponseMessage ?? '').trim() || null;
      } else if (payload.type === 'free') {
        payload.message = String(formData?.freeMessage ?? '').trim() || null;
      }

      const response = await api.post('/minha-conta/campanhas', {
        ...payload,
      });

      const newCampaign = response?.data?.campaign;
      if (newCampaign) {
        setCampaigns((previous) => [newCampaign, ...previous]);
      } else {
        await loadCampaigns();
      }

      showSuccess('Campanha criada com sucesso.');
      setIsCreateModalOpen(false);
    } catch (err) {
      const message =
        err?.response?.data?.errors?.name?.[0] ??
        err?.response?.data?.errors?.type?.[0] ??
        err?.response?.data?.message ??
        'Nao foi possivel criar campanha.';
      setModalError(message);
    } finally {
      setCreateBusy(false);
    }
  };

  const handleStartCampaign = async (campaignId) => {
    setStartingId(campaignId);
    try {
      await api.post(`/campaigns/${campaignId}/start`);
      setCampaigns((previous) =>
        previous.map((campaign) =>
          campaign.id === campaignId ? { ...campaign, status: 'sending' } : campaign
        )
      );
      await loadCampaigns({ silent: true });
      showSuccess('Envio da campanha iniciado.');
    } catch (err) {
      showError(err?.response?.data?.message ?? 'Nao foi possivel iniciar o envio.');
    } finally {
      setStartingId(null);
    }
  };

  if (meLoading) {
    return (
      <Layout role="company" onLogout={logout}>
        <section className="campaigns-page">
          <h1 className="app-page-title">Campanhas</h1>
          <div className="app-panel">
            <LoadingSpinner label="Carregando..." />
          </div>
        </section>
      </Layout>
    );
  }

  if (meError || !data?.authenticated) {
    return (
      <Layout role="company" onLogout={logout}>
        <section className="campaigns-page">
          <h1 className="app-page-title">Campanhas</h1>
          <div className="app-panel">
            <ErrorMessage message="Nao foi possivel carregar a pagina." />
          </div>
        </section>
      </Layout>
    );
  }

  return (
    <Layout role="company" companyName={data?.user?.company_name} onLogout={logout}>
      <section className="campaigns-page">
        <header className="campaigns-page__header">
          <div>
            <h1 className="app-page-title">Campanhas</h1>
            <p className="app-page-subtitle">Envios em massa por template, open ou free.</p>
          </div>
          <button type="button" className="app-btn-primary" onClick={openCreateModal}>
            Nova campanha
          </button>
        </header>

        {loading ? (
          <div className="app-panel">
            <LoadingSpinner label="Carregando campanhas..." />
          </div>
        ) : null}

        {!loading && error ? (
          <div className="app-panel campaigns-page__state">
            <ErrorMessage message={error} onRetry={() => void loadCampaigns()} />
          </div>
        ) : null}

        {!loading && !error && orderedCampaigns.length === 0 ? (
          <div className="app-panel campaigns-page__state">
            <EmptyState title="Nenhuma campanha encontrada." />
          </div>
        ) : null}

        {!loading && !error && orderedCampaigns.length > 0 ? (
          <div className="app-panel campaigns-table-wrap">
            <table className="campaigns-table">
              <thead>
                <tr>
                  <th>Nome</th>
                  <th>Tipo</th>
                  <th>Status</th>
                  <th>Data</th>
                  <th>Enviados</th>
                  <th>Falhados</th>
                  <th>Ignorados</th>
                  <th>Acoes</th>
                </tr>
              </thead>
              <tbody>
                {orderedCampaigns.map((campaign) => {
                  const status = String(campaign?.status ?? 'draft');
                  const isStartingThis = startingId === campaign.id;
                  const isStartDisabled = status !== 'draft' || isStartingThis;

                  return (
                    <tr key={campaign.id}>
                      <td>{campaign?.name || '-'}</td>
                      <td>{String(campaign?.type ?? '-')}</td>
                      <td>
                        <span className={`campaign-status-badge campaign-status-badge--${status}`}>
                          {status}
                        </span>
                      </td>
                      <td>{formatDate(campaign?.created_at)}</td>
                      <td>{toDisplayCount(campaign?.sent_count)}</td>
                      <td>{toDisplayCount(campaign?.failed_count)}</td>
                      <td>{toDisplayCount(campaign?.skipped_count)}</td>
                      <td>
                        <button
                          type="button"
                          className="app-btn-secondary campaigns-start-btn"
                          disabled={isStartDisabled}
                          onClick={() => void handleStartCampaign(campaign.id)}
                        >
                          {isStartingThis ? 'Iniciando...' : 'Iniciar campanha'}
                        </button>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        ) : null}
      </section>

      {isCreateModalOpen ? (
        <div className="campaigns-modal-overlay" role="presentation" onClick={closeCreateModal}>
          <div
            className="campaigns-modal app-panel"
            role="dialog"
            aria-modal="true"
            aria-label="Nova campanha"
            onClick={(event) => event.stopPropagation()}
          >
            <div className="campaigns-modal__header">
              <h2 className="text-base font-semibold text-[#171717]">Nova campanha</h2>
              <button
                type="button"
                className="campaigns-modal__close"
                onClick={closeCreateModal}
                aria-label="Fechar modal"
                disabled={createBusy}
              >
                x
              </button>
            </div>

            <div className="campaigns-modal__form">
              {templatesLoading ? (
                <p className="text-xs text-[#737373]">Carregando templates...</p>
              ) : null}
              {templatesError ? (
                <p className="text-xs text-amber-700">{templatesError}</p>
              ) : null}
              {contactsLoading ? (
                <p className="text-xs text-[#737373]">Carregando contatos...</p>
              ) : null}
              {contactsError ? (
                <p className="text-xs text-amber-700">{contactsError}</p>
              ) : null}
              <CampaignForm
                templates={templates}
                contacts={contacts}
                busy={createBusy}
                importBusy={contactsImportBusy}
                importError={contactsImportError}
                onImportCsv={(file) => void handleImportContactsCsv(file)}
                submitLabel={createBusy ? 'Salvando...' : 'Salvar campanha'}
                onSubmit={(payload) => void handleCreateCampaign(payload)}
                onCancel={closeCreateModal}
              />
              {modalError ? <p className="text-xs text-red-600">{modalError}</p> : null}
            </div>
          </div>
        </div>
      ) : null}
    </Layout>
  );
}

export default CampaignsPage;
