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
  metaNumbers = [],
}) {
  const [editing, setEditing] = useState(false);
  const [name, setName] = useState('');
  const [phone, setPhone] = useState('');
  const [defaultAttendantId, setDefaultAttendantId] = useState('');
  const [skipBot, setSkipBot] = useState(false);
  const [metaNumberId, setMetaNumberId] = useState('');
  const [fieldError, setFieldError] = useState('');
  const [confirmOpen, setConfirmOpen] = useState(false);

  useEffect(() => {
    if (!contact) return undefined;
    setName(contact.name ?? '');
    setPhone(contact.phone ?? '');
    setDefaultAttendantId(contact.default_attendant_user_id ? String(contact.default_attendant_user_id) : '');
    setSkipBot(Boolean(contact.skip_bot_to_default_attendant));
    setMetaNumberId(contact.meta_number_id ? String(contact.meta_number_id) : '');
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
  const selectedMetaNumber = metaNumbers.find((item) => Number(item.id) === Number(contact?.meta_number_id));
  const selectedMetaLabel = selectedMetaNumber ? (selectedMetaNumber.display_name || selectedMetaNumber.phone_number) : 'Padrao da empresa';

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
        meta_number_id: metaNumberId || null,
        default_attendant_user_id: defaultAttendantId || null,
        skip_bot_to_default_attendant: skipBot,
      });
      setEditing(false);
    } catch (err) {
      setFieldError(err.message || 'Nao foi possivel salvar.');
    }
  };

  const addedByName = contact.added_by?.name ?? null;
  const sourceLabel = SOURCE_LABEL[contact.source] ?? null;

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
                <div className="contact-detail-row"><dt>Numero padrao de envio</dt><dd>{selectedMetaLabel}</dd></div>
                <div className="contact-detail-row"><dt>Atendente padrao</dt><dd>{contact?.default_attendant?.name ?? 'Nao definido'}</dd></div>
                <div className="contact-detail-row"><dt>Pular bot</dt><dd>{contact?.skip_bot_to_default_attendant ? 'Ativo' : 'Inativo'}</dd></div>
                <div className="contact-detail-row"><dt>Adicionado em</dt><dd>{formatDate(contact.created_at)}</dd></div>
                {addedByName ? <div className="contact-detail-row"><dt>Adicionado por</dt><dd>{addedByName}</dd></div> : null}
                {sourceLabel ? <div className="contact-detail-row"><dt>Origem</dt><dd>{sourceLabel}</dd></div> : null}
                {contact.last_interaction_at ? <div className="contact-detail-row"><dt>Ultima interacao</dt><dd>{formatDate(contact.last_interaction_at)}</dd></div> : null}
              </dl>

              {fieldError ? <p className="contact-detail-error">{fieldError}</p> : null}

              <div className="contact-detail-actions">
                <button type="button" className="app-btn-secondary contact-detail-delete-btn" onClick={() => setConfirmOpen(true)} disabled={busy}>Excluir</button>
                <button type="button" className="app-btn-primary" onClick={() => setEditing(true)} disabled={busy}>Editar</button>
              </div>
            </>
          ) : (
            <form onSubmit={handleSave} className="contacts-form">
              <div>
                <label htmlFor="cd-name" className="contacts-label">Nome</label>
                <input id="cd-name" type="text" className="app-input" value={name} onChange={(e) => setName(e.target.value)} disabled={saving} autoFocus required />
              </div>
              <div>
                <label htmlFor="cd-phone" className="contacts-label">Telefone</label>
                <input id="cd-phone" type="tel" className="app-input" value={phone} onChange={(e) => setPhone(e.target.value)} disabled={saving} placeholder="5511999999999" required />
              </div>
              <div>
                <label htmlFor="cd-meta-number" className="contacts-label">Numero padrao de envio</label>
                <select id="cd-meta-number" className="app-input" value={metaNumberId} onChange={(event) => setMetaNumberId(event.target.value)} disabled={saving}>
                  <option value="">Padrao da empresa</option>
                  {metaNumbers.map((item) => (
                    <option key={item.id} value={String(item.id)}>
                      {item.display_name || item.phone_number}{item.is_primary ? ' (principal)' : ''}
                    </option>
                  ))}
                </select>
              </div>
              <div>
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
              </div>

              <div className="contacts-checkbox-row">
                <label className="contacts-checkbox-label" htmlFor="cd-skip-bot">
                  <input id="cd-skip-bot" type="checkbox" checked={skipBot} disabled={saving || !defaultAttendantId} onChange={(event) => setSkipBot(event.target.checked)} />
                  <span>Pular bot e ir direto para atendente</span>
                </label>
              </div>

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
        onConfirm={async () => {
          try {
            await onDelete(contact.id);
            onClose();
          } catch (err) {
            setConfirmOpen(false);
            setFieldError(err.message || 'Nao foi possivel excluir.');
          }
        }}
        onClose={() => setConfirmOpen(false)}
      />
    </>
  );
}

export default ContactDetailModal;
