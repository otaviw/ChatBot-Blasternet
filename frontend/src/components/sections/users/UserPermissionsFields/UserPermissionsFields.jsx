import './UserPermissionsFields.css';
import { ALL_PERMISSIONS, PERMISSION_GROUPS } from '@/constants/permissions';

/**
 * Permissions panel shown inside the user create/edit form.
 * Only rendered when the user being configured has the "agent" role.
 *
 * `permissions` is either:
 *   - null  → all defaults (everything checked)
 *   - array → explicit list (only those keys checked)
 */
function UserPermissionsFields({ permissions, onChange }) {
  const effectivePermissions = permissions ?? ALL_PERMISSIONS;

  function isChecked(key) {
    return effectivePermissions.includes(key);
  }

  function toggle(key) {
    const next = isChecked(key)
      ? effectivePermissions.filter((k) => k !== key)
      : [...effectivePermissions, key];
    onChange(next);
  }

  function toggleGroup(groupItems) {
    const keys = groupItems.map((i) => i.key);
    const allChecked = keys.every(isChecked);
    let next;
    if (allChecked) {
      next = effectivePermissions.filter((k) => !keys.includes(k));
    } else {
      next = [...new Set([...effectivePermissions, ...keys])];
    }
    onChange(next);
  }

  function toggleAll() {
    const allChecked = ALL_PERMISSIONS.every(isChecked);
    onChange(allChecked ? [] : [...ALL_PERMISSIONS]);
  }

  const allChecked = ALL_PERMISSIONS.every(isChecked);
  const anyChecked = ALL_PERMISSIONS.some(isChecked);

  return (
    <div className="upf">
      <div className="upf__header">
        <span className="upf__title">Permissões do usuário</span>
        <label className="upf__toggle-all">
          <input
            type="checkbox"
            checked={allChecked}
            ref={(el) => {
              if (el) el.indeterminate = !allChecked && anyChecked;
            }}
            onChange={toggleAll}
          />
          <span>{allChecked ? 'Remover todas' : 'Selecionar todas'}</span>
        </label>
      </div>

      {PERMISSION_GROUPS.map((group) => {
        const groupChecked = group.items.every((i) => isChecked(i.key));
        const groupAny = group.items.some((i) => isChecked(i.key));

        return (
          <div key={group.key} className="upf__group">
            <div className="upf__group-header">
              <label className="upf__group-label">
                <input
                  type="checkbox"
                  checked={groupChecked}
                  ref={(el) => {
                    if (el) el.indeterminate = !groupChecked && groupAny;
                  }}
                  onChange={() => toggleGroup(group.items)}
                />
                <span className="upf__group-name">{group.label}</span>
              </label>
              <span className="upf__group-desc">{group.description}</span>
            </div>

            <div className="upf__items">
              {group.items.map((item) => {
                const dependsOn = item.dependsOn;
                const parentMissing = dependsOn && !isChecked(dependsOn);

                return (
                  <label
                    key={item.key}
                    className={`upf__item ${parentMissing ? 'upf__item--dim' : ''}`}
                    title={parentMissing ? `Requer a página acima habilitada` : undefined}
                  >
                    <input
                      type="checkbox"
                      checked={isChecked(item.key)}
                      onChange={() => toggle(item.key)}
                    />
                    <span className="upf__item-label">{item.label}</span>
                    {parentMissing && (
                      <span className="upf__item-warn">página desabilitada</span>
                    )}
                  </label>
                );
              })}
            </div>
          </div>
        );
      })}
    </div>
  );
}

export default UserPermissionsFields;
