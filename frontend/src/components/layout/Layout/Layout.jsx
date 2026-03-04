import './Layout.css';
import { useEffect, useMemo, useState } from 'react';
import api from '@/services/api';

function Layout({ children, role, companyName, onLogout }) {
  const isLogged = Boolean(role);
  const currentPath = window.location.pathname;
  const [canManageUsers, setCanManageUsers] = useState(false);

  useEffect(() => {
    let canceled = false;

    if (role !== 'company') {
      setCanManageUsers(false);
      return () => {
        canceled = true;
      };
    }

    api
      .get('/me')
      .then((response) => {
        if (canceled) return;
        setCanManageUsers(Boolean(response.data?.user?.can_manage_users));
      })
      .catch(() => {
        if (canceled) return;
        setCanManageUsers(false);
      });

    return () => {
      canceled = true;
    };
  }, [role]);

  const adminLinks = [
    { href: '/dashboard', label: 'Dashboard' },
    { href: '/admin/empresas', label: 'Empresas' },
    { href: '/admin/suporte', label: 'Solicitacoes' },
    { href: '/suporte', label: 'Abrir suporte' },
    { href: '/admin/simulador', label: 'Simulador' },
  ];

  const companyLinks = useMemo(() => {
    const links = [
      { href: '/dashboard', label: 'Dashboard' },
      { href: '/minha-conta/bot', label: 'Config. do bot' },
      { href: '/minha-conta/conversas', label: 'Inbox' },
      { href: '/suporte', label: 'Suporte' },
      { href: '/minha-conta/suporte/solicitacoes', label: 'Minhas solicitacoes' },
      { href: '/minha-conta/simulador', label: 'Simulador' },
      { href: '/minha-conta/respostas-rapidas', label: 'Respostas rapidas' },
    ];

    if (canManageUsers) {
      links.push({ href: '/minha-conta/usuarios', label: 'Usuarios' });
    }

    return links;
  }, [canManageUsers]);

  const links = role === 'admin' ? adminLinks : role === 'company' ? companyLinks : [];

  const handleLogout = (event) => {
    if (!onLogout) return;
    event.preventDefault();
    onLogout();
  };

  const isActive = (href) => {
    if (currentPath === href) return true;
    if (href === '/dashboard') return false;
    return currentPath.startsWith(`${href}/`);
  };

  return (
    <div className="min-h-screen relative text-[#0f172a]">
      <header className="sticky top-0 z-20 border-b border-[#e2e8f0] bg-white/95 backdrop-blur-sm">
        <div className="max-w-7xl mx-auto px-4 py-3 md:py-3.5 flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
          <a
            href={isLogged ? '/dashboard' : '/entrar'}
            className="inline-flex items-center gap-2 text-sm md:text-base font-semibold tracking-tight text-[#0f172a] focus-visible:rounded-lg"
          >
            <span className="h-2.5 w-2.5 rounded-full bg-[#f53003]" />
            Blasternet ChatBot
            {role === 'company' && companyName ? (
              <span className="text-[#64748b] text-xs md:text-sm">/ {companyName}</span>
            ) : null}
            {role === 'admin' ? (
              <span className="rounded-full bg-[#fee2e2] px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-[#b91c1c]">
                Admin
              </span>
            ) : null}
          </a>
          {isLogged && (
            <nav className="flex flex-wrap items-center gap-2 text-sm">
              {links.map((item) => (
                <a
                  key={item.href}
                  href={item.href}
                  className={[
                    'rounded-md px-2.5 py-1.5 transition-colors focus-visible:outline-none',
                    isActive(item.href)
                      ? 'text-[#0f172a] font-semibold border-b-2 border-[#f53003]'
                      : 'text-[#475569] border-b-2 border-transparent hover:text-[#0f172a] hover:border-[#cbd5e1]',
                  ].join(' ')}
                >
                  {item.label}
                </a>
              ))}
              <a
                href="/entrar"
                onClick={handleLogout}
                className="rounded-md px-2.5 py-1.5 text-[#b91c1c] border-b-2 border-transparent hover:border-[#fecaca] hover:bg-[#fff1f2] focus-visible:outline-none"
              >
                Sair
              </a>
            </nav>
          )}
        </div>
      </header>

      <main className="relative max-w-7xl mx-auto px-4 py-8 md:py-9">{children}</main>
    </div>
  );
}

export default Layout;
