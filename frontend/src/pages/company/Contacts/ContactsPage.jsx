import './ContactsPage.css';
import { useMemo, useRef, useState } from 'react';
import Layout from '@/components/layout/Layout/Layout.jsx';
import EmptyState from '@/components/ui/EmptyState/EmptyState.jsx';
import ErrorMessage from '@/components/ui/ErrorMessage/ErrorMessage.jsx';
import LoadingSpinner from '@/components/ui/LoadingSpinner/LoadingSpinner.jsx';
import useLogout from '@/hooks/useLogout';
import usePageData from '@/hooks/usePageData';
import { showError, showSuccess } from '@/services/toastService';
import { fetchCompanyMetaNumbers } from '@/services/metaNumbers';
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
  const { contacts, searchQuery, loading: contactsLoading, error: contactsError, creating, importing, saving, deleting, searchContacts, refetch, createContact, updateContact, deleteContact, importCsv } = useContacts();

  const csvInputRef = useRef(null);
  const [selectedContact, setSelectedContact] = useState(null);
  const [isModalOpen, setIsModalOpen] = useState(false);
  const [newName, setNewName] = useState('');
  const [newPhone, setNewPhone] = useState('');
  const [newMetaNumberId, setNewMetaNumberId] = useState('');
  const [newDefaultAttendantId, setNewDefaultAttendantId] = useState('');
  const [newSkipBot, setNewSkipBot] = useState(false);
  const [modalError, setModalError] = useState('');
  const [metaNumbers, setMetaNumbers] = useState([]);

  const openCreateModal = () => {
    setIsModalOpen(true);
    setModalError('');
    setNewName('');
    setNewPhone('');
    setNewMetaNumberId('');
    setNewDefaultAttendantId('');
    setNewSkipBot(false);
    void fetchCompanyMetaNumbers().then(setMetaNumbers).catch(() => setMetaNumbers([]));
  };

  const handleCreateContact = async (event) => {
    event.preventDefault();
    setModalError('');
    if (!newName.trim() || !newPhone.trim()) {
      setModalError('Preencha nome e telefone.');
      return;
    }

    try {
      await createContact({
        name: newName,
        phone: newPhone,
        meta_number_id: newMetaNumberId || null,
        default_attendant_user_id: newDefaultAttendantId || null,
        skip_bot_to_default_attendant: newSkipBot,
      });
      setIsModalOpen(false);
      showSuccess('Contato criado com sucesso.');
    } catch (err) {
      setModalError(err.message || 'Nao foi possivel criar o contato.');
    }
  };

  const attendants = useMemo(() => {
    const raw = Array.isArray(data?.attendants) ? data.attendants : [];
    return raw
      .filter((item) => Boolean(item?.is_active ?? true))
      .map((item) => ({ id: Number(item?.id ?? 0), name: String(item?.name ?? '').trim() }))
      .filter((item) => item.id > 0 && item.name !== '');
  }, [data]);

  if (meLoading || contactsLoading) {
    return <Layout role="company" onLogout={logout}><section className="contacts-page"><h1 className="app-page-title">Contatos</h1><div className="app-panel"><LoadingSpinner label="Carregando contatos..." /></div></section></Layout>;
  }

  if (meError || !data?.authenticated) {
    return <Layout role="company" onLogout={logout}><section className="contacts-page"><h1 className="app-page-title">Contatos</h1><div className="app-panel"><ErrorMessage message="Erro ao carregar a pagina de contatos." /></div></section></Layout>;
  }

  return (
    <Layout role="company" companyName={data?.user?.company_name} onLogout={logout}>
      <section className="contacts-page">
        <header>
          <h1 className="app-page-title">Contatos</h1>
          <p className="app-page-subtitle">Gerencie sua base de contatos.</p>
        </header>

        <div className="app-panel contacts-toolbar">
          <div className="contacts-search-wrap">
            <label htmlFor="contacts-search" className="contacts-label">Buscar por nome ou telefone</label>
            <input id="contacts-search" type="search" value={searchQuery} onChange={(event) => searchContacts(event.target.value)} placeholder="Ex: Maria ou 5511999999999" className="app-input" />
          </div>
          <div className="contacts-toolbar-actions">
            <input ref={csvInputRef} type="file" accept=".csv,text/csv,text/plain" className="contacts-hidden-input" onChange={async (event) => {
              const file = event.target.files?.[0];
              event.target.value = '';
              if (!file) return;
              try {
                const result = await importCsv(file);
                showSuccess(`Importacao concluida: ${Number(result?.imported ?? 0)} importados.`);
              } catch (err) {
                showError(err.message || 'Nao foi possivel importar o CSV.');
              }
            }} />
            <button type="button" className="app-btn-secondary" onClick={() => csvInputRef.current?.click()} disabled={importing}>{importing ? 'Importando...' : 'Importar CSV'}</button>
            <button type="button" className="app-btn-primary" onClick={openCreateModal}>Novo contato</button>
          </div>
        </div>

        {contactsError ? <div className="app-panel contacts-state"><ErrorMessage message={contactsError || 'Erro ao carregar contatos.'} onRetry={refetch} /></div> : null}
        {!contactsError && contacts.length === 0 ? <div className="app-panel contacts-state"><EmptyState title="Nenhum contato encontrado" /></div> : null}
        {!contactsError && contacts.length > 0 ? (
          <div className="app-panel contacts-table-wrap">
            <table className="contacts-table">
              <thead><tr><th>Nome</th><th>Telefone</th><th>Ultima interacao</th></tr></thead>
              <tbody>
                {contacts.map((contact) => (
                  <tr key={contact.id} className="contacts-table-row--clickable" onClick={() => setSelectedContact(contact)} tabIndex={0} role="button" onKeyDown={(e) => e.key === 'Enter' && setSelectedContact(contact)}>
                    <td>{contact.name || '-'}</td><td>{contact.phone || '-'}</td><td>{formatLastInteraction(contact.last_interaction_at)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        ) : null}
      </section>

      <ContactDetailModal contact={selectedContact} onClose={() => setSelectedContact(null)} onUpdate={async (id, fields) => {
        const updated = await updateContact(id, fields);
        if (updated) setSelectedContact(updated);
        showSuccess('Contato atualizado.');
      }} onDelete={async (id) => {
        await deleteContact(id);
        showSuccess('Contato excluido.');
      }} saving={saving} deleting={deleting} attendants={attendants} metaNumbers={metaNumbers} />

      {isModalOpen ? (
        <div className="contacts-modal-overlay" onClick={() => !creating && setIsModalOpen(false)} role="presentation">
          <div className="contacts-modal app-panel" role="dialog" aria-modal="true" aria-label="Novo contato" onClick={(event) => event.stopPropagation()}>
            <div className="contacts-modal-header">
              <h2 className="text-base font-semibold text-[#171717]">Novo contato</h2>
              <button type="button" className="contacts-close-btn" onClick={() => setIsModalOpen(false)} disabled={creating} aria-label="Fechar modal">x</button>
            </div>

            <form onSubmit={handleCreateContact} className="contacts-form">
              <div><label htmlFor="contacts-new-name" className="contacts-label">Nome</label><input id="contacts-new-name" type="text" className="app-input" value={newName} onChange={(event) => setNewName(event.target.value)} disabled={creating} autoFocus required /></div>
              <div><label htmlFor="contacts-new-phone" className="contacts-label">Telefone</label><input id="contacts-new-phone" type="tel" className="app-input" value={newPhone} onChange={(event) => setNewPhone(event.target.value)} disabled={creating} placeholder="5511999999999" required /></div>
              <div>
                <label htmlFor="contacts-new-meta-number" className="contacts-label">Numero padrao de envio</label>
                <select id="contacts-new-meta-number" className="app-input" value={newMetaNumberId} onChange={(event) => setNewMetaNumberId(event.target.value)} disabled={creating}>
                  <option value="">Padrao da empresa</option>
                  {metaNumbers.map((item) => <option key={item.id} value={String(item.id)}>{item.display_name || item.phone_number}{item.is_primary ? ' (principal)' : ''}</option>)}
                </select>
              </div>
              <div>
                <label htmlFor="contacts-new-default-attendant" className="contacts-label">Atendente padrao</label>
                <select id="contacts-new-default-attendant" className="app-input" value={newDefaultAttendantId} onChange={(event) => { const nextValue = event.target.value; setNewDefaultAttendantId(nextValue); if (!nextValue) setNewSkipBot(false); }} disabled={creating}>
                  <option value="">Selecione um atendente</option>
                  {attendants.map((attendant) => <option key={attendant.id} value={String(attendant.id)}>{attendant.name}</option>)}
                </select>
              </div>
              <div className="contacts-checkbox-row"><label className="contacts-checkbox-label" htmlFor="contacts-new-skip-bot"><input id="contacts-new-skip-bot" type="checkbox" checked={newSkipBot} disabled={creating || !newDefaultAttendantId} onChange={(event) => setNewSkipBot(event.target.checked)} /><span>Pular bot e ir direto para atendente</span></label></div>
              {modalError ? <p className="text-xs text-red-600">{modalError}</p> : null}
              <div className="contacts-modal-actions">
                <button type="button" className="app-btn-secondary" onClick={() => setIsModalOpen(false)} disabled={creating}>Cancelar</button>
                <button type="submit" className="app-btn-primary" disabled={creating}>{creating ? 'Salvando...' : 'Salvar contato'}</button>
              </div>
            </form>
          </div>
        </div>
      ) : null}
    </Layout>
  );
}

export default ContactsPage;
