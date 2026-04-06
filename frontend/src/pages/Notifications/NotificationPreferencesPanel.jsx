import { useCallback, useEffect, useRef, useState } from 'react';
import notificationService from '@/services/notificationService';

const TYPE_LABELS = {
  customer_message: { label: 'Nova mensagem do cliente', module: 'Conversas' },
  conversation_transferred: { label: 'Conversa transferida para você', module: 'Conversas' },
  conversation_closed: { label: 'Conversa encerrada', module: 'Conversas' },
  support_ticket_created: { label: 'Nova solicitação de suporte aberta', module: 'Chamados' },
  support_ticket_message: { label: 'Nova mensagem em chamado', module: 'Chamados' },
  support_ticket_closed: { label: 'Chamado encerrado', module: 'Chamados' },
  internal_chat_message: { label: 'Nova mensagem no chat interno', module: 'Chat interno' },
  chat_participant_added: { label: 'Você foi adicionado a um grupo', module: 'Chat interno' },
};

function groupByModule(allTypes, preferences) {
  const groups = {};
  for (const type of allTypes) {
    const meta = TYPE_LABELS[type] ?? { label: type, module: 'Outros' };
    if (!groups[meta.module]) groups[meta.module] = [];
    groups[meta.module].push({ type, label: meta.label, enabled: preferences[type] !== false });
  }
  return groups;
}

export default function NotificationPreferencesPanel({ open, onClose }) {
  const [preferences, setPreferences] = useState({});
  const [allTypes, setAllTypes] = useState([]);
  const [loading, setLoading] = useState(false);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');
  const [successMsg, setSuccessMsg] = useState('');
  const panelRef = useRef(null);

  const load = useCallback(async () => {
    setLoading(true);
    setError('');
    try {
      const data = await notificationService.getPreferences();
      setPreferences(data.preferences ?? {});
      setAllTypes(data.all_types ?? []);
    } catch {
      setError('Não foi possível carregar as preferências.');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    if (open) {
      void load();
      setSuccessMsg('');
    }
  }, [open, load]);

  useEffect(() => {
    if (!open) return;
    function handleKey(e) {
      if (e.key === 'Escape') onClose();
    }
    function handleClick(e) {
      if (panelRef.current && !panelRef.current.contains(e.target)) onClose();
    }
    document.addEventListener('keydown', handleKey);
    document.addEventListener('mousedown', handleClick);
    return () => {
      document.removeEventListener('keydown', handleKey);
      document.removeEventListener('mousedown', handleClick);
    };
  }, [open, onClose]);

  const handleToggle = (type) => {
    setPreferences((prev) => ({ ...prev, [type]: !prev[type] }));
    setSuccessMsg('');
  };

  const handleSave = async () => {
    setSaving(true);
    setError('');
    setSuccessMsg('');
    try {
      const data = await notificationService.updatePreferences(preferences);
      setPreferences(data.preferences ?? preferences);
      setSuccessMsg('Preferências salvas com sucesso.');
    } catch {
      setError('Não foi possível salvar as preferências.');
    } finally {
      setSaving(false);
    }
  };

  const handleEnableAll = () => {
    const all = {};
    for (const t of allTypes) all[t] = true;
    setPreferences(all);
    setSuccessMsg('');
  };

  const handleDisableAll = () => {
    const all = {};
    for (const t of allTypes) all[t] = false;
    setPreferences(all);
    setSuccessMsg('');
  };

  if (!open) return null;

  const groups = groupByModule(allTypes, preferences);

  return (
    <div className="notif-prefs-overlay" role="dialog" aria-modal="true" aria-label="Preferências de notificações">
      <div className="notif-prefs-panel" ref={panelRef}>
        <div className="notif-prefs-header">
          <h2 className="notif-prefs-title">Preferências de notificações</h2>
          <button type="button" className="notif-prefs-close" onClick={onClose} aria-label="Fechar">
            ✕
          </button>
        </div>

        <p className="notif-prefs-subtitle">
          Escolha quais tipos de notificação você deseja receber. As alterações valem para novas notificações.
        </p>

        {loading ? (
          <p className="notif-prefs-loading">Carregando...</p>
        ) : (
          <>
            <div className="notif-prefs-bulk">
              <button type="button" className="app-btn-secondary text-xs" onClick={handleEnableAll}>
                Ativar todas
              </button>
              <button type="button" className="app-btn-secondary text-xs" onClick={handleDisableAll}>
                Desativar todas
              </button>
            </div>

            <div className="notif-prefs-groups">
              {Object.entries(groups).map(([module, items]) => (
                <div key={module} className="notif-prefs-group">
                  <h3 className="notif-prefs-group-title">{module}</h3>
                  <ul className="notif-prefs-list">
                    {items.map(({ type, label, enabled }) => (
                      <li key={type} className="notif-prefs-item">
                        <label className="notif-prefs-label">
                          <span className="notif-prefs-toggle-wrap">
                            <input
                              type="checkbox"
                              checked={enabled}
                              onChange={() => handleToggle(type)}
                              className="notif-prefs-checkbox"
                            />
                            <span className={`notif-prefs-toggle${enabled ? ' notif-prefs-toggle--on' : ''}`} />
                          </span>
                          <span className="notif-prefs-label-text">{label}</span>
                        </label>
                      </li>
                    ))}
                  </ul>
                </div>
              ))}
            </div>

            {error ? <p className="notif-prefs-error">{error}</p> : null}
            {successMsg ? <p className="notif-prefs-success">{successMsg}</p> : null}

            <div className="notif-prefs-footer">
              <button type="button" className="app-btn-secondary text-sm" onClick={onClose}>
                Cancelar
              </button>
              <button
                type="button"
                className="app-btn-primary text-sm"
                onClick={handleSave}
                disabled={saving}
              >
                {saving ? 'Salvando...' : 'Salvar preferências'}
              </button>
            </div>
          </>
        )}
      </div>
    </div>
  );
}
