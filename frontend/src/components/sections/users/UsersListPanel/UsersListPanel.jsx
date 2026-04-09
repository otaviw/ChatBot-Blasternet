import './UsersListPanel.css';
import Button from '@/components/ui/Button/Button.jsx';
import EmptyState from '@/components/ui/EmptyState/EmptyState.jsx';

function UsersListPanel({ users, roleLabel, onEdit, onDelete, showCompany = false }) {
  if (!users.length) {
    return (
      <EmptyState
        title="Nenhum usuario cadastrado"
        subtitle="Crie o primeiro usuario para comecar a gerenciar acessos."
      />
    );
  }

  return (
    <ul className="space-y-2 text-sm mb-4 max-h-80 overflow-y-auto pr-1">
      {users.map((user) => (
        <li key={user.id} className="rounded-xl border border-[#d7dce6] bg-white p-3">
          <div className="flex items-start justify-between gap-3">
            <div className="min-w-0">
              <p className="font-medium text-[#0f172a]">
                {user.name} ({roleLabel(user.role)})
                {!user.is_active ? ' [inativo]' : ''}
              </p>
              <p className="text-xs text-[#64748b]">{user.email}</p>
              {showCompany && user.company?.name ? (
                <p className="text-xs text-[#64748b]">Empresa: {user.company.name}</p>
              ) : null}
              <p className="text-xs text-[#64748b]">
                Areas:{' '}
                {Array.isArray(user.areas) && user.areas.length ? user.areas.join(', ') : '-'}
              </p>
            </div>
            <div className="flex flex-col items-end gap-1 flex-shrink-0">
              <Button type="button" variant="secondary" className="px-3 py-1.5" onClick={() => onEdit(user)}>
                Editar
              </Button>
              {onDelete ? (
                <Button
                  type="button"
                  variant="danger"
                  className="px-3 py-1 text-xs"
                  onClick={() => onDelete(user)}
                >
                  Excluir
                </Button>
              ) : null}
            </div>
          </div>
        </li>
      ))}
    </ul>
  );
}

export default UsersListPanel;
