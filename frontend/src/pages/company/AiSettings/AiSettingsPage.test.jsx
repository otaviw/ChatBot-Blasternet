import { act } from 'react';
import { createRoot } from 'react-dom/client';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import AiSettingsPage from './AiSettingsPage.jsx';

const mockUsePageData = vi.fn();
const mockUseAuth = vi.fn();
const mockUseLogout = vi.fn();
const mockUseAdminCompanySelector = vi.fn();
const mockUseAiSettings = vi.fn();
const mockApiPost = vi.fn();
const mockSaveSettingsChanges = vi.fn();

vi.mock('@/hooks/usePageData', () => ({ default: (...args) => mockUsePageData(...args) }));
vi.mock('@/components/layout/Layout/Layout.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('@/hooks/useAuth', () => ({ default: () => mockUseAuth() }));
vi.mock('@/hooks/useLogout', () => ({ default: () => mockUseLogout() }));
vi.mock('@/hooks/useAdminCompanySelector', () => ({ default: (...args) => mockUseAdminCompanySelector(...args) }));
vi.mock('./hooks/useAiSettings', () => ({ default: (...args) => mockUseAiSettings(...args) }));
vi.mock('@/services/api', () => ({ default: { post: (...args) => mockApiPost(...args) } }));

function mount(ui) {
  const container = document.createElement('div');
  document.body.appendChild(container);
  const root = createRoot(container);
  act(() => {
    root.render(ui);
  });
  return { container, root };
}

describe('AiSettingsPage', () => {
  let mounted;

  beforeEach(() => {
    globalThis.IS_REACT_ACT_ENVIRONMENT = true;
  });

  afterEach(() => {
    mockUsePageData.mockReset();
    mockUseAuth.mockReset();
    mockUseLogout.mockReset();
    mockUseAdminCompanySelector.mockReset();
    mockUseAiSettings.mockReset();
    mockApiPost.mockReset();
    mockSaveSettingsChanges.mockReset();

    if (mounted) {
      act(() => mounted.root.unmount());
      mounted.container.remove();
      mounted = null;
    }
  });

  it('exibe erro quando /me não está autenticado', () => {
    mockUsePageData.mockReturnValue({ data: { authenticated: false }, loading: false, error: null });
    mockUseAuth.mockReturnValue({ user: { role: 'company_admin' } });
    mockUseLogout.mockReturnValue({ logout: vi.fn() });
    mockUseAdminCompanySelector.mockReturnValue({ companies: [], selectedCompanyId: '', setSelectedCompanyId: vi.fn() });
    mockUseAiSettings.mockReturnValue({});

    mounted = mount(<AiSettingsPage />);
    expect(mounted.container.textContent).toContain('Não foi possível carregar as configurações de IA.');
  });

  it('renderiza estado vazio de utilizadores e aciona salvar alterações', async () => {
    mockUsePageData.mockReturnValue({
      data: { authenticated: true, user: { role: 'company_admin', company_id: 10, can_manage_users: true } },
      loading: false,
      error: null,
    });
    mockUseAuth.mockReturnValue({ user: { role: 'company_admin' } });
    mockUseLogout.mockReturnValue({ logout: vi.fn() });
    mockUseAdminCompanySelector.mockReturnValue({ companies: [], selectedCompanyId: '', setSelectedCompanyId: vi.fn() });
    mockUseAiSettings.mockReturnValue({
      company: { name: 'Empresa X' },
      settings: {
        ai_enabled: true,
        ai_internal_chat_enabled: true,
        ai_persona: '',
        ai_tone: 'casual',
        ai_language: 'pt-BR',
        ai_formality: 'media',
        ai_system_prompt: '',
        ai_usage_enabled: false,
        ai_usage_limit_monthly: null,
      },
      users: [],
      loading: false,
      error: '',
      saving: false,
      canSave: true,
      permissionBusyById: {},
      updateField: vi.fn(),
      saveSettingsChanges: mockSaveSettingsChanges,
      toggleUserPermission: vi.fn(),
    });

    mounted = mount(<AiSettingsPage />);
    expect(mounted.container.textContent).toContain('Nenhum utilizador encontrado');

    const saveButton = [...mounted.container.querySelectorAll('button')]
      .find((btn) => btn.textContent.includes('Salvar'));
    expect(saveButton).toBeTruthy();

    await act(async () => {
      saveButton.click();
    });
    expect(mockSaveSettingsChanges).toHaveBeenCalledTimes(1);
  });
});
