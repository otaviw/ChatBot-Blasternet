import React from 'react';
import Layout from '../components/Layout';

function NotFoundPage() {
  return (
    <Layout>
      <h1 className="text-xl font-medium mb-2">Pagina nao encontrada</h1>
      <p className="text-sm text-[#706f6c] dark:text-[#A1A09A] mb-4">
        Verifique a URL ou volte para a tela de entrada.
      </p>
      <a href="/entrar" className="inline-block px-4 py-2 rounded border border-[#e3e3e0] dark:border-[#3E3E3A]">
        Voltar para Entrar
      </a>
    </Layout>
  );
}

export default NotFoundPage;
