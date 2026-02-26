import React from 'react';

function Layout({ children, role, companyName, onLogout }) {
  const isLogged = Boolean(role);

  const handleLogout = (event) => {
    if (!onLogout) return;
    event.preventDefault();
    onLogout();
  };

  return (
    <div className="min-h-screen bg-[#FDFDFC] dark:bg-[#0a0a0a] text-[#1b1b18]">
      <header className="border-b border-[#e3e3e0] dark:border-[#3E3E3A] bg-white dark:bg-[#161615]">
        <div className="max-w-6xl mx-auto px-4 py-3 flex items-center justify-between">
          <a
            href={isLogged ? '/dashboard' : '/entrar'}
            className="font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
          >
            Blasternet ChatBot
            {role === 'company' && companyName ? ` - ${companyName}` : null}
          </a>
          {isLogged && (
            <nav className="flex items-center gap-4 text-sm">
              {role === 'admin' && (
                <>
                  <a
                    href="/admin/empresas"
                    className="text-[#706f6c] dark:text-[#A1A09A] hover:text-[#1b1b18] dark:hover:text-white"
                  >
                    Empresas
                  </a>
                  <a
                    href="/admin/simulador"
                    className="text-[#706f6c] dark:text-[#A1A09A] hover:text-[#1b1b18] dark:hover:text-white"
                  >
                    Simulador
                  </a>
                  <a
                    href="/admin/conversas"
                    className="text-[#706f6c] dark:text-[#A1A09A] hover:text-[#1b1b18] dark:hover:text-white"
                  >
                    Inbox
                  </a>
                  <a
                    href="/admin/usuarios"
                    className="text-[#706f6c] dark:text-[#A1A09A] hover:text-[#1b1b18] dark:hover:text-white"
                  >
                    Usuarios
                  </a>
                  <a
                    href="/minha-conta/respostas-rapidas"
                    className="text-[#706f6c] dark:text-[#A1A09A] hover:text-[#1b1b18] dark:hover:text-white"
                  >
                    Respostas rapidas
                  </a>
                </>
              )}
              {role === 'company' && (
                <>
                  <a
                    href="/minha-conta/bot"
                    className="text-[#706f6c] dark:text-[#A1A09A] hover:text-[#1b1b18] dark:hover:text-white"
                  >
                    Config. do bot
                  </a>
                  <a
                    href="/minha-conta/simulador"
                    className="text-[#706f6c] dark:text-[#A1A09A] hover:text-[#1b1b18] dark:hover:text-white"
                  >
                    Simulador
                  </a>
                  <a
                    href="/minha-conta/conversas"
                    className="text-[#706f6c] dark:text-[#A1A09A] hover:text-[#1b1b18] dark:hover:text-white"
                  >
                    Inbox
                  </a>
                  <a
                    href="/minha-conta/respostas-rapidas"
                    className="text-[#706f6c] dark:text-[#A1A09A] hover:text-[#1b1b18] dark:hover:text-white"
                  >
                    Respostas rapidas
                  </a>
                  <a
                    href="/minha-conta/usuarios"
                    className="text-[#706f6c] dark:text-[#A1A09A] hover:text-[#1b1b18] dark:hover:text-white"
                  >
                    Usuarios
                  </a>
                </>
              )}
              <a
                href="/entrar"
                onClick={handleLogout}
                className="text-[#706f6c] dark:text-[#A1A09A] hover:text-[#1b1b18] dark:hover:text-white"
              >
                Sair
              </a>
            </nav>
          )}
        </div>
      </header>

      <main className="max-w-6xl mx-auto px-4 py-8">{children}</main>
    </div>
  );
}

export default Layout;

