import { act } from 'react';
import { createRoot } from 'react-dom/client';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import CampaignsPage from './CampaignsPage.jsx';

const mockUsePageData = vi.fn();
const mockUseLogout = vi.fn();
const mockUseWhatsAppTemplates = vi.fn();
const mockApiGet = vi.fn();
const mockApiPost = vi.fn();
const mockShowSuccess = vi.fn();
const mockShowError = vi.fn();
const mockRun = vi.fn();

vi.mock('@/hooks/usePageData', () => ({ default: (...args) => mockUsePageData(...args) }));
vi.mock('@/components/layout/Layout/Layout.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('@/hooks/useLogout', () => ({ default: () => mockUseLogout() }));
vi.mock('@/hooks/useWhatsAppTemplates', () => ({ default: () => mockUseWhatsAppTemplates() }));
vi.mock('@/hooks/useAsync', () => ({ default: () => ({ loading: false, error: null, run: (...args) => mockRun(...args) }) }));
vi.mock('@/services/api', () => ({ default: { get: (...args) => mockApiGet(...args), post: (...args) => mockApiPost(...args) } }));
vi.mock('@/services/realtimeClient', () => ({ default: { on: () => () => {} } }));
vi.mock('@/services/toastService', () => ({ showSuccess: (...args) => mockShowSuccess(...args), showError: (...args) => mockShowError(...args) }));

function mount(ui) {
  const container = document.createElement('div');
  document.body.appendChild(container);
  const root = createRoot(container);
  act(() => root.render(ui));
  return { container, root };
}

describe('CampaignsPage', () => {
  let mounted;

  beforeEach(() => {
    globalThis.IS_REACT_ACT_ENVIRONMENT = true;
  });

  afterEach(() => {
    mockUsePageData.mockReset();
    mockUseLogout.mockReset();
    mockUseWhatsAppTemplates.mockReset();
    mockApiGet.mockReset();
    mockApiPost.mockReset();
    mockShowSuccess.mockReset();
    mockShowError.mockReset();
    mockRun.mockReset();

    if (mounted) {
      act(() => mounted.root.unmount());
      mounted.container.remove();
      mounted = null;
    }
  });

  it('mostra estado vazio quando não há campanhas', async () => {
    mockUsePageData.mockImplementation((path) => {
      if (path === '/me') return { data: { authenticated: true, user: { company_name: 'Acme' } }, loading: false, error: null };
      return { data: null, loading: false, error: null };
    });
    mockUseLogout.mockReturnValue({ logout: vi.fn() });
    mockUseWhatsAppTemplates.mockReturnValue({ templates: [], templatesLoading: false, templatesError: '', loadTemplates: vi.fn() });
    mockRun.mockImplementation(async (fn) => ({ data: await fn(), error: null }));
    mockApiGet.mockResolvedValue({ data: { campaigns: [] } });

    mounted = mount(<CampaignsPage />);
    await act(async () => { await Promise.resolve(); });
    expect(mounted.container.textContent).toContain('Nenhuma campanha encontrada');
  });

  it('aciona início de campanha draft', async () => {
    mockUsePageData.mockImplementation((path) => {
      if (path === '/me') return { data: { authenticated: true, user: { company_name: 'Acme' } }, loading: false, error: null };
      return { data: null, loading: false, error: null };
    });
    mockUseLogout.mockReturnValue({ logout: vi.fn() });
    mockUseWhatsAppTemplates.mockReturnValue({ templates: [], templatesLoading: false, templatesError: '', loadTemplates: vi.fn() });
    mockRun.mockImplementation(async (fn) => ({ data: await fn(), error: null }));
    mockApiGet.mockResolvedValue({
      data: {
        campaigns: [{ id: 11, name: 'Campanha 1', type: 'free', status: 'draft', created_at: '2026-01-01T10:00:00Z', sent_count: 0, failed_count: 0, skipped_count: 0 }],
      },
    });
    mockApiPost.mockResolvedValue({ data: {} });

    mounted = mount(<CampaignsPage />);
    await act(async () => { await Promise.resolve(); });

    const startButton = [...mounted.container.querySelectorAll('button')]
      .find((btn) => btn.textContent.includes('Iniciar campanha'));
    expect(startButton).toBeTruthy();

    await act(async () => {
      startButton.click();
      await Promise.resolve();
    });

    expect(mockApiPost).toHaveBeenCalledWith('/campaigns/11/start');
  });
});
