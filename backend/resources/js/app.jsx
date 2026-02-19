import React, { useEffect, useState } from 'react';
import ReactDOM from 'react-dom/client';
import axios from 'axios';
import './bootstrap';
import '../css/app.css';

function Layout({ children, role, companyName }) {
    const isLogged = Boolean(role);

    return (
        <div className="min-h-screen bg-[#FDFDFC] dark:bg-[#0a0a0a] text-[#1b1b18]">
            <header className="border-b border-[#e3e3e0] dark:border-[#3E3E3A] bg-white dark:bg-[#161615]">
                <div className="max-w-6xl mx-auto px-4 py-3 flex items-center justify-between">
                    <a
                        href={isLogged ? '/dashboard' : '/entrar'}
                        className="font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                    >
                        Blasternet ChatBot
                        {role === 'company' && companyName ? ` — ${companyName}` : null}
                    </a>
                    {isLogged && (
                        <nav className="flex items-center gap-4 text-sm">
                            {role === 'admin' && (
                                <a
                                    href="/admin/empresas"
                                    className="text-[#706f6c] dark:text-[#A1A09A] hover:text-[#1b1b18] dark:hover:text-white"
                                >
                                    Empresas
                                </a>
                            )}
                            {role === 'company' && (
                                <a
                                    href="/minha-conta/bot"
                                    className="text-[#706f6c] dark:text-[#A1A09A] hover:text-[#1b1b18] dark:hover:text-white"
                                >
                                    Config. do bot
                                </a>
                            )}
                            <a
                                href="/sair"
                                className="text-[#706f6c] dark:text-[#A1A09A] hover:text-[#1b1b18] dark:hover:text-white"
                            >
                                Sair
                            </a>
                        </nav>
                    )}
                </div>
            </header>

            <main className="max-w-6xl mx-auto px-4 py-8">
                {children}
            </main>
        </div>
    );
}

function usePageData(url, initial = null) {
    const [data, setData] = useState(initial);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);

    useEffect(() => {
        let canceled = false;
        setLoading(true);
        axios
            .get(url, {
                headers: { Accept: 'application/json' },
            })
            .then((response) => {
                if (canceled) return;
                const payload = response.data;
                if (payload?.redirect) {
                    window.location.href = payload.redirect;
                    return;
                }
                setData(payload);
                setError(null);
            })
            .catch((err) => {
                if (canceled) return;
                const redirect = err.response?.data?.redirect;
                if (redirect) {
                    window.location.href = redirect;
                    return;
                }
                setError(err);
            })
            .finally(() => {
                if (!canceled) {
                    setLoading(false);
                }
            });

        return () => {
            canceled = true;
        };
    }, [url]);

    return { data, loading, error };
}

function EntrarPage() {
    const { data, loading, error } = usePageData('/entrar');

    return (
        <Layout>
            <div className="max-w-md mx-auto">
                <h1 className="text-xl font-medium mb-2">Entrar</h1>
                <p className="text-[#706f6c] dark:text-[#A1A09A] text-sm mb-6">
                    Escolha com qual perfil acessar (login real você cria depois).
                </p>

                {loading && <p className="text-sm text-[#706f6c]">Carregando empresas...</p>}
                {error && (
                    <p className="text-sm text-red-600 dark:text-red-400">
                        Erro ao carregar empresas. Tente novamente.
                    </p>
                )}

                {!loading && !error && (
                    <div className="space-y-3">
                        <a
                            href="/entrar-como/admin"
                            className="block w-full px-4 py-3 rounded-lg border border-[#e3e3e0] dark:border-[#3E3E3A] bg-white dark:bg-[#161615] hover:border-[#19140035] dark:hover:border-[#62605b] text-left font-medium"
                        >
                            Minha empresa (admin) — gerenciar tudo
                        </a>
                        {data?.companies?.length ? (
                            data.companies.map((company) => (
                                <a
                                    key={company.id}
                                    href={`/entrar-como/empresa/${company.id}`}
                                    className="block w-full px-4 py-3 rounded-lg border border-[#e3e3e0] dark:border-[#3E3E3A] bg-white dark:bg-[#161615] hover:border-[#19140035] dark:hover:border-[#62605b] text-left"
                                >
                                    {company.name} — configurar bot
                                </a>
                            ))
                        ) : (
                            <p className="text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                Nenhuma empresa cadastrada. Cadastre pelo admin ou pelo banco.
                            </p>
                        )}
                    </div>
                )}
            </div>
        </Layout>
    );
}

function DashboardPage() {
    const { data, loading, error } = usePageData('/dashboard');

    if (loading) {
        return (
            <Layout>
                <p className="text-sm text-[#706f6c]">Carregando dashboard...</p>
            </Layout>
        );
    }

    if (error || !data?.authenticated) {
        return (
            <Layout>
                <p className="text-sm text-red-600 dark:text-red-400">
                    Não foi possível carregar o dashboard.
                </p>
            </Layout>
        );
    }

    if (data.role === 'admin') {
        return (
            <Layout role="admin">
                <h1 className="text-xl font-medium mb-2">Dashboard — Minha empresa</h1>
                <p className="text-[#706f6c] dark:text-[#A1A09A] text-sm mb-6">
                    Você está como administrador. Aqui você gerencia empresas, usos e informações.
                </p>

                <ul className="space-y-2 text-sm">
                    <li>
                        <a
                            href="/admin/empresas"
                            className="text-[#f53003] dark:text-[#FF4433] underline underline-offset-2"
                        >
                            Empresas
                        </a>{' '}
                        — listar, ver informações e uso de cada uma
                    </li>
                </ul>
            </Layout>
        );
    }

    return (
        <Layout role="company" companyName={data.companyName}>
            <h1 className="text-xl font-medium mb-2">
                Dashboard — {data.companyName ?? 'Empresa'}
            </h1>
            <p className="text-[#706f6c] dark:text-[#A1A09A] text-sm mb-6">
                Aqui você gerencia como o bot funciona: respostas, horários e demais configurações.
            </p>

            <ul className="space-y-2 text-sm">
                <li>
                    <a
                        href="/minha-conta/bot"
                        className="text-[#f53003] dark:text-[#FF4433] underline underline-offset-2"
                    >
                        Configurações do bot
                    </a>{' '}
                    — respostas, horários, etc.
                </li>
            </ul>
        </Layout>
    );
}

function AdminCompaniesPage() {
    const { data, loading, error } = usePageData('/admin/empresas');

    if (loading) {
        return (
            <Layout role="admin">
                <p className="text-sm text-[#706f6c]">Carregando empresas...</p>
            </Layout>
        );
    }

    if (error || !data?.authenticated) {
        return (
            <Layout>
                <p className="text-sm text-red-600 dark:text-red-400">
                    Não foi possível carregar as empresas.
                </p>
            </Layout>
        );
    }

    const companies = data.companies ?? [];

    return (
        <Layout role="admin">
            <h1 className="text-xl font-medium mb-2">Empresas</h1>
            <p className="text-[#706f6c] dark:text-[#A1A09A] text-sm mb-6">
                Lista de empresas com acesso. Clique para ver informações e uso.
            </p>

            {!companies.length ? (
                <p className="text-sm text-[#706f6c]">Nenhuma empresa cadastrada.</p>
            ) : (
                <ul className="border border-[#e3e3e0] dark:border-[#3E3E3A] rounded-lg divide-y divide-[#e3e3e0] dark:divide-[#3E3E3A] overflow-hidden">
                    {companies.map((company) => (
                        <li key={company.id}>
                            <a
                                href={`/admin/empresas/${company.id}`}
                                className="block px-4 py-3 hover:bg-[#FDFDFC] dark:hover:bg-[#161615]"
                            >
                                <span className="font-medium">{company.name}</span>
                                <span className="text-sm text-[#706f6c] dark:text-[#A1A09A] ml-2">
                                    — {company.conversations_count ?? 0} conversa(s)
                                </span>
                            </a>
                        </li>
                    ))}
                </ul>
            )}
        </Layout>
    );
}

function AdminCompanyShowPage({ companyId }) {
    const { data, loading, error } = usePageData(`/admin/empresas/${companyId}`);

    if (loading) {
        return (
            <Layout role="admin">
                <p className="text-sm text-[#706f6c]">Carregando empresa...</p>
            </Layout>
        );
    }

    if (error || !data?.authenticated || !data.company) {
        return (
            <Layout>
                <p className="text-sm text-red-600 dark:text-red-400">
                    Não foi possível carregar a empresa.
                </p>
            </Layout>
        );
    }

    const company = data.company;

    return (
        <Layout role="admin">
            <div className="mb-4">
                <a
                    href="/admin/empresas"
                    className="text-sm text-[#706f6c] dark:text-[#A1A09A] hover:underline"
                >
                    ← Empresas
                </a>
            </div>
            <h1 className="text-xl font-medium mb-2">{company.name}</h1>
            <p className="text-[#706f6c] dark:text-[#A1A09A] text-sm mb-6">
                Informações e uso da empresa.
            </p>

            <section className="mb-8">
                <h2 className="text-sm font-medium text-[#706f6c] dark:text-[#A1A09A] mb-2">
                    Informações
                </h2>
                <ul className="text-sm space-y-1">
                    <li>ID: {company.id}</li>
                    <li>Nome: {company.name}</li>
                    <li>
                        Meta Phone Number ID:{' '}
                        {company.meta_phone_number_id ? company.meta_phone_number_id : '—'}
                    </li>
                    <li>
                        Token configurado: {company.has_meta_credentials ? 'Sim' : 'Não'}
                    </li>
                </ul>
            </section>

            <section className="mb-8">
                <h2 className="text-sm font-medium text-[#706f6c] dark:text-[#A1A09A] mb-2">
                    Uso
                </h2>
                <p className="text-sm">
                    Total de conversas: <strong>{company.conversations_count ?? 0}</strong>
                </p>
                {Array.isArray(company.conversations) && company.conversations.length > 0 && (
                    <>
                        <p className="text-sm text-[#706f6c] mt-2">
                            Últimas conversas (até 10):
                        </p>
                        <ul className="mt-1 text-sm space-y-1">
                            {company.conversations.map((conv) => (
                                <li key={conv.id}>
                                    {conv.customer_phone} — {conv.status} ({conv.created_at})
                                </li>
                            ))}
                        </ul>
                    </>
                )}
            </section>
        </Layout>
    );
}

function CompanyBotPage() {
    const { data, loading, error } = usePageData('/minha-conta/bot');

    if (loading) {
        return (
            <Layout role="company">
                <p className="text-sm text-[#706f6c]">Carregando configurações do bot...</p>
            </Layout>
        );
    }

    if (error || !data?.authenticated || !data.company) {
        return (
            <Layout>
                <p className="text-sm text-red-600 dark:text-red-400">
                    Não foi possível carregar as configurações do bot.
                </p>
            </Layout>
        );
    }

    const company = data.company;

    return (
        <Layout role="company" companyName={company.name}>
            <h1 className="text-xl font-medium mb-2">
                Configurações do bot — {company.name}
            </h1>
            <p className="text-[#706f6c] dark:text-[#A1A09A] text-sm mb-6">
                Defina como o bot responde, horários de atendimento e demais opções. (Você pode ir
                ajustando os campos depois.)
            </p>

            <div className="space-y-8 max-w-2xl">
                <section className="border border-[#e3e3e0] dark:border-[#3E3E3A] rounded-lg p-4">
                    <h2 className="font-medium mb-2">Respostas</h2>
                    <p className="text-sm text-[#706f6c] dark:text-[#A1A09A]">
                        Mensagens padrão, respostas automáticas, menu. (Formulários e salvamento você
                        implementa depois.)
                    </p>
                </section>
                <section className="border border-[#e3e3e0] dark:border-[#3E3E3A] rounded-lg p-4">
                    <h2 className="font-medium mb-2">Horários</h2>
                    <p className="text-sm text-[#706f6c] dark:text-[#A1A09A]">
                        Horário de atendimento, mensagem fora do horário. (Campos e regras você
                        implementa depois.)
                    </p>
                </section>
            </div>
        </Layout>
    );
}

function NotFoundPage() {
    return (
        <Layout>
            <h1 className="text-xl font-medium mb-2">Página não encontrada</h1>
            <p className="text-sm text-[#706f6c] dark:text-[#A1A09A] mb-4">
                Verifique a URL ou volte para a tela de entrada.
            </p>
            <a
                href="/entrar"
                className="inline-block px-4 py-2 rounded border border-[#e3e3e0] dark:border-[#3E3E3A]"
            >
                Voltar para Entrar
            </a>
        </Layout>
    );
}

function AppRouter() {
    const path = window.location.pathname;
    const segments = path.replace(/^\/+|\/+$/g, '').split('/');

    if (path === '/' || path === '/entrar') {
        return <EntrarPage />;
    }

    if (path === '/dashboard') {
        return <DashboardPage />;
    }

    if (path === '/admin/empresas') {
        return <AdminCompaniesPage />;
    }

    if (segments[0] === 'admin' && segments[1] === 'empresas' && segments[2]) {
        return <AdminCompanyShowPage companyId={segments[2]} />;
    }

    if (path === '/minha-conta/bot') {
        return <CompanyBotPage />;
    }

    return <NotFoundPage />;
}

const rootElement = document.getElementById('app');

if (rootElement) {
    ReactDOM.createRoot(rootElement).render(
        <React.StrictMode>
            <AppRouter />
        </React.StrictMode>,
    );
}

