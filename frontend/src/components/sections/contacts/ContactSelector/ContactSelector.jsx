import './ContactSelector.css';
import { useCallback, useEffect, useId, useMemo, useState } from 'react';

const MODE_ALL = 'all';
const MODE_MANUAL = 'manual';
const MODE_CSV = 'csv';

const digitsOnly = (value) => String(value ?? '').replace(/\D/g, '');

function ContactSelector({
  contacts = [],
  disabled = false,
  initialMode = MODE_ALL,
  initialSelectedIds = [],
  importBusy = false,
  importError = '',
  onModeChange,
  onSelectedIdsChange,
  onImportCsv,
}) {
  const [mode, setMode] = useState(initialMode);
  const [search, setSearch] = useState('');
  const radioGroupId = useId();
  const [manualSelectedIds, setManualSelectedIds] = useState(() => {
    return Array.isArray(initialSelectedIds)
      ? initialSelectedIds.map((id) => Number(id)).filter((id) => Number.isInteger(id) && id > 0)
      : [];
  });

  const allContactIds = useMemo(() => {
    return contacts
      .map((contact) => Number(contact?.id))
      .filter((id) => Number.isInteger(id) && id > 0);
  }, [contacts]);

  const availableIdSet = useMemo(() => new Set(allContactIds), [allContactIds]);

  const filteredContacts = useMemo(() => {
    const normalizedSearch = String(search ?? '').trim().toLowerCase();
    if (!normalizedSearch) return contacts;
    const numericSearch = digitsOnly(normalizedSearch);

    return contacts.filter((contact) => {
      const name = String(contact?.name ?? '').toLowerCase();
      const phone = String(contact?.phone ?? '');
      const normalizedPhone = digitsOnly(phone);

      return (
        name.includes(normalizedSearch) ||
        phone.toLowerCase().includes(normalizedSearch) ||
        (numericSearch.length > 0 && normalizedPhone.includes(numericSearch))
      );
    });
  }, [contacts, search]);

  const selectedIds = useMemo(() => {
    if (mode === MODE_ALL) return allContactIds;
    if (mode === MODE_MANUAL) return manualSelectedIds.filter((id) => availableIdSet.has(id));
    return [];
  }, [mode, allContactIds, manualSelectedIds, availableIdSet]);

  useEffect(() => {
    onSelectedIdsChange?.(selectedIds);
  }, [selectedIds, onSelectedIdsChange]);

  const setNextMode = useCallback((nextMode) => {
    setMode(nextMode);
    onModeChange?.(nextMode);
  }, [onModeChange]);

  const toggleManualContact = useCallback((contactId) => {
    setManualSelectedIds((previous) => {
      const exists = previous.includes(contactId);
      if (exists) return previous.filter((id) => id !== contactId);
      return [...previous, contactId];
    });
  }, []);

  const handleCsvSelected = useCallback((event) => {
    const file = event.target.files?.[0];
    event.target.value = '';
    if (!file) return;
    onImportCsv?.(file);
  }, [onImportCsv]);

  return (
    <section className="contact-selector">
      <p className="contact-selector__label">Contatos</p>

      <label className="contact-selector__option">
        <input
          type="radio"
          name={`contact-selector-mode-${radioGroupId}`}
          value={MODE_ALL}
          checked={mode === MODE_ALL}
          onChange={() => setNextMode(MODE_ALL)}
          disabled={disabled}
        />
        <span>todos contatos</span>
      </label>

      <label className="contact-selector__option">
        <input
          type="radio"
          name={`contact-selector-mode-${radioGroupId}`}
          value={MODE_MANUAL}
          checked={mode === MODE_MANUAL}
          onChange={() => setNextMode(MODE_MANUAL)}
          disabled={disabled}
        />
        <span>selecionar manualmente</span>
      </label>

      <label className="contact-selector__option">
        <input
          type="radio"
          name={`contact-selector-mode-${radioGroupId}`}
          value={MODE_CSV}
          checked={mode === MODE_CSV}
          onChange={() => setNextMode(MODE_CSV)}
          disabled={disabled}
        />
        <span>importar CSV</span>
      </label>

      {mode === MODE_MANUAL ? (
        <div className="contact-selector__manual">
          <input
            type="search"
            className="app-input"
            value={search}
            onChange={(event) => setSearch(event.target.value)}
            placeholder="Buscar por nome ou telefone"
            disabled={disabled}
          />

          <div className="contact-selector__list">
            {filteredContacts.length === 0 ? (
              <p className="contact-selector__empty">Nenhum contato encontrado.</p>
            ) : (
              filteredContacts.map((contact) => {
                const contactId = Number(contact?.id);
                if (!Number.isInteger(contactId) || contactId <= 0) return null;
                const checked = manualSelectedIds.includes(contactId);

                return (
                  <label key={contactId} className="contact-selector__item">
                    <input
                      type="checkbox"
                      checked={checked}
                      onChange={() => toggleManualContact(contactId)}
                      disabled={disabled}
                    />
                    <span>
                      {String(contact?.name ?? 'Sem nome')} - {String(contact?.phone ?? 'Sem telefone')}
                    </span>
                  </label>
                );
              })
            )}
          </div>
        </div>
      ) : null}

      {mode === MODE_CSV ? (
        <div className="contact-selector__csv">
          <input
            type="file"
            accept=".csv,text/csv,text/plain"
            onChange={handleCsvSelected}
            disabled={disabled || importBusy}
          />
          {importBusy ? <p className="contact-selector__hint">Importando contatos...</p> : null}
          {importError ? <p className="contact-selector__error">{importError}</p> : null}
        </div>
      ) : null}

      <p className="contact-selector__hint">Selecionados: {selectedIds.length}</p>
    </section>
  );
}

export default ContactSelector;
