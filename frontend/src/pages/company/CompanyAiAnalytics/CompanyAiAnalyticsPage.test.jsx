import { act } from 'react';
import { createRoot } from 'react-dom/client';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import CompanyAiAnalyticsPage from './CompanyAiAnalyticsPage.jsx';

const mockUsePageData = vi.fn();
const mockUseAuth = vi.fn();
const mockUseLogout = vi.fn();
const mockUseAdminCompanySelector = vi.fn();

vi.mock('@/hooks/usePageData', () => ({ default: (...args) => mockUsePageData(...args) }));
vi.mock('@/hooks/useAuth', () => ({ default: () => mockUseAuth() }));
vi.mock('@/hooks/useLogout', () => ({ default: () => mockUseLogout() }));
vi.mock('@/hooks/useAdminCompanySelector', () => ({ default: (...args) => mockUseAdminCompanySelector(...args) }));
vi.mock('@/components/layout/Layout/Layout.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));

const dashboardPayload = {
  authenticated: true,
  is_admin: false,
  selected_company_id: 10,
  range: { from: '2026-05-01', to: '2026-05-19' },
  filters: { channel: 'all', area_id: null, flow: null },
  filter_options: {
    areas: [{ id: 3, name: 'Suporte' }],
    flows: ['main', 'ixc_invoice'],
  },
  export_urls: {
    json: '/api/minha-conta/ia/analytics?export=json',
    csv: '/api/minha-conta/ia/analytics?export=csv',
  },
  summary: {
    provider_requests: 12,
    ok_count: 11,
    error_count: 1,
    chatbot_decisions: 9,
    avg_confidence: 0.82,
    resolution_rate_pct: 66.67,
    resolved_count: 6,
    handoff_rate_pct: 22.22,
    handoff_count: 2,
    handoff_incapacity_count: 1,
    total_tokens: 1500,
    chatbot_decision_tokens: 300,
    estimated_cost: 0.015,
    estimated_cost_currency: 'USD',
    estimated_cost_per_1k_tokens: 0.01,
    failure_rate_pct: 4.76,
    failure_count: 1,
    avg_response_time_ms: 540,
    avg_decision_latency_ms: 180,
  },
  daily: [
    {
      date: '2026-05-19',
      label: '19/05',
      provider_requests: 12,
      chatbot_decisions: 9,
      tokens: 1500,
      handoffs: 2,
      failures: 1,
    },
  ],
  top_intents: [{ intent: 'agendamento', total: 5, handoffs: 1, avg_confidence: 0.9 }],
  handoff_by_type: [
    { type: 'menu', count: 1 },
    { type: 'incapacity', count: 1 },
  ],
  handoff_reasons: [{ reason: 'outside_company_scope', count: 1 }],
  bottlenecks_by_flow: [{ flow: 'ixc_invoice', total: 4, handoffs: 2, failures: 1, avg_confidence: 0.7 }],
  by_provider: [{ provider: 'test', total: 12, error: 1, avg_ms: 540, tokens: 1500 }],
  by_feature: [{ feature: 'chatbot', total: 12, error: 1, avg_ms: 540, tokens: 1500 }],
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

describe('CompanyAiAnalyticsPage', () => {
  let mounted;

  beforeEach(() => {
    globalThis.IS_REACT_ACT_ENVIRONMENT = true;
    mockUseAuth.mockReturnValue({ user: { role: 'company_admin' } });
    mockUseLogout.mockReturnValue({ logout: vi.fn() });
    mockUseAdminCompanySelector.mockReturnValue({
      companies: [],
      selectedCompanyId: '',
      setSelectedCompanyId: vi.fn(),
    });
    mockUsePageData.mockReturnValue({
      data: dashboardPayload,
      loading: false,
      error: null,
    });
  });

  afterEach(() => {
    mockUsePageData.mockReset();
    mockUseAuth.mockReset();
    mockUseLogout.mockReset();
    mockUseAdminCompanySelector.mockReset();

    if (mounted) {
      act(() => mounted.root.unmount());
      mounted.container.remove();
      mounted = null;
    }
  });

  it('renderiza consumo, qualidade, handoff e export do dashboard', () => {
    mounted = mount(<CompanyAiAnalyticsPage />);

    expect(mounted.container.textContent).toContain('Dashboard IA');
    expect(mounted.container.textContent).toContain('Chamadas ao provider');
    expect(mounted.container.textContent).toContain('Resolucao estimada');
    expect(mounted.container.textContent).toContain('Handoff por incapacidade');
    expect(mounted.container.textContent).toContain('agendamento');
    expect(mounted.container.textContent).toContain('ixc_invoice');

    const links = [...mounted.container.querySelectorAll('a')].map((link) => link.textContent);
    expect(links).toContain('Export CSV');
    expect(links).toContain('Export JSON');
  });

  it('inclui filtros de canal, area e fluxo na URL carregada', async () => {
    mounted = mount(<CompanyAiAnalyticsPage />);

    const selects = mounted.container.querySelectorAll('select');
    const channelSelect = selects[0];
    const areaSelect = selects[1];
    const flowSelect = selects[2];

    await act(async () => {
      channelSelect.value = 'whatsapp';
      channelSelect.dispatchEvent(new Event('change', { bubbles: true }));
    });

    await act(async () => {
      areaSelect.value = '3';
      areaSelect.dispatchEvent(new Event('change', { bubbles: true }));
    });

    await act(async () => {
      flowSelect.value = 'ixc_invoice';
      flowSelect.dispatchEvent(new Event('change', { bubbles: true }));
    });

    const lastUrl = mockUsePageData.mock.calls.at(-1)?.[0] ?? '';
    expect(lastUrl).toContain('/minha-conta/ia/analytics');
    expect(lastUrl).toContain('channel=whatsapp');
    expect(lastUrl).toContain('area_id=3');
    expect(lastUrl).toContain('flow=ixc_invoice');
  });
});
