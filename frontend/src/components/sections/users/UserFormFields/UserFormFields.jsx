import './UserFormFields.css';
import AreasSelector from '@/components/sections/users/AreasSelector/AreasSelector.jsx';
import { CheckboxField, Field, SelectInput, TextInput } from '@/components/ui/FormControls/FormControls.jsx';

function UserFormFields({
  form,
  setForm,
  roleOptions,
  passwordRequired,
  passwordPlaceholder,
  showCompanyField = false,
  companies = [],
  onCompanyChange,
  showAreas = false,
  availableAreas = [],
  onToggleArea,
  areaEmptyMessage = 'Nenhuma area disponível.',
  showAiPermissionField = false,
  showAppointmentFields = false,
}) {
  const updateForm = (patch) => {
    setForm((prev) => ({ ...prev, ...patch }));
  };

  return (
    <>
      <Field label="Nome">
        <TextInput
          type="text"
          placeholder="Nome completo"
          value={form.name}
          onChange={(event) => updateForm({ name: event.target.value })}
          required
        />
      </Field>

      {showAppointmentFields && (
        <Field label="Nome do atendente">
          <TextInput
            type="text"
            placeholder="Ex.: Dra. Ana / Joao - Suporte"
            value={form.appointment_display_name ?? ''}
            onChange={(event) => updateForm({ appointment_display_name: event.target.value })}
          />
        </Field>
      )}

      <Field label="E-mail">
        <TextInput
          type="email"
          placeholder="nome@empresa.com"
          value={form.email}
          onChange={(event) => updateForm({ email: event.target.value })}
          required
        />
      </Field>

      <Field label="Senha">
        <TextInput
          type="password"
          placeholder={passwordPlaceholder}
          value={form.password}
          onChange={(event) => updateForm({ password: event.target.value })}
          required={passwordRequired}
        />
      </Field>

      <Field label="Perfil">
        <SelectInput
          value={form.role}
          onChange={(event) =>
            updateForm({
              role: event.target.value,
              company_id: '',
              areas: [],
              can_use_ai: event.target.value === 'agent' ? Boolean(form.can_use_ai) : false,
            })
          }
        >
          {roleOptions.map((role) => (
            <option key={role.value} value={role.value}>
              {role.label}
            </option>
          ))}
        </SelectInput>
      </Field>

      {showAppointmentFields && (
        <CheckboxField
          checked={Boolean(form.appointment_is_staff)}
          onChange={(event) => updateForm({ appointment_is_staff: event.target.checked })}
        >
          Pode atender agendamentos
        </CheckboxField>
      )}

      {showCompanyField && (
        <Field label="Empresa">
          <SelectInput
            value={form.company_id}
            onChange={(event) => onCompanyChange(event.target.value)}
            required
          >
            <option value="">Selecione empresa</option>
            {companies.map((company) => (
              <option key={company.id} value={company.id}>
                {company.name}
              </option>
            ))}
          </SelectInput>
        </Field>
      )}

      {showAreas && (
        <AreasSelector
          areas={availableAreas}
          selectedAreas={form.areas ?? []}
          onToggle={onToggleArea}
          emptyMessage={areaEmptyMessage}
        />
      )}

      {/* {showAiPermissionField && (
        <CheckboxField
          checked={Boolean(form.can_use_ai)}
          onChange={(event) => updateForm({ can_use_ai: event.target.checked })}
        >
          Permitir usar IA interna
        </CheckboxField>
      )} */}

      <CheckboxField
        checked={Boolean(form.is_active)}
        onChange={(event) => updateForm({ is_active: event.target.checked })}
      >
        Usuario ativo
      </CheckboxField>
    </>
  );
}

export default UserFormFields;
