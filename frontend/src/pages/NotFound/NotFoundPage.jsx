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
          title="Pagina nao encontrada"
          subtitle="A URL informada nao corresponde a uma rota valida do painel."
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



