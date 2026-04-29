import './ContactsPage.css';
import { useRef, useState } from 'react';
import Layout from '@/components/layout/Layout/Layout.jsx';
import EmptyState from '@/components/ui/EmptyState/EmptyState.jsx';
import ErrorMessage from '@/components/ui/ErrorMessage/ErrorMessage.jsx';
import LoadingSpinner from '@/components/ui/LoadingSpinner/LoadingSpinner.jsx';
import useLogout from '@/hooks/useLogout';
import usePageData from '@/hooks/usePageData';
import { showError, showSuccess } from '@/services/toastService';
import useContacts from './hooks/useContacts';
import ContactDetailModal from './components/ContactDetailModal.jsx';

const formatLastInteraction = (value) => {
  if (!value) return '-';
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return '-';
  return date.toLocaleString('pt-BR');
};

function ContactsPage() {
  const { data, loading: meLoading, error: meError } = usePageData('/me');
  const { logout } = useLogout();
  const {
    contacts,
    searchQuery,
    loading: contactsLoading,
    error: contactsError,
    creating,
    importing,
    saving,
    deleting,
    searchContacts,
    refetch,
    createContact,
    updateContact,
    deleteContact,
    importCsv,
  } = useContacts();

  const csvInputRef = useRef(null);
  const [selectedContact, setSelectedContact] = useState(null);
  const [isModalOpen, setIsModalOpen] = useState(false);
  const [newName, setNewName] = useState('');
  const [newPhone, setNewPhone] = useState('');
  const [modalError, setModalError] = useState('');

  const openCreateModal = () => {
    setIsModalOpen(true);
    setModalError('');
    setNewName('');
    setNewPhone('');
  };

  const closeCreateModal = () => {
    if (creating) return;
    setIsModalOpen(false);
    setModalError('');
  };

  const handleCreateContact = async (event) => {
    event.preventDefault();
    setModalError('');

    if (!newName.trim() || !newPhone.trim()) {
      setModalError('Preencha nome e telefone.');
      return;
    }

    try {
      await createContact({ name: newName, phone: newPhone });
      setIsModalOpen(false);
      showSuccess('Contato criado com sucesso.');
    } catch (err) {
      setModalError(err.message || 'Não foi possível criar o contato.');
    }
  };

  const handleUpdateContact = async (id, fields) => {
    const updated = await updateContact(id, fields);
    if (updated) setSelectedContact(updated);
    showSuccess('Contato atualizado.');
  };

  const handleDeleteContact = async (id) => {
    await deleteContact(id);
    showSuccess('Contato excluído.');
  };

  const triggerCsvImport = () => {
    csvInputRef.current?.click();
  };

  const handleCsvSelected = async (event) => {
    const file = event.target.files?.[0];
    event.target.value = '';
    if (!file) return;

    try {
      const result = await importCsv(file);
      const imported = Number(result?.imported ?? 0);
      const skipped = Number(result?.skipped ?? 0);
      const firstError = Array.isArray(result?.errors) ? result.errors[0] : '';
      const message = firstError
        ? `Importacao concluida (${imported} importados, ${skipped} ignorados). Aviso: ${firstError}`
        : `Importacao concluida: ${imported} importados, ${skipped} ignorados.`;
      showSuccess(message);
    } catch (err) {
      showError(err.message || 'Não foi possível importar o CSV.');
    }
  };

  if (meLoading || contactsLoading) {
    return (
      <Layout role="company" onLogout={logout}>
        <section className="contacts-page">
          <h1 className="app-page-title">Contatos</h1>
          <div className="app-panel">
            <LoadingSpinner label="Carregando contatos..." />
          </div>
        </section>
      </Layout>
    );
  }

  if (meError || !data?.authenticated) {
    return (
      <Layout role="company" onLogout={logout}>
        <section className="contacts-page">
          <h1 className="app-page-title">Contatos</h1>
          <div className="app-panel">
            <ErrorMessage message="Erro ao carregar a pagina de contatos." />
          </div>
        </section>
      </Layout>
    );
  }

  return (
    <Layout role="company" companyName={data?.user?.company_name} onLogout={logout}>
      <section className="contacts-page">
        <header>
          <h1 className="app-page-title">Contatos</h1>
          <p className="app-page-subtitle">
            Gerencie sua base de contatos, importe via CSV e acompanhe a ultima interacao.
          </p>
        </header>

        <div className="app-panel contacts-toolbar">
          <div className="contacts-search-wrap">
            <label htmlFor="contacts-search" className="contacts-label">
              Buscar por nome ou telefone
            </label>
            <input
              id="contacts-search"
              type="search"
              value={searchQuery}
              onChange={(event) => searchContacts(event.target.value)}
              placeholder="Ex: Maria ou 5511999999999"
              className="app-input"
            />
          </div>

          <div className="contacts-toolbar-actions">
            <input
              ref={csvInputRef}
              type="file"
              accept=".csv,text/csv,text/plain"
              className="contacts-hidden-input"
              onChange={handleCsvSelected}
            />
            <button
              type="button"
              className="app-btn-secondary"
              onClick={triggerCsvImport}
              disabled={importing}
            >
              {importing ? 'Importando...' : 'Importar CSV'}
            </button>
            <button type="button" className="app-btn-primary" onClick={openCreateModal}>
              Novo contato
            </button>
          </div>
        </div>

        {contactsError ? (
          <div className="app-panel contacts-state">
            <ErrorMessage message={contactsError || 'Erro ao carregar contatos.'} onRetry={refetch} />
          </div>
        ) : null}

        {!contactsError && contacts.length === 0 ? (
          <div className="app-panel contacts-state">
            <EmptyState title="Nenhum contato encontrado" />
          </div>
        ) : null}

        {!contactsError && contacts.length > 0 ? (
          <div className="app-panel contacts-table-wrap">
            <table className="contacts-table">
              <thead>
                <tr>
                  <th>Nome</th>
                  <th>Telefone</th>
                  <th>Ultima interacao</th>
                </tr>
              </thead>
              <tbody>
                {contacts.map((contact) => (
                  <tr
                    key={contact.id}
                    className="contacts-table-row--clickable"
                    onClick={() => setSelectedContact(contact)}
                    tabIndex={0}
                    role="button"
                    aria-label={`Ver detalhes de ${contact.name || contact.phone}`}
                    onKeyDown={(e) => e.key === 'Enter' && setSelectedContact(contact)}
                  >
                    <td>{contact.name || '-'}</td>
                    <td>{contact.phone || '-'}</td>
                    <td>{formatLastInteraction(contact.last_interaction_at)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        ) : null}
      </section>

      <ContactDetailModal
        contact={selectedContact}
        onClose={() => setSelectedContact(null)}
        onUpdate={handleUpdateContact}
        onDelete={handleDeleteContact}
        saving={saving}
        deleting={deleting}
      />

      {isModalOpen ? (
        <div className="contacts-modal-overlay" onClick={closeCreateModal} role="presentation">
          <div
            className="contacts-modal app-panel"
            role="dialog"
            aria-modal="true"
            aria-label="Novo contato"
            onClick={(event) => event.stopPropagation()}
          >
            <div className="contacts-modal-header">
              <h2 className="text-base font-semibold text-[#171717]">Novo contato</h2>
              <button
                type="button"
                className="contacts-close-btn"
                onClick={closeCreateModal}
                disabled={creating}
                aria-label="Fechar modal"
              >
                x
              </button>
            </div>

            <form onSubmit={handleCreateContact} className="contacts-form">
              <div>
                <label htmlFor="contacts-new-name" className="contacts-label">
                  Nome
                </label>
                <input
                  id="contacts-new-name"
                  type="text"
                  className="app-input"
                  value={newName}
                  onChange={(event) => setNewName(event.target.value)}
                  disabled={creating}
                  autoFocus
                  required
                />
              </div>

              <div>
                <label htmlFor="contacts-new-phone" className="contacts-label">
                  Telefone
                </label>
                <input
                  id="contacts-new-phone"
                  type="tel"
                  className="app-input"
                  value={newPhone}
                  onChange={(event) => setNewPhone(event.target.value)}
                  disabled={creating}
                  placeholder="5511999999999"
                  required
                />
              </div>

              {modalError ? <p className="text-xs text-red-600">{modalError}</p> : null}

              <div className="contacts-modal-actions">
                <button
                  type="button"
                  className="app-btn-secondary"
                  onClick={closeCreateModal}
                  disabled={creating}
                >
                  Cancelar
                </button>
                <button type="submit" className="app-btn-primary" disabled={creating}>
                  {creating ? 'Salvando...' : 'Salvar contato'}
                </button>
              </div>
            </form>
          </div>
        </div>
      ) : null}
    </Layout>
  );
}

export default ContactsPage;
