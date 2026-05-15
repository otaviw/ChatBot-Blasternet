import './ContactDetailModal.css';
import { useEffect, useState } from 'react';
import ConfirmDialog from '@/components/ui/ConfirmDialog/ConfirmDialog.jsx';

const SOURCE_LABEL = {
  manual: 'Adicionado manualmente',
  csv: 'Importado via CSV',
};

const formatDate = (value) => {
  if (!value) return '-';
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return '-';
  return date.toLocaleString('pt-BR');
};

function ContactDetailModal({
  contact,
  onClose,
  onUpdate,
  onDelete,
  saving,
  deleting,
  attendants = [],
  activeSenderNumbers = [],
}) {
  const [editing, setEditing] = useState(false);
  const [name, setName] = useState('');
  const [phone, setPhone] = useState('');
  const [defaultSenderNumberId, setDefaultSenderNumberId] = useState('');
  const [defaultAttendantId, setDefaultAttendantId] = useState('');
  const [skipBot, setSkipBot] = useState(false);
  const [fieldError, setFieldError] = useState('');
  const [confirmOpen, setConfirmOpen] = useState(false);

  useEffect(() => {
    if (!contact) return undefined;
    setName(contact.name ?? '');
    setPhone(contact.phone ?? '');
    setDefaultSenderNumberId(String(contact.default_sender_number_id ?? ''));
    setDefaultAttendantId(contact.default_attendant_user_id ? String(contact.default_attendant_user_id) : '');
    setSkipBot(Boolean(contact.skip_bot_to_default_attendant));
    setEditing(false);
    setFieldError('');
    setConfirmOpen(false);

    const onKeyDown = (event) => {
      if (event.key === 'Escape' && !saving && !deleting) onClose();
    };
    window.addEventListener('keydown', onKeyDown);
    return () => window.removeEventListener('keydown', onKeyDown);
  }, [contact, onClose, saving, deleting]);

  if (!contact) return null;
  const busy = saving || deleting;
  const addedByName = contact.added_by?.name ?? null;
  const sourceLabel = SOURCE_LABEL[contact.source] ?? null;

  const handleSave = async (event) => {
    event.preventDefault();
    setFieldError('');
    if (!name.trim() || !phone.trim()) {
      setFieldError('Preencha nome e telefone.');
      return;
    }
    try {
      await onUpdate(contact.id, {
        name,
        phone,
        default_sender_number_id: defaultSenderNumberId || null,
        default_attendant_user_id: defaultAttendantId || null,
        skip_bot_to_default_attendant: skipBot,
      });
      setEditing(false);
    } catch (err) {
      setFieldError(err.message || 'Nao foi possivel salvar.');
    }
  };

  const handleConfirmDelete = async () => {
    try {
      await onDelete(contact.id);
      onClose();
    } catch (err) {
      setConfirmOpen(false);
      setFieldError(err.message || 'Nao foi possivel excluir.');
    }
  };

  return (
    <>
      <div className="contacts-modal-overlay" onClick={() => !busy && onClose()} role="presentation">
        <div className="contact-detail-modal app-panel" role="dialog" aria-modal="true" aria-label="Detalhes do contato" onClick={(e) => e.stopPropagation()}>
          <div className="contacts-modal-header">
            <h2 className="contact-detail-title">Detalhes do contato</h2>
            <button type="button" className="contacts-close-btn" onClick={onClose} disabled={busy} aria-label="Fechar">x</button>
          </div>

          {!editing ? (
            <>
              <dl className="contact-detail-info">
                <div className="contact-detail-row"><dt>Nome</dt><dd>{contact.name || '-'}</dd></div>
                <div className="contact-detail-row"><dt>Telefone</dt><dd>{contact.phone || '-'}</dd></div>
                <div className="contact-detail-row">
                  <dt>Numero padrao de envio</dt>
                  <dd>{contact.default_sender_number_label ?? contact.default_sender_number_id ?? '-'}</dd>
                </div>
                <div className="contact-detail-row"><dt>Atendente padrao</dt><dd>{contact?.default_attendant?.name ?? 'Nao definido'}</dd></div>
                <div className="contact-detail-row"><dt>Pular bot</dt><dd>{contact?.skip_bot_to_default_attendant ? 'Ativo' : 'Inativo'}</dd></div>
                <div className="contact-detail-row"><dt>Adicionado em</dt><dd>{formatDate(contact.created_at)}</dd></div>
                {addedByName ? <div className="contact-detail-row"><dt>Adicionado por</dt><dd>{addedByName}</dd></div> : null}
                {sourceLabel ? <div className="contact-detail-row"><dt>Origem</dt><dd>{sourceLabel}</dd></div> : null}
                {contact.last_interaction_at ? <div className="contact-detail-row"><dt>Ultima interacao</dt><dd>{formatDate(contact.last_interaction_at)}</dd></div> : null}
                {Array.isArray(contact.audit_summary) && contact.audit_summary.length > 0 ? (
                  <div className="contact-detail-row">
                    <dt>Historico</dt>
                    <dd>{contact.audit_summary.slice(0, 3).join(' | ')}</dd>
                  </div>
                ) : null}
              </dl>

              {fieldError ? <p className="contact-detail-error">{fieldError}</p> : null}
              <div className="contact-detail-actions">
                <button type="button" className="app-btn-secondary contact-detail-delete-btn" onClick={() => setConfirmOpen(true)} disabled={busy}>Excluir</button>
                <button type="button" className="app-btn-primary" onClick={() => setEditing(true)} disabled={busy}>Editar</button>
              </div>
            </>
          ) : (
            <form onSubmit={handleSave} className="contacts-form">
              <label htmlFor="cd-name" className="contacts-label">Nome</label>
              <input id="cd-name" type="text" className="app-input" value={name} onChange={(e) => setName(e.target.value)} disabled={saving} required />

              <label htmlFor="cd-phone" className="contacts-label">Telefone</label>
              <input id="cd-phone" type="tel" className="app-input" value={phone} onChange={(e) => setPhone(e.target.value)} disabled={saving} required />

              <label htmlFor="cd-default-sender" className="contacts-label">Numero padrao de envio</label>
              <select id="cd-default-sender" className="app-input" value={defaultSenderNumberId} onChange={(e) => setDefaultSenderNumberId(e.target.value)} disabled={saving || activeSenderNumbers.length === 0}>
                <option value="">Sem numero padrao</option>
                {activeSenderNumbers.map((number) => (
                  <option key={String(number.id)} value={String(number.id)}>{String(number.label || number.id)}</option>
                ))}
              </select>

              <label htmlFor="cd-default-attendant" className="contacts-label">Atendente padrao</label>
              <select
                id="cd-default-attendant"
                className="app-input"
                value={defaultAttendantId}
                onChange={(event) => {
                  const nextValue = event.target.value;
                  setDefaultAttendantId(nextValue);
                  if (!nextValue) setSkipBot(false);
                }}
                disabled={saving}
              >
                <option value="">Selecione um atendente</option>
                {attendants.map((attendant) => (
                  <option key={attendant.id} value={String(attendant.id)}>{attendant.name}</option>
                ))}
              </select>

              <label className="contacts-checkbox-label" htmlFor="cd-skip-bot">
                <input id="cd-skip-bot" type="checkbox" checked={skipBot} disabled={saving || !defaultAttendantId} onChange={(event) => setSkipBot(event.target.checked)} />
                <span>Pular bot e ir direto para atendente</span>
              </label>

              {fieldError ? <p className="contact-detail-error">{fieldError}</p> : null}
              <div className="contacts-modal-actions">
                <button type="button" className="app-btn-secondary" onClick={() => setEditing(false)} disabled={saving}>Cancelar</button>
                <button type="submit" className="app-btn-primary" disabled={saving}>{saving ? 'Salvando...' : 'Salvar'}</button>
              </div>
            </form>
          )}
        </div>
      </div>

      <ConfirmDialog
        open={confirmOpen}
        title="Excluir contato"
        description={`Tem certeza que deseja excluir "${contact.name}"? Esta acao nao pode ser desfeita.`}
        confirmLabel="Excluir"
        confirmTone="danger"
        busy={deleting}
        onConfirm={handleConfirmDelete}
        onClose={() => setConfirmOpen(false)}
      />
    </>
  );
}

export default ContactDetailModal;
