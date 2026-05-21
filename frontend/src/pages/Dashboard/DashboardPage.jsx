import './DashboardPage.css';
import { useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import Layout from '@/components/layout/Layout/Layout.jsx';
import {
  ADMIN_MAIN_LINKS,
  ADMIN_SUPPORT_LINKS,
  buildCompanyMainLinks,
  COMPANY_SUPPORT_LINKS,
} from '@/components/layout/Layout/layoutLinks';
import usePageData from '@/hooks/usePageData';
import useLogout from '@/hooks/useLogout';
import Card from '@/components/ui/Card/Card.jsx';
import SkeletonCard from '@/components/ui/SkeletonCard/SkeletonCard.jsx';
import SkeletonText from '@/components/ui/SkeletonText/SkeletonText.jsx';

const ROLE_LABELS = {
  system_admin: 'Administrador do sistema',
  reseller_admin: 'Administrador da revenda',
  company_admin: 'Administrador da empresa',
  agent: 'Atendente',
};

const CATEGORY_LABELS = {
  operation: 'Operação',
  management: 'Gestão',
  settings: 'Configurações',
  administration: 'Administração',
};

const CATEGORY_BY_HREF = {
  '/dashboard': 'operation',
  '/admin/empresas': 'management',
  '/admin/minha-revenda': 'settings',
  '/admin/usuarios': 'administration',
  '/admin/conversas': 'operation',
  '/admin/chat-interno': 'operation',
  '/admin/chat-ia': 'operation',
  '/admin/auditoria': 'administration',
  '/admin/suporte': 'operation',
  '/admin/simulador': 'operation',
  '/suporte': 'operation',
  '/manuais': 'operation',
  '/minha-conta/conversas': 'operation',
  '/minha-conta/chat-interno': 'operation',
  '/minha-conta/chat-ia': 'operation',
  '/minha-conta/agendamentos': 'operation',
  '/minha-conta/contatos': 'management',
  '/minha-conta/respostas-rapidas': 'management',
  '/minha-conta/tags': 'management',
  '/minha-conta/campanhas': 'operation',
  '/minha-conta/bot': 'settings',
  '/minha-conta/ia/configuracoes': 'settings',
  '/minha-conta/ia/analytics': 'management',
  '/minha-conta/ia/auditoria': 'administration',
  '/minha-conta/base-conhecimento': 'management',
  '/minha-conta/ixc/clientes': 'operation',
  '/minha-conta/usuarios': 'administration',
  '/minha-conta/empresa': 'settings',
  '/minha-conta/auditoria': 'administration',
  '/minha-conta/suporte/solicitacoes': 'operation',
};

const DESCRIPTION_BY_HREF = {
  '/dashboard': 'Resumo inicial da sua operação.',
  '/admin/empresas': 'Cadastro, acompanhamento e governança das empresas.',
  '/admin/minha-revenda': 'Dados, marca e configurações da revenda.',
  '/admin/usuarios': 'Pessoas, perfis e acessos da plataforma.',
  '/admin/conversas': 'Atendimento e conversas das empresas vinculadas.',
  '/admin/chat-interno': 'Conversas com a equipe interna.',
  '/admin/chat-ia': 'Assistente de IA para apoio operacional.',
  '/admin/auditoria': 'Eventos e rastros de auditoria administrativa.',
  '/admin/suporte': 'Chamados recebidos pelo suporte.',
  '/suporte': 'Abertura de chamado para o suporte.',
  '/manuais': 'Guias de uso das telas disponíveis.',
  '/minha-conta/conversas': 'Atendimento, etiquetas e transferências da equipe.',
  '/minha-conta/chat-interno': 'Mensagens entre usuários da empresa.',
  '/minha-conta/chat-ia': 'Assistente de IA para apoio ao atendimento.',
  '/minha-conta/agendamentos': 'Agenda, horários e marcações manuais.',
  '/minha-conta/contatos': 'Base de contatos e dados de relacionamento.',
  '/minha-conta/respostas-rapidas': 'Mensagens prontas para acelerar respostas.',
  '/minha-conta/tags': 'Organização das conversas por etiquetas.',
  '/minha-conta/campanhas': 'Envios e listas de campanhas.',
  '/minha-conta/bot': 'Fluxos, mensagens e regras do bot.',
  '/minha-conta/ia/configuracoes': 'Provedores, políticas e uso de IA.',
  '/minha-conta/ia/analytics': 'Indicadores de uso e resultado da IA.',
  '/minha-conta/ia/auditoria': 'Registro de ações e decisões da IA.',
  '/minha-conta/base-conhecimento': 'Conteúdos usados pela IA da empresa.',
  '/minha-conta/ixc/clientes': 'Consulta de clientes e dados da integração IXC.',
  '/minha-conta/usuarios': 'Usuários, permissões e áreas da empresa.',
  '/minha-conta/empresa': 'Dados da empresa e integrações.',
  '/minha-conta/auditoria': 'Eventos de auditoria da empresa.',
  '/minha-conta/suporte/solicitacoes': 'Acompanhamento dos seus chamados.',
};

function formatDateTime(value) {
  if (!value) return 'Ainda não registrado';
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return 'Ainda não registrado';

  return new Intl.DateTimeFormat('pt-BR', {
    dateStyle: 'short',
    timeStyle: 'short',
  }).format(date);
}

function initials(value) {
  return String(value ?? 'B')
    .trim()
    .split(/\s+/)
    .slice(0, 2)
    .map((part) => part[0])
    .join('')
    .toUpperCase() || 'B';
}

function flattenLinks(items) {
  return items.flatMap((item) => item.children ?? [item]);
}

function uniqueLinks(items) {
  const seen = new Set();
  return items.filter((item) => {
    if (!item?.href || seen.has(item.href)) return false;
    seen.add(item.href);
    return true;
  });
}

function buildAdminLinks(data) {
  const userRole = data?.user_role;
  const resellerAllowed = new Set([
    '/dashboard',
    '/admin/empresas',
    '/admin/minha-revenda',
    '/admin/usuarios',
    '/admin/chat-interno',
    '/admin/auditoria',
  ]);
  const systemHidden = new Set(['/admin/conversas', '/admin/auditoria', '/admin/minha-revenda']);

  const main = userRole === 'reseller_admin'
    ? ADMIN_MAIN_LINKS.filter((item) => resellerAllowed.has(item.href))
    : userRole === 'system_admin'
      ? ADMIN_MAIN_LINKS.filter((item) => !systemHidden.has(item.href))
      : ADMIN_MAIN_LINKS;

  const support = userRole === 'reseller_admin' ? COMPANY_SUPPORT_LINKS : ADMIN_SUPPORT_LINKS;
  return uniqueLinks([...flattenLinks(main), ...support]);
}

function buildCompanyLinks(data) {
  const main = buildCompanyMainLinks({
    userRole: data?.user_role ?? null,
    userPerms: data?.permissions ?? null,
    canManageUsers: Boolean(data?.can_manage_users),
    canManageAi: Boolean(data?.can_manage_ai),
    canUseInternalAi: Boolean(data?.can_access_internal_ai_chat || data?.can_use_ai),
    hasIxcIntegration: Boolean(data?.has_ixc_integration),
  });

  return uniqueLinks([...flattenLinks(main), ...COMPANY_SUPPORT_LINKS]);
}

function missingConfigurationFor(item, data) {
  const config = data?.company?.configuration ?? {};
  const rules = {
    '/minha-conta/conversas': !config.has_meta_credentials,
    '/minha-conta/campanhas': !config.has_meta_credentials,
    '/minha-conta/bot': !config.has_meta_credentials,
    '/minha-conta/ixc/clientes': !config.has_ixc_integration,
    '/minha-conta/agendamentos': !config.has_appointment_setup,
  };

  if (!rules[item.href]) return null;

  const detail = item.href === '/minha-conta/ixc/clientes'
    ? 'Integração IXC pendente'
    : item.href === '/minha-conta/agendamentos'
      ? 'Agenda não configurada'
      : 'WhatsApp não configurado';

  return { label: 'Faltando configurar', detail };
}

function normalizePageItem(item, data) {
  const status = data?.role === 'company' ? missingConfigurationFor(item, data) : null;

  return {
    href: item.href,
    label: item.label,
    description: DESCRIPTION_BY_HREF[item.href] ?? item.ariaLabel ?? 'Página disponível para o seu perfil.',
    category: CATEGORY_BY_HREF[item.href] ?? 'operation',
    status,
  };
}

function MetricCard({ label, value, detail }) {
  return (
    <Card className="dashboard-metric">
      <span>{label}</span>
      <strong>{value}</strong>
      {detail ? <small>{detail}</small> : null}
    </Card>
  );
}

function LoadingState() {
  return (
    <Layout>
      <div className="dashboard-page">
        <SkeletonText lines={2} lineClassName="h-4 w-96 max-w-full" />
        <div className="dashboard-grid dashboard-grid--two">
          {Array.from({ length: 4 }).map((_, index) => (
            <Card key={`dashboard-skeleton-${index}`} className="p-0">
              <SkeletonCard className="h-40 border-0" />
            </Card>
          ))}
        </div>
      </div>
    </Layout>
  );
}

function DashboardPage() {
  const { data, loading, error } = usePageData('/dashboard');
  const { logout } = useLogout();
  const [query, setQuery] = useState('');

  const role = data?.role === 'admin' ? 'admin' : 'company';
  const companyName = data?.company?.name ?? data?.companyName ?? data?.user?.company_name ?? 'Empresa';
  const userName = data?.user?.name ?? 'Usuário';
  const roleLabel = ROLE_LABELS[data?.user_role] ?? 'Usuário';
  const brandLogo = data?.company?.logo_url || data?.company?.reseller_logo_url || data?.reseller?.logo_url || '';

  const pages = useMemo(() => {
    if (!data?.authenticated) return [];
    const source = data.role === 'admin' ? buildAdminLinks(data) : buildCompanyLinks(data);
    return source.map((item) => normalizePageItem(item, data));
  }, [data]);

  const filteredPages = useMemo(() => {
    const normalizedQuery = query.trim().toLowerCase();
    if (!normalizedQuery) return pages;

    return pages.filter((page) => (
      `${page.label} ${page.description} ${CATEGORY_LABELS[page.category] ?? ''}`
        .toLowerCase()
        .includes(normalizedQuery)
    ));
  }, [pages, query]);

  const groupedPages = useMemo(() => {
    return Object.keys(CATEGORY_LABELS).map((category) => ({
      category,
      pages: filteredPages.filter((page) => page.category === category),
    })).filter((group) => group.pages.length > 0);
  }, [filteredPages]);

  const primaryPages = pages.filter((page) => page.href !== '/dashboard').slice(0, 3);

  if (loading) return <LoadingState />;

  if (error || !data?.authenticated) {
    return (
      <Layout>
        <p className="dashboard-error">Não foi possível carregar o dashboard.</p>
      </Layout>
    );
  }

  return (
    <Layout role={role} companyName={companyName} onLogout={logout}>
      <div className="dashboard-page">
        <section className="dashboard-welcome">
          <div className="dashboard-welcome__identity">
            <div className="dashboard-company-mark" aria-hidden="true">
              {brandLogo ? <img src={brandLogo} alt="" /> : <span>{initials(companyName)}</span>}
            </div>
            <div>
              <p className="dashboard-eyebrow">Bem-vindo(a)</p>
              <h1>{userName}</h1>
              <div className="dashboard-meta">
                <span>{roleLabel}</span>
                {role === 'company' ? <span>{companyName}</span> : null}
                <span>Último acesso: {formatDateTime(data.last_access_at)}</span>
              </div>
            </div>
          </div>

          <div className="dashboard-cta-list" aria-label="Páginas principais">
            {primaryPages.map((page) => (
              <Link key={page.href} to={page.href} className="dashboard-cta">
                {page.label}
              </Link>
            ))}
          </div>
        </section>

        <section className="dashboard-section">
          <div className="dashboard-section__header">
            <div>
              <p className="dashboard-eyebrow">Resumo do usuário</p>
              <h2>Seu movimento</h2>
            </div>
          </div>
          <div className="dashboard-grid dashboard-grid--three">
            <MetricCard label="Ações hoje" value={data.user_summary?.actions_today ?? 0} />
            <MetricCard label="Ações na semana" value={data.user_summary?.actions_week ?? 0} detail="Últimos 7 dias" />
            <MetricCard label="Pendências atribuídas" value={data.user_summary?.assigned_pending ?? 0} />
          </div>
        </section>

        {data.company_summary ? (
          <section className="dashboard-section">
            <div className="dashboard-section__header">
              <div>
                <p className="dashboard-eyebrow">Resumo da empresa</p>
                <h2>{companyName}</h2>
              </div>
            </div>
            <div className="dashboard-grid dashboard-grid--four">
              <MetricCard label="Usuários ativos" value={data.company_summary.active_users_7d ?? 0} detail="7 dias" />
              <MetricCard label="Total de usuários" value={data.company_summary.total_users ?? 0} />
              <MetricCard
                label={data.company_summary.core_metric?.label ?? 'Métrica core'}
                value={data.company_summary.core_metric?.value ?? 0}
                detail={data.company_summary.core_metric?.period ?? '7 dias'}
              />
              <MetricCard label="Pendências críticas" value={data.company_summary.critical_pending ?? 0} />
            </div>
          </section>
        ) : null}

        <section className="dashboard-section">
          <div className="dashboard-section__header dashboard-section__header--search">
            <div>
              <p className="dashboard-eyebrow">Navegação</p>
              <h2>Páginas disponíveis</h2>
            </div>
            <label className="dashboard-search">
              <span>Buscar página</span>
              <input
                type="search"
                value={query}
                onChange={(event) => setQuery(event.target.value)}
                placeholder="Buscar por nome ou categoria"
              />
            </label>
          </div>

          {groupedPages.length > 0 ? (
            <div className="dashboard-navigation">
              {groupedPages.map((group) => (
                <div key={group.category} className="dashboard-nav-group">
                  <h3>{CATEGORY_LABELS[group.category]}</h3>
                  <div className="dashboard-page-list">
                    {group.pages.map((page) => (
                      <Link key={page.href} to={page.href} className="dashboard-page-link">
                        <span>
                          <strong>{page.label}</strong>
                          <small>{page.description}</small>
                        </span>
                        {page.status ? (
                          <em title={page.status.detail}>{page.status.label}</em>
                        ) : null}
                      </Link>
                    ))}
                  </div>
                </div>
              ))}
            </div>
          ) : (
            <Card className="dashboard-empty">Nenhuma página encontrada dentro das suas permissões.</Card>
          )}
        </section>
      </div>
    </Layout>
  );
}

export default DashboardPage;
