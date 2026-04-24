import { useEffect, useMemo, useState } from 'react';
import Layout from '@/components/layout/Layout/Layout.jsx';
import PageLoading from '@/components/ui/PageLoading/PageLoading.jsx';
import useLogout from '@/hooks/useLogout';
import usePageData from '@/hooks/usePageData';
import api from '@/services/api';

function extractFirstValidationError(error) {
  const validationErrors = error?.validationErrors;
  if (!validationErrors || typeof validationErrors !== 'object') {
    return '';
  }

  for (const messages of Object.values(validationErrors)) {
    if (Array.isArray(messages) && messages.length > 0) {
      const first = String(messages[0] ?? '').trim();
      if (first) {
        return first;
      }
    }
  }

  return '';
}

function normalizeSlugInput(value) {
  return String(value ?? '')
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .trim()
    .toLowerCase()
    .replace(/\s+/g, '-')
    .replace(/[^a-z0-9-]+/g, '-')
    .replace(/-+/g, '-')
    .replace(/^-|-$/g, '');
}

function normalizeColorInput(value) {
  const raw = String(value ?? '')
    .toLowerCase()
    .replace(/\s+/g, '')
    .replace(/^#+/, '');

  if (!raw) {
    return '';
  }

  const onlyHexChars = raw.replace(/[^0-9a-f]/g, '').slice(0, 6);
  return onlyHexChars ? `#${onlyHexChars}` : '';
}

function normalizeNullableHexColor(value) {
  const normalized = normalizeColorInput(value);
  if (/^#[0-9a-f]{6}$/.test(normalized)) {
    return normalized;
  }

  return '';
}

function AdminMyResellerPage() {
  const { data, setData, loading, error } = usePageData('/admin/minha-revenda');
  const { logout } = useLogout();
  const [saving, setSaving] = useState(false);
  const [submitError, setSubmitError] = useState('');
  const [submitSuccess, setSubmitSuccess] = useState('');
  const [form, setForm] = useState({
    name: '',
    slug: '',
    primary_color: '',
    logo: null,
  });
  const [logoPreviewUrl, setLogoPreviewUrl] = useState('');

  useEffect(() => {
    const reseller = data?.reseller;
    if (!reseller) {
      return;
    }

    setForm({
      name: String(reseller?.name ?? ''),
      slug: normalizeSlugInput(reseller?.slug),
      primary_color: normalizeNullableHexColor(reseller?.primary_color),
      logo: null,
    });
  }, [data?.reseller]);

  useEffect(() => {
    if (!(form.logo instanceof File)) {
      setLogoPreviewUrl('');
      return undefined;
    }

    const objectUrl = URL.createObjectURL(form.logo);
    setLogoPreviewUrl(objectUrl);

    return () => {
      URL.revokeObjectURL(objectUrl);
    };
  }, [form.logo]);

  const canSubmit = useMemo(() => {
    const hasName = String(form.name ?? '').trim() !== '';
    const hasSlug = String(form.slug ?? '').trim() !== '';
    const validColor = form.primary_color === '' || /^#[0-9a-f]{6}$/.test(form.primary_color);
    return hasName && hasSlug && validColor && !saving;
  }, [form.name, form.primary_color, form.slug, saving]);

  const visibleLogo = logoPreviewUrl || String(data?.reseller?.logo_url ?? '').trim();
  const hasColorError = form.primary_color !== '' && !/^#[0-9a-f]{6}$/.test(form.primary_color);
  const colorPickerValue = hasColorError
    ? '#2563eb'
    : normalizeNullableHexColor(form.primary_color) || '#2563eb';

  const handleSubmit = async (event) => {
    event.preventDefault();
    setSubmitError('');
    setSubmitSuccess('');

    const normalizedColor = normalizeNullableHexColor(form.primary_color);
    const hasInvalidColor = form.primary_color !== '' && normalizedColor === '';
    if (hasInvalidColor) {
      setSubmitError('A cor primaria deve estar no formato #RRGGBB.');
      return;
    }

    setSaving(true);

    const formData = new FormData();
    formData.append('name', String(form.name ?? '').trim());
    formData.append('slug', normalizeSlugInput(form.slug));
    formData.append('primary_color', normalizedColor);
    if (form.logo instanceof File) {
      formData.append('logo', form.logo);
    }
    formData.append('_method', 'PUT');

    try {
      const response = await api.post('/admin/minha-revenda', formData, {
        headers: { 'Content-Type': 'multipart/form-data' },
      });

      const reseller = response?.data?.reseller;
      if (reseller) {
        setData((previous) => ({
          ...(previous && typeof previous === 'object' ? previous : {}),
          ok: true,
          reseller,
        }));
      }

      setForm((previous) => ({
        ...previous,
        primary_color: normalizeNullableHexColor(reseller?.primary_color),
        logo: null,
      }));
      setSubmitSuccess('Dados da revenda atualizados com sucesso.');
    } catch (err) {
      const validationMessage = extractFirstValidationError(err);
      setSubmitError(validationMessage || err?.message || 'Falha ao salvar os dados da revenda.');
    } finally {
      setSaving(false);
    }
  };

  if (loading) {
    return (
      <Layout role="admin" onLogout={logout}>
        <PageLoading rows={2} cards={1} />
      </Layout>
    );
  }

  if (error || !data?.reseller) {
    return (
      <Layout role="admin" onLogout={logout}>
        <p className="text-sm text-red-600">Nao foi possivel carregar os dados da sua revenda.</p>
      </Layout>
    );
  }

  return (
    <Layout role="admin" onLogout={logout}>
      <h1 className="app-page-title">Minha revenda</h1>
      <p className="app-page-subtitle mb-6">
        Atualize os dados da sua revenda. O slug e normalizado automaticamente.
      </p>

      <section className="app-panel">
        {submitError ? <p className="text-sm text-red-600 mb-3">{submitError}</p> : null}
        {submitSuccess ? <p className="text-sm text-green-700 mb-3">{submitSuccess}</p> : null}

        <form onSubmit={handleSubmit} className="grid grid-cols-1 md:grid-cols-2 gap-3">
          <label className="block text-sm md:col-span-2">
            Nome
            <input
              type="text"
              value={form.name}
              onChange={(event) => setForm((previous) => ({ ...previous, name: event.target.value }))}
              required
              className="app-input"
            />
          </label>

          <label className="block text-sm md:col-span-2">
            Slug
            <input
              type="text"
              value={form.slug}
              onChange={(event) => setForm((previous) => ({ ...previous, slug: normalizeSlugInput(event.target.value) }))}
              required
              className="app-input"
              placeholder="minha-revenda"
            />
          </label>

          <div className="block text-sm md:col-span-2">
            <p>Logo</p>
            <div className="mt-1.5 flex flex-wrap items-center gap-3">
              <div className="h-16 w-16 rounded-lg border border-[#e5e5e5] bg-[#fafafa] overflow-hidden flex items-center justify-center">
                {visibleLogo ? (
                  <img src={visibleLogo} alt="Preview da logo da revenda" className="h-full w-full object-cover" />
                ) : (
                  <span className="text-xs text-[#737373]">Sem logo</span>
                )}
              </div>

              <div className="min-w-[240px] flex-1">
                <input
                  type="file"
                  accept="image/*"
                  onChange={(event) =>
                    setForm((previous) => ({ ...previous, logo: event.target.files?.[0] ?? null }))
                  }
                  className="app-input !mt-0"
                />
                <p className="app-helper mt-1">A imagem atual so muda se um novo arquivo for selecionado.</p>
              </div>

              {form.logo ? (
                <button
                  type="button"
                  className="app-btn-secondary"
                  onClick={() => setForm((previous) => ({ ...previous, logo: null }))}
                >
                  Remover selecao
                </button>
              ) : null}
            </div>
          </div>

          <label className="block text-sm md:col-span-2">
            Cor primaria
            <div className="mt-1.5 flex flex-wrap items-center gap-2">
              <input
                type="color"
                value={colorPickerValue}
                onChange={(event) =>
                  setForm((previous) => ({ ...previous, primary_color: normalizeNullableHexColor(event.target.value) }))
                }
                className="h-10 w-14 rounded border border-[#d4d4d4] bg-white p-1"
                aria-label="Selecionar cor primaria da revenda"
              />
              <input
                type="text"
                value={form.primary_color}
                onChange={(event) =>
                  setForm((previous) => ({ ...previous, primary_color: normalizeColorInput(event.target.value) }))
                }
                className={`app-input !mt-0 max-w-[180px] ${hasColorError ? '!border-red-300' : ''}`}
                placeholder="#2563eb"
              />
              <button
                type="button"
                className="app-btn-secondary"
                onClick={() => setForm((previous) => ({ ...previous, primary_color: '' }))}
              >
                Limpar cor
              </button>
            </div>
            <p className="app-helper mt-1">Opcional. Informe no formato #RRGGBB.</p>
          </label>

          <div className="md:col-span-2">
            <button type="submit" disabled={!canSubmit} className="app-btn-primary">
              {saving ? 'Salvando...' : 'Salvar alteracoes'}
            </button>
          </div>
        </form>
      </section>
    </Layout>
  );
}

export default AdminMyResellerPage;
