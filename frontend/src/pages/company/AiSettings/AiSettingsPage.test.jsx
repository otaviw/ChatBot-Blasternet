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

const baseSettings = {
  ai_enabled: true,
  ai_internal_chat_enabled: true,
  ai_chatbot_enabled: true,
  ai_chatbot_auto_reply_enabled: false,
  ai_chatbot_mode: 'always',
  ai_chatbot_shadow_mode: false,
  ai_chatbot_sandbox_enabled: false,
  ai_chatbot_test_numbers: [],
  ai_chatbot_confidence_threshold: 0.75,
  ai_chatbot_handoff_repeat_limit: 2,
  ai_chatbot_rules: [],
  ai_persona: '',
  ai_tone: 'casual',
  ai_language: 'pt-BR',
  ai_formality: 'media',
  ai_system_prompt: '',
  ai_usage_enabled: false,
  ai_usage_limit_monthly: null,
  ai_temperature: null,
  ai_max_context_messages: null,
  ai_max_response_tokens: null,
  ai_provider: '',
  ai_model: '',
};

function mount(ui) {
  const container = document.createElement('div');
  document.body.appendChild(container);
  const root = createRoot(container);
  act(() => {
    root.render(ui);
  });
  return { container, root };
}

function findButton(container, label) {
  return [...container.querySelectorAll('button')]
    .find((btn) => btn.textContent.includes(label));
}

function mockAuthenticatedPage(settings = baseSettings, overrides = {}) {
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
    settings,
    users: [],
    loading: false,
    error: '',
    saving: false,
    canSave: true,
    permissionBusyById: {},
    updateField: vi.fn(),
    saveSettingsChanges: mockSaveSettingsChanges,
    toggleUserPermission: vi.fn(),
    ...overrides,
  });
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

  it('exibe erro quando /me nao esta autenticado', () => {
    mockUsePageData.mockReturnValue({ data: { authenticated: false }, loading: false, error: null });
    mockUseAuth.mockReturnValue({ user: { role: 'company_admin' } });
    mockUseLogout.mockReturnValue({ logout: vi.fn() });
    mockUseAdminCompanySelector.mockReturnValue({ companies: [], selectedCompanyId: '', setSelectedCompanyId: vi.fn() });
    mockUseAiSettings.mockReturnValue({});

    mounted = mount(<AiSettingsPage />);
    expect(mounted.container.textContent).toContain('Nao foi possivel carregar as configuracoes de IA.');
  });

  it('renderiza wizard, estado salvo e aciona salvar em configuracao valida', async () => {
    mockAuthenticatedPage();

    mounted = mount(<AiSettingsPage />);

    expect(mounted.container.textContent).toContain('Wizard de configuracao IA');
    expect(mounted.container.textContent).toContain('IA no bot do WhatsApp');
    expect(mounted.container.textContent).toContain('Nenhum usuario encontrado');

    const saveButton = findButton(mounted.container, 'Salvar configuracao');
    expect(saveButton).toBeTruthy();

    await act(async () => {
      saveButton.click();
    });

    expect(mockSaveSettingsChanges).toHaveBeenCalledTimes(1);
  });

  it('bloqueia salvamento quando combinacao critica e invalida', async () => {
    mockAuthenticatedPage({
      ...baseSettings,
      ai_enabled: false,
      ai_chatbot_enabled: true,
      ai_chatbot_mode: 'always',
    });

    mounted = mount(<AiSettingsPage />);

    const saveButton = findButton(mounted.container, 'Salvar configuracao');
    await act(async () => {
      saveButton.click();
    });

    expect(mounted.container.textContent).toContain('Para usar IA no bot, ative primeiro a IA da empresa.');
    expect(mockSaveSettingsChanges).not.toHaveBeenCalled();
  });

  it('navega pelos passos numerados do wizard', async () => {
    mockAuthenticatedPage();

    mounted = mount(<AiSettingsPage />);

    const nextButton = findButton(mounted.container, 'Proximo passo');
    expect(nextButton).toBeTruthy();

    await act(async () => {
      nextButton.click();
    });

    expect(mounted.container.textContent).toContain('Prompt do sistema');

    await act(async () => {
      nextButton.click();
    });

    expect(mounted.container.textContent).toContain('Confianca minima');
  });
});
