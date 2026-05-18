<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Manual;
use Illuminate\Database\Seeder;

class ManualsSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->manuals() as $manual) {
            Manual::updateOrCreate(
                ['target_key' => $manual['target_key']],
                [
                    'title' => $manual['title'],
                    'category' => $manual['category'],
                    'summary' => $manual['summary'],
                    'content' => $manual['content'],
                    'image_urls' => [],
                    'required_roles' => $manual['required_roles'] ?? [],
                    'required_permissions' => $manual['required_permissions'] ?? [],
                    'is_published' => true,
                ]
            );
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function manuals(): array
    {
        return [
            $this->flow('fluxo-geral', 'Fluxo geral do sistema', 'Visão completa do atendimento e operação.', "Como funciona\nO sistema foi desenhado para receber mensagens, organizar atendimento e manter histórico confiável.\n\nEtapas do fluxo\n1. O cliente entra em contato.\n2. A conversa é direcionada para a fila certa.\n3. O atendimento segue com bot, atendente ou os dois.\n4. O caso pode ser transferido, atualizado e encerrado.\n5. O histórico fica salvo para consultas futuras.\n\nDicas de boas maneiras de uso\n- Leia o histórico antes de qualquer resposta.\n- Registre mudanças importantes no momento em que ocorrerem.\n- Evite encerrar conversa sem confirmar se a dúvida foi resolvida.\n\nIdeias de uso\n- Defina rotina diária para limpar pendências.\n- Revise indicadores no início e no fim do dia."),
            $this->flow('fluxo-atendimento-manual', 'Fluxo de atendimento manual', 'Como atender bem e com organização.', "Como funciona\nO atendimento manual é usado quando a conversa precisa de análise humana.\n\nEtapas do fluxo\n1. Abrir conversa e revisar contexto.\n2. Assumir atendimento.\n3. Responder com clareza.\n4. Transferir quando necessário.\n5. Encerrar somente após conclusão.\n\nDicas de boas maneiras de uso\n- Evite respostas curtas sem contexto.\n- Trate o cliente com linguagem respeitosa e objetiva.\n- Confirme entendimento antes de seguir para próxima ação.\n\nIdeias de uso\n- Use respostas rápidas como base e personalize quando preciso.\n- Marque conversas com tags para facilitar retorno."),
            $this->flow('fluxo-campanhas', 'Fluxo de campanhas', 'Preparação e envio seguro de campanhas.', "Como funciona\nCampanhas ajudam a enviar comunicação para grupos de contatos.\n\nEtapas do fluxo\n1. Definir objetivo da campanha.\n2. Selecionar público correto.\n3. Revisar texto e contexto.\n4. Iniciar disparo.\n5. Acompanhar resultado.\n\nDicas de boas maneiras de uso\n- Nunca dispare sem revisão final.\n- Evite mensagens genéricas em assuntos sensíveis.\n- Respeite o horário da comunicação.\n\nIdeias de uso\n- Crie campanhas por segmento de interesse.\n- Compare resultados por tipo de mensagem."),
            $this->flow('fluxo-agendamentos', 'Fluxo de agendamentos', 'Como organizar disponibilidade e marcações.', "Como funciona\nEsse fluxo controla horários, serviços e confirmação de compromissos.\n\nEtapas do fluxo\n1. Configurar serviços e equipe.\n2. Definir horários disponíveis.\n3. Criar agendamento.\n4. Ajustar quando houver mudança.\n5. Finalizar ou cancelar conforme atendimento.\n\nDicas de boas maneiras de uso\n- Sempre confirme dados com o cliente.\n- Atualize agenda no momento da alteração.\n- Evite sobreposição de horários.\n\nIdeias de uso\n- Crie padrão de duração por serviço.\n- Use bloqueios para eventos internos."),
            $this->flow('fluxo-contatos', 'Fluxo de gestão de contatos', 'Como manter base limpa e confiável.', "Como funciona\nContatos bem organizados melhoram atendimento e campanhas.\n\nEtapas do fluxo\n1. Cadastrar ou importar contatos.\n2. Revisar duplicidades.\n3. Atualizar dados incompletos.\n4. Associar atendente padrão quando necessário.\n\nDicas de boas maneiras de uso\n- Padronize nomes e telefones.\n- Evite criar novo contato se já existir.\n- Revise base periodicamente.\n\nIdeias de uso\n- Criar rotina semanal de revisão.\n- Separar contatos por perfil de atendimento."),
            $this->flow('fluxo-suporte', 'Fluxo de suporte interno', 'Como abrir e acompanhar chamados.', "Como funciona\nChamados registram problemas e pedidos para o time de suporte.\n\nEtapas do fluxo\n1. Abrir chamado com detalhes.\n2. Informar impacto e contexto.\n3. Acompanhar respostas no mesmo ticket.\n4. Validar solução e encerrar.\n\nDicas de boas maneiras de uso\n- Use títulos objetivos.\n- Anexe evidências quando possível.\n- Não abra tickets duplicados para o mesmo assunto.\n\nIdeias de uso\n- Criar padrão de relato de erro na equipe.\n- Registrar horário exato do problema para facilitar análise."),
            $this->screen('dashboard', 'Tela Início (Dashboard)', null, null, "Como funciona\nÉ o painel inicial com visão rápida da operação.\n\nAções da tela\n- Acessar atalhos para áreas principais.\n- Ver alertas e pendências.\n- Iniciar jornada diária com prioridades.\n\nDicas de boas maneiras de uso\n- Abra o dashboard no início do expediente.\n- Use os atalhos para reduzir tempo de navegação.\n\nIdeias de uso\n- Definir checklist de início do dia com base no painel."),
            $this->screen('minha-conta-conversas', 'Tela Conversas', null, ['page_inbox'], "Como funciona\nCentraliza atendimento em tempo real, histórico e acompanhamento.\n\nAções da tela\n- Buscar e filtrar conversas por status, responsável e conteúdo.\n- Abrir conversa para ler histórico completo.\n- Assumir conversa para indicar responsabilidade.\n- Soltar conversa quando outro atendente deve seguir.\n- Responder manualmente com texto e anexos.\n- Transferir conversa para outro atendente.\n- Enviar template quando aplicável.\n- Encerrar conversa ao concluir atendimento.\n- Atualizar contato e tags da conversa.\n\nPara que serve cada ação\n- Assumir: evita atendimento duplicado.\n- Soltar: devolve para fila.\n- Transferir: direciona para pessoa mais adequada.\n- Encerrar: finaliza ciclo e limpa pendência.\n\nDicas de boas maneiras de uso\n- Leia o histórico antes de responder.\n- Não transfira sem explicar contexto para o próximo atendente.\n- Evite encerrar conversa sem confirmação do cliente.\n\nIdeias de uso\n- Criar padrão de tag por assunto.\n- Usar respostas rápidas para acelerar mensagens repetitivas."),
            $this->screen('minha-conta-contatos', 'Tela Contatos', null, ['page_contacts'], "Como funciona\nMantém cadastro de pessoas e dados usados em atendimento e campanhas.\n\nAções da tela\n- Buscar por nome ou telefone.\n- Criar contato manualmente.\n- Importar contatos por arquivo CSV.\n- Editar dados do contato.\n- Excluir contato quando necessário.\n- Definir número padrão e atendente padrão.\n\nDicas de boas maneiras de uso\n- Cadastre telefone com padrão único da empresa.\n- Revise duplicidades antes de importar.\n- Atualize dados sempre que o cliente informar mudança.\n\nIdeias de uso\n- Padronizar preenchimento para toda a equipe.\n- Criar rotina mensal de limpeza da base."),
            $this->screen('minha-conta-campanhas', 'Tela Campanhas', null, ['page_campaigns'], "Como funciona\nGerencia criação e disparo de mensagens para grupos de contatos.\n\nAções da tela\n- Criar campanha com título e conteúdo.\n- Definir público alvo.\n- Validar contatos antes do disparo.\n- Iniciar envio.\n- Acompanhar status da campanha.\n- Excluir campanha quando necessário.\n\nDicas de boas maneiras de uso\n- Revisar conteúdo com atenção antes de iniciar.\n- Evitar disparo em horários inadequados.\n- Não reutilizar campanha antiga sem revisão.\n\nIdeias de uso\n- Criar modelos por tema.\n- Testar formatos diferentes e comparar resultados."),
            $this->screen('chat-interno', 'Tela Equipe interna', null, ['page_internal_chat'], "Como funciona\nPermite conversa entre membros da equipe sem sair da plataforma.\n\nAções da tela\n- Criar conversa interna.\n- Enviar mensagens para alinhamentos rápidos.\n- Consultar histórico interno.\n\nDicas de boas maneiras de uso\n- Seja objetivo nas mensagens.\n- Evite excesso de mensagens fora de contexto.\n- Registre decisões importantes de forma clara.\n\nIdeias de uso\n- Usar chat interno para passagem de turno.\n- Padronizar mensagens de handoff."),
            $this->screen('minha-conta-agendamentos', 'Tela Agendamentos', null, ['page_appointments'], "Como funciona\nControla configuração de agenda, serviços e compromissos.\n\nAções da tela\n- Configurar regras gerais de agendamento.\n- Cadastrar, editar e desativar serviços.\n- Definir atendentes e jornada de trabalho.\n- Criar bloqueios de agenda.\n- Consultar disponibilidade.\n- Criar, alterar e cancelar agendamentos.\n\nDicas de boas maneiras de uso\n- Valide sempre data e horário antes de salvar.\n- Atualize bloqueios assim que surgir indisponibilidade.\n- Evite agendamento sem responsável definido.\n\nIdeias de uso\n- Criar janela de confirmação um dia antes.\n- Separar serviços por categoria de atendimento."),
            $this->screen('minha-conta-respostas-rapidas', 'Tela Respostas rápidas', null, ['page_quick_replies'], "Como funciona\nArmazena mensagens prontas para acelerar atendimento.\n\nAções da tela\n- Criar template.\n- Editar template existente.\n- Excluir template antigo.\n- Aplicar template no atendimento.\n\nDicas de boas maneiras de uso\n- Use linguagem clara e educada.\n- Revise textos para evitar informações antigas.\n- Personalize a resposta quando necessário.\n\nIdeias de uso\n- Criar templates por etapa do atendimento.\n- Criar versões para dúvidas mais recorrentes."),
            $this->screen('minha-conta-tags', 'Tela Tags', null, ['page_tags'], "Como funciona\nClassifica conversas para facilitar busca e organização.\n\nAções da tela\n- Criar tag.\n- Editar tag.\n- Excluir tag.\n- Aplicar tags nas conversas.\n\nDicas de boas maneiras de uso\n- Mantenha nomes curtos e padronizados.\n- Evite criar tags duplicadas com mesmo objetivo.\n\nIdeias de uso\n- Tags por prioridade, tipo de problema e resultado."),
            $this->screen('minha-conta-auditoria', 'Tela Auditoria', null, ['page_audit'], "Como funciona\nMostra histórico de ações importantes da operação.\n\nAções da tela\n- Filtrar registros por data e tipo.\n- Consultar detalhes de eventos.\n- Rastrear alterações por usuário.\n\nDicas de boas maneiras de uso\n- Use auditoria para investigar mudanças inesperadas.\n- Revise ações críticas periodicamente.\n\nIdeias de uso\n- Criar rotina de revisão semanal de eventos."),
            $this->screen('minha-conta-ixc-clientes', 'Tela Clientes IXC', null, ['page_ixc_clients'], "Como funciona\nPermite consulta de clientes e documentos da integração IXC.\n\nAções da tela\n- Buscar cliente.\n- Visualizar boletos e notas fiscais.\n- Baixar boleto/documento.\n- Enviar boleto por e-mail ou SMS.\n\nDicas de boas maneiras de uso\n- Confirme cliente e documento antes de enviar.\n- Evite compartilhar dados sem validação.\n\nIdeias de uso\n- Criar checklist de conferência antes de envio."),
            $this->screen('minha-conta-bot', 'Tela Bot', ['company_admin', 'system_admin', 'reseller_admin'], null, "Como funciona\nConfigura o comportamento do atendimento automático.\n\nAções da tela\n- Ajustar mensagens e regras do bot.\n- Definir fluxos automáticos.\n- Validar integração do canal.\n- Salvar configurações.\n\nDicas de boas maneiras de uso\n- Teste antes de publicar mudanças.\n- Faça alterações graduais.\n- Registre motivo de cada ajuste.\n\nIdeias de uso\n- Criar versões por tipo de campanha.\n- Ajustar mensagens por horário de atendimento."),
            $this->screen('usuarios', 'Tela Usuários', ['company_admin', 'system_admin', 'reseller_admin'], null, "Como funciona\nGerencia cadastro de usuários e permissões.\n\nAções da tela\n- Criar usuário.\n- Editar dados e permissões.\n- Desativar ou remover usuário.\n\nDicas de boas maneiras de uso\n- Aplique o mínimo de acesso necessário.\n- Revise permissões sempre que função mudar.\n\nIdeias de uso\n- Criar matriz de permissões por cargo."),
            $this->screen('minha-conta-empresa', 'Tela Minha empresa', null, ['ixc_integration_manage'], "Como funciona\nConcentra dados da empresa e integrações principais.\n\nAções da tela\n- Atualizar dados institucionais.\n- Configurar integração IXC.\n- Validar conexão.\n\nDicas de boas maneiras de uso\n- Atualize dados oficiais com cuidado.\n- Teste integração após alterações.\n\nIdeias de uso\n- Criar revisão trimestral de configurações."),
            $this->screen('suporte-novo', 'Tela Pedir ajuda', null, null, "Como funciona\nCanal para abrir chamados para o time de suporte.\n\nAções da tela\n- Informar assunto e descrição.\n- Anexar contexto do problema.\n- Enviar solicitação.\n\nDicas de boas maneiras de uso\n- Relate o problema com começo, meio e fim.\n- Informe impacto para priorização.\n\nIdeias de uso\n- Padronizar texto de abertura de chamados na equipe."),
            $this->screen('suporte-lista', 'Tela Meus chamados', null, null, "Como funciona\nMostra os chamados já abertos e o status de cada um.\n\nAções da tela\n- Consultar tickets abertos e fechados.\n- Abrir ticket para responder no chat.\n- Acompanhar evolução da análise.\n\nDicas de boas maneiras de uso\n- Mantenha conversa no mesmo ticket.\n- Responda dúvidas do suporte com rapidez.\n\nIdeias de uso\n- Criar acompanhamento diário de tickets abertos."),
            $this->screen('admin-empresas', 'Tela Empresas (admin)', ['system_admin', 'reseller_admin'], null, "Como funciona\nAmbiente administrativo para gestão de empresas.\n\nAções da tela\n- Listar empresas.\n- Criar empresa.\n- Editar dados e configurações.\n- Excluir empresa quando autorizado.\n\nDicas de boas maneiras de uso\n- Revisar impacto antes de alterar dados críticos.\n- Registrar motivo de mudanças relevantes.\n\nIdeias de uso\n- Criar checklist de onboarding de nova empresa."),
            $this->screen('admin-conversas', 'Tela Conversas (admin)', ['reseller_admin'], null, "Como funciona\nVisão administrativa das conversas da operação.\n\nAções da tela\n- Pesquisar conversas por critérios amplos.\n- Acompanhar fila e distribuição.\n- Revisar detalhes para suporte operacional.\n\nDicas de boas maneiras de uso\n- Evite interferência sem necessidade operacional.\n- Preserve contexto ao orientar a equipe.\n\nIdeias de uso\n- Usar tela para detectar gargalos de atendimento."),
            $this->screen('admin-usuarios', 'Tela Usuários (admin)', ['system_admin', 'reseller_admin'], null, "Como funciona\nControle de contas administrativas e operacionais.\n\nAções da tela\n- Criar usuário.\n- Atualizar papel e permissões.\n- Remover usuário.\n\nDicas de boas maneiras de uso\n- Garanta rastreabilidade de alterações de acesso.\n- Revise usuários inativos com frequência.\n\nIdeias de uso\n- Auditoria mensal de acesso por perfil."),
            $this->screen('admin-auditoria', 'Tela Auditoria (admin)', ['reseller_admin'], null, "Como funciona\nConsulta administrativa de registros de auditoria.\n\nAções da tela\n- Filtrar por período, usuário e evento.\n- Abrir registro detalhado.\n\nDicas de boas maneiras de uso\n- Use filtros para análises rápidas e objetivas.\n- Evite conclusões sem validar contexto completo.\n\nIdeias de uso\n- Criar rotina de análise de eventos críticos."),
            $this->screen('admin-simulador', 'Tela Simulador (admin)', ['system_admin'], null, "Como funciona\nPermite testar fluxos e respostas antes de aplicar em produção.\n\nAções da tela\n- Simular entradas do cliente.\n- Ver resposta esperada do fluxo.\n- Ajustar configuração após teste.\n\nDicas de boas maneiras de uso\n- Teste cenários comuns e exceções.\n- Registre resultados relevantes.\n\nIdeias de uso\n- Usar em treinamento de novos operadores."),
            $this->screen('admin-minha-revenda', 'Tela Minha revenda (admin)', ['reseller_admin'], null, "Como funciona\nGerencia dados da revenda e configurações relacionadas.\n\nAções da tela\n- Atualizar dados de revenda.\n- Revisar informações operacionais.\n\nDicas de boas maneiras de uso\n- Mantenha dados atualizados para evitar inconsistência.\n\nIdeias de uso\n- Revisão periódica de cadastro e contatos oficiais."),
            $this->screen('admin-suporte', 'Tela Chamados (admin)', ['system_admin'], null, "Como funciona\nAmbiente para gestão global dos chamados de suporte.\n\nAções da tela\n- Listar e priorizar tickets.\n- Alterar status do chamado.\n- Responder pelo chat interno do ticket.\n\nDicas de boas maneiras de uso\n- Priorize por impacto real.\n- Atualize status conforme andamento.\n\nIdeias de uso\n- Definir critérios de prioridade por categoria de problema."),
            $this->screen('chat-ia', 'Tela Assistente IA', ['system_admin'], null, "Como funciona\nAssistente para apoio em análise e tomada de decisão.\n\nAções da tela\n- Enviar perguntas.\n- Refinar contexto da solicitação.\n- Revisar respostas geradas.\n\nDicas de boas maneiras de uso\n- Faça perguntas objetivas.\n- Valide resultados antes de aplicar.\n\nIdeias de uso\n- Criar prompts padrão para tarefas recorrentes."),
            $this->screen('ia-configuracoes', 'Tela Configurações de IA', ['system_admin'], null, "Como funciona\nDefine parâmetros e regras de uso da IA.\n\nAções da tela\n- Ajustar parâmetros de comportamento.\n- Salvar e revisar configuração.\n\nDicas de boas maneiras de uso\n- Faça mudanças pequenas e monitoradas.\n- Documente alterações.\n\nIdeias de uso\n- Criar padrão de configuração por cenário."),
            $this->screen('ia-analytics', 'Tela Analytics IA', ['system_admin'], null, "Como funciona\nMostra dados de uso e desempenho da IA.\n\nAções da tela\n- Acompanhar volume e tendências.\n- Comparar períodos.\n\nDicas de boas maneiras de uso\n- Revise dados com frequência definida.\n- Use métricas para orientar ajustes.\n\nIdeias de uso\n- Reunião semanal de melhoria com base nas métricas."),
            $this->screen('ia-auditoria', 'Tela Auditoria IA', ['system_admin'], null, "Como funciona\nRegistra eventos relevantes do uso da IA.\n\nAções da tela\n- Filtrar eventos.\n- Inspecionar detalhes de cada ocorrência.\n\nDicas de boas maneiras de uso\n- Use para investigação e prevenção.\n- Correlacione com mudanças recentes de configuração.\n\nIdeias de uso\n- Criar rotina de revisão de eventos sensíveis."),
            $this->screen('base-conhecimento', 'Tela Base de conhecimento', ['system_admin'], null, "Como funciona\nArmazena conteúdos que apoiam respostas da IA.\n\nAções da tela\n- Criar item de conhecimento.\n- Editar conteúdo existente.\n- Excluir conteúdo desatualizado.\n\nDicas de boas maneiras de uso\n- Escreva textos claros e objetivos.\n- Atualize sempre que houver mudança de regra.\n\nIdeias de uso\n- Organizar base por tema e prioridade."),
        ];
    }

    /**
     * @param list<string>|null $roles
     * @param list<string>|null $perms
     * @return array<string, mixed>
     */
    private function screen(string $key, string $title, ?array $roles, ?array $perms, string $content): array
    {
        return [
            'target_key' => $key,
            'title' => $title,
            'category' => 'screen',
            'summary' => 'Manual completo da tela com ações, boas práticas e ideias de uso.',
            'required_roles' => $roles ?? [],
            'required_permissions' => $perms ?? [],
            'content' => $content,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function flow(string $key, string $title, string $summary, string $content): array
    {
        return [
            'target_key' => $key,
            'title' => $title,
            'category' => 'flow',
            'summary' => $summary,
            'required_roles' => [],
            'required_permissions' => [],
            'content' => $content,
        ];
    }
}

