import './NotFoundPage.css';
import Layout from '@/components/layout/Layout/Layout.jsx';
import Button from '@/components/ui/Button/Button.jsx';
import Card from '@/components/ui/Card/Card.jsx';
import PageHeader from '@/components/ui/PageHeader/PageHeader.jsx';

function NotFoundPage() {
  return (
    <Layout>
      <Card className="mx-auto max-w-lg text-center">
        <PageHeader
          title="Página não encontrada"
          subtitle="A URL informada não corresponde a uma rota válida do painel."
          className="justify-center"
        />
        <a href="/entrar">
          <Button variant="secondary">Voltar para Entrar</Button>
        </a>
      </Card>
    </Layout>
  );
}

export default NotFoundPage;



