import './MessageSimulatorCard.css';
import Button from '@/components/ui/Button/Button.jsx';
import Card from '@/components/ui/Card/Card.jsx';
import { CheckboxField, Field, SelectInput, TextAreaInput, TextInput } from '@/components/ui/FormControls/FormControls.jsx';

function MessageSimulatorCard({
  title,
  subtitle,
  companies = [],
  companyId = '',
  onCompanyChange,
  from,
  onFromChange,
  text,
  onTextChange,
  imageFile = null,
  onImageChange,
  onRemoveImage,
  sendOutbound,
  onSendOutboundChange,
  onSubmit,
  busy,
  busyLabel,
  submitLabel,
}) {
  return (
    <Card className="max-w-2xl">
      <div className="mb-4">
        <h2 className="text-lg font-semibold text-[#0f172a]">{title}</h2>
        {subtitle ? <p className="text-sm text-[#64748b] mt-1">{subtitle}</p> : null}
      </div>

      <form onSubmit={onSubmit} className="space-y-4">
        {companies.length > 0 && (
          <Field label="Empresa">
            <SelectInput value={companyId} onChange={(event) => onCompanyChange(event.target.value)}>
              {companies.map((company) => (
                <option key={company.id} value={company.id}>
                  {company.name}
                </option>
              ))}
            </SelectInput>
          </Field>
        )}

        <Field label="Telefone do cliente">
          <TextInput type="text" value={from} onChange={(event) => onFromChange(event.target.value)} />
        </Field>

        <Field label="Mensagem recebida">
          <TextAreaInput value={text} onChange={(event) => onTextChange(event.target.value)} rows={4} />
        </Field>

        <Field label="Imagem recebida (opcional)">
          <div className="simulator-upload-row">
            <label className="app-btn-secondary text-xs cursor-pointer">
              Selecionar imagem
              <input
                type="file"
                accept="image/*"
                onChange={(event) => onImageChange?.(event.target.files?.[0] ?? null)}
                className="hidden"
              />
            </label>
            {imageFile ? (
              <button type="button" onClick={onRemoveImage} className="app-btn-danger text-xs">
                Remover
              </button>
            ) : null}
          </div>
          {imageFile ? <p className="simulator-upload-file">{imageFile.name}</p> : null}
        </Field>

        <CheckboxField checked={sendOutbound} onChange={(event) => onSendOutboundChange(event.target.checked)}>
          Tentar envio externo (se tiver credenciais)
        </CheckboxField>

        <Button type="submit" variant="primary" disabled={busy}>
          {busy ? busyLabel : submitLabel}
        </Button>
      </form>
    </Card>
  );
}

export default MessageSimulatorCard;

