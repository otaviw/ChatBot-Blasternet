<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manuals', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->string('category', 32);
            $table->string('target_key', 64)->nullable();
            $table->text('summary')->nullable();
            $table->longText('content');
            $table->json('image_urls')->nullable();
            $table->json('required_roles')->nullable();
            $table->json('required_permissions')->nullable();
            $table->boolean('is_published')->default(true);
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->unsignedBigInteger('updated_by_user_id')->nullable();
            $table->timestamps();

            $table->index(['category', 'is_published']);
            $table->index('target_key');
        });

        $manuals = [
            [
                'title' => 'Fluxo geral do sistema',
                'category' => 'flow',
                'target_key' => 'fluxo-geral',
                'summary' => 'Visão completa do atendimento, do primeiro contato até o encerramento.',
                'content' => "O fluxo principal começa quando o cliente envia uma mensagem.\n\nA conversa entra na fila e pode ser atendida pelo bot ou por uma pessoa.\nQuando necessário, o atendente assume a conversa, responde, transfere e encerra.\nDepois disso, os registros ficam disponíveis para consulta e auditoria.\n\nEsse é o caminho padrão para manter o atendimento organizado, rápido e seguro.",
                'required_roles' => null,
                'required_permissions' => null,
            ],
            [
                'title' => 'Fluxo de atendimento manual',
                'category' => 'flow',
                'target_key' => 'fluxo-atendimento-manual',
                'summary' => 'Passo a passo para atender conversas com qualidade e sem perder contexto.',
                'content' => "Abra a conversa e leia o histórico antes de responder.\n\nAssuma a conversa quando iniciar o atendimento para evitar duplicidade.\nResponda de forma objetiva e confirme se o cliente ficou sem dúvidas.\nQuando o caso terminar, encerre a conversa para liberar a fila.\n\nSe o assunto não for seu, transfira para a pessoa certa e registre o motivo.",
                'required_roles' => null,
                'required_permissions' => json_encode(['page_inbox'], JSON_THROW_ON_ERROR),
            ],
            [
                'title' => 'Fluxo de suporte interno',
                'category' => 'flow',
                'target_key' => 'fluxo-suporte',
                'summary' => 'Como abrir e acompanhar chamados quando precisar de ajuda da plataforma.',
                'content' => "Use a área de suporte para registrar o problema com detalhes.\n\nInforme o que aconteceu, quando aconteceu e o impacto no trabalho.\nAcompanhe respostas em Meus chamados e continue a conversa no mesmo ticket.\nEvite abrir tickets duplicados para o mesmo assunto.\n\nCom essas práticas, o time de suporte resolve com mais rapidez.",
                'required_roles' => null,
                'required_permissions' => null,
            ],
            [
                'title' => 'Fluxo de gestão de equipe',
                'category' => 'flow',
                'target_key' => 'fluxo-gestao-equipe',
                'summary' => 'Como organizar pessoas, permissões e responsabilidades.',
                'content' => "Crie usuários com o perfil adequado para cada função.\n\nDefina quais telas cada pessoa pode acessar.\nRevise permissões periodicamente para manter segurança e produtividade.\nAo mudar função de alguém, ajuste os acessos no mesmo dia.\n\nAssim você evita acesso indevido e melhora a operação.",
                'required_roles' => json_encode(['company_admin', 'system_admin', 'reseller_admin'], JSON_THROW_ON_ERROR),
                'required_permissions' => null,
            ],
            [
                'title' => 'Tela Início (Dashboard)',
                'category' => 'screen',
                'target_key' => 'dashboard',
                'summary' => 'Resumo diário para começar o trabalho com prioridade.',
                'content' => "A tela de Início mostra atalhos e informações gerais da operação.\n\nUse essa tela para identificar pendências do dia.\nA partir dela, entre rapidamente em Conversas, Suporte e áreas mais usadas.\nSempre confira alertas antes de iniciar novos atendimentos.",
                'required_roles' => null,
                'required_permissions' => null,
            ],
            [
                'title' => 'Tela Conversas',
                'category' => 'screen',
                'target_key' => 'minha-conta-conversas',
                'summary' => 'Organização da fila, histórico e ações de atendimento.',
                'content' => "Nessa tela você acompanha conversas em andamento e encerradas.\n\nUse filtros para localizar rapidamente o que precisa de ação.\nAo abrir uma conversa, leia todo o contexto antes de responder.\nUse assumir, transferir e encerrar com critério para manter a fila limpa.\n\nBoas práticas aqui melhoram todo o tempo de resposta.",
                'required_roles' => null,
                'required_permissions' => json_encode(['page_inbox'], JSON_THROW_ON_ERROR),
            ],
            [
                'title' => 'Tela Contatos',
                'category' => 'screen',
                'target_key' => 'minha-conta-contatos',
                'summary' => 'Cadastro e manutenção da base de contatos.',
                'content' => "A área de Contatos centraliza dados usados no atendimento e em campanhas.\n\nCadastre com nome e telefone corretos para evitar erros.\nAtualize dados sempre que houver mudança.\nUse importação quando precisar incluir muitos contatos de uma vez.\n\nUma base limpa melhora atendimento e resultados de envio.",
                'required_roles' => null,
                'required_permissions' => json_encode(['page_contacts'], JSON_THROW_ON_ERROR),
            ],
            [
                'title' => 'Tela Campanhas',
                'category' => 'screen',
                'target_key' => 'minha-conta-campanhas',
                'summary' => 'Planejamento e disparo de mensagens em lote.',
                'content' => "Use Campanhas para enviar comunicação para vários contatos.\n\nRevise público, conteúdo e objetivo antes de iniciar.\nSempre faça uma validação final para evitar disparo incorreto.\nApós o envio, acompanhe o resultado para ajustar próximas ações.\n\nEnvio com revisão reduz falhas e retrabalho.",
                'required_roles' => null,
                'required_permissions' => json_encode(['page_campaigns'], JSON_THROW_ON_ERROR),
            ],
            [
                'title' => 'Tela Equipe interna',
                'category' => 'screen',
                'target_key' => 'chat-interno',
                'summary' => 'Comunicação entre membros da equipe sem sair da plataforma.',
                'content' => "A tela de Equipe interna é usada para alinhamentos rápidos.\n\nAbra conversas diretas ou em grupo para tratar temas operacionais.\nMantenha mensagens curtas e objetivas para facilitar leitura.\nEvite usar essa área para dados sensíveis desnecessários.\n\nBoa comunicação interna reduz tempo de decisão.",
                'required_roles' => null,
                'required_permissions' => json_encode(['page_internal_chat'], JSON_THROW_ON_ERROR),
            ],
            [
                'title' => 'Tela Agendamentos',
                'category' => 'screen',
                'target_key' => 'minha-conta-agendamentos',
                'summary' => 'Controle de horários, serviços e marcações.',
                'content' => "Nessa tela você define regras e acompanha os agendamentos.\n\nCadastre serviços e disponibilidade da equipe.\nCrie e atualize agendamentos com horário e responsável corretos.\nUse bloqueios de agenda para períodos indisponíveis.\n\nAgenda bem configurada evita conflitos e atrasos.",
                'required_roles' => null,
                'required_permissions' => json_encode(['page_appointments'], JSON_THROW_ON_ERROR),
            ],
            [
                'title' => 'Tela Respostas rápidas',
                'category' => 'screen',
                'target_key' => 'minha-conta-respostas-rapidas',
                'summary' => 'Modelos de mensagem para acelerar o atendimento.',
                'content' => "Crie respostas prontas para assuntos frequentes.\n\nMantenha textos claros e diretos.\nRevise periodicamente para garantir que estão atualizados.\nAdapte o tom quando necessário, sem perder clareza.\n\nEsse recurso aumenta velocidade sem perder qualidade.",
                'required_roles' => null,
                'required_permissions' => json_encode(['page_quick_replies'], JSON_THROW_ON_ERROR),
            ],
            [
                'title' => 'Tela Tags',
                'category' => 'screen',
                'target_key' => 'minha-conta-tags',
                'summary' => 'Classificação de conversas para busca e organização.',
                'content' => "Tags ajudam a organizar conversas por assunto, prioridade e status.\n\nCrie nomes simples e padronizados.\nEvite excesso de tags para não gerar confusão.\nAplique as mesmas regras para toda a equipe.\n\nBoa padronização melhora visão da operação.",
                'required_roles' => null,
                'required_permissions' => json_encode(['page_tags'], JSON_THROW_ON_ERROR),
            ],
            [
                'title' => 'Tela Auditoria',
                'category' => 'screen',
                'target_key' => 'minha-conta-auditoria',
                'summary' => 'Histórico de ações para rastreio e governança.',
                'content' => "A Auditoria mostra ações importantes realizadas no sistema.\n\nUse filtros para localizar período, usuário ou tipo de ação.\nConsulte sempre que precisar entender uma mudança.\nRegistros de auditoria ajudam na segurança e na melhoria de processo.",
                'required_roles' => null,
                'required_permissions' => json_encode(['page_audit'], JSON_THROW_ON_ERROR),
            ],
            [
                'title' => 'Tela Clientes IXC',
                'category' => 'screen',
                'target_key' => 'minha-conta-ixc-clientes',
                'summary' => 'Consulta de clientes, boletos e notas na integração IXC.',
                'content' => "Nessa tela você consulta informações financeiras de clientes no IXC.\n\nBusque pelo cliente certo antes de qualquer ação.\nVerifique se o boleto ou nota corresponde ao atendimento em aberto.\nAo enviar ou baixar documentos, confirme os dados com atenção.\n\nEsse cuidado evita envio incorreto e retrabalho.",
                'required_roles' => null,
                'required_permissions' => json_encode(['page_ixc_clients'], JSON_THROW_ON_ERROR),
            ],
            [
                'title' => 'Tela Bot',
                'category' => 'screen',
                'target_key' => 'minha-conta-bot',
                'summary' => 'Configuração de atendimento automático e comportamento do bot.',
                'content' => "A tela Bot define como o atendimento automático responde.\n\nRevise mensagens, fluxos e regras antes de publicar mudanças.\nSempre teste em cenário controlado antes de usar em produção.\nMantenha os textos claros para reduzir dúvidas do cliente.",
                'required_roles' => json_encode(['company_admin', 'system_admin', 'reseller_admin'], JSON_THROW_ON_ERROR),
                'required_permissions' => null,
            ],
            [
                'title' => 'Tela Usuários',
                'category' => 'screen',
                'target_key' => 'usuarios',
                'summary' => 'Cadastro de pessoas e controle de acessos.',
                'content' => "Use esta tela para criar e manter usuários ativos.\n\nDefina perfil e permissões de acordo com a função de cada pessoa.\nDesative acessos que não são mais necessários.\nRevise permissões com frequência para manter segurança.",
                'required_roles' => json_encode(['company_admin', 'system_admin', 'reseller_admin'], JSON_THROW_ON_ERROR),
                'required_permissions' => null,
            ],
            [
                'title' => 'Tela Minha empresa',
                'category' => 'screen',
                'target_key' => 'minha-conta-empresa',
                'summary' => 'Dados da empresa e integrações operacionais.',
                'content' => "Nessa tela ficam dados institucionais e integrações da empresa.\n\nPreencha as informações com cuidado e mantenha tudo atualizado.\nAntes de salvar, valide os dados para evitar interrupções na operação.\nMudanças nessa área impactam fluxos críticos do sistema.",
                'required_roles' => null,
                'required_permissions' => json_encode(['ixc_integration_manage'], JSON_THROW_ON_ERROR),
            ],
            [
                'title' => 'Tela Pedir ajuda',
                'category' => 'screen',
                'target_key' => 'suporte-novo',
                'summary' => 'Abertura de chamados para o time de suporte.',
                'content' => "Use esta tela quando precisar de ajuda da plataforma.\n\nDescreva o problema com clareza e inclua o contexto necessário.\nSe possível, informe horário do erro e impacto no atendimento.\nQuanto mais claro o chamado, mais rápida a solução.",
                'required_roles' => null,
                'required_permissions' => null,
            ],
            [
                'title' => 'Tela Meus chamados',
                'category' => 'screen',
                'target_key' => 'suporte-lista',
                'summary' => 'Acompanhamento dos chamados abertos pela sua equipe.',
                'content' => "Aqui você acompanha o andamento dos tickets de suporte.\n\nAbra o chamado para ler respostas e atualizar informações.\nMantenha toda a conversa no mesmo ticket para preservar histórico.\nFinalize somente quando o problema estiver realmente resolvido.",
                'required_roles' => null,
                'required_permissions' => null,
            ],
            [
                'title' => 'Tela Chamados (admin)',
                'category' => 'screen',
                'target_key' => 'admin-suporte',
                'summary' => 'Gestão completa dos chamados para operação administrativa.',
                'content' => "Esta tela é usada para acompanhar e tratar chamados de suporte em nível administrativo.\n\nPriorize tickets com maior impacto operacional.\nAtualize status corretamente para manter visibilidade do trabalho.\nUse histórico e contexto para responder com precisão.",
                'required_roles' => json_encode(['system_admin'], JSON_THROW_ON_ERROR),
                'required_permissions' => null,
            ],
            [
                'title' => 'Tela Empresas (admin)',
                'category' => 'screen',
                'target_key' => 'admin-empresas',
                'summary' => 'Cadastro e gestão de empresas em ambiente administrativo.',
                'content' => "Nessa tela você cria, consulta e atualiza empresas.\n\nConfirme dados essenciais antes de concluir qualquer alteração.\nMudanças em empresa podem afetar integrações e acessos.\nFaça revisões com atenção para evitar impacto operacional.",
                'required_roles' => json_encode(['system_admin', 'reseller_admin'], JSON_THROW_ON_ERROR),
                'required_permissions' => null,
            ],
            [
                'title' => 'Tela Conversas (admin)',
                'category' => 'screen',
                'target_key' => 'admin-conversas',
                'summary' => 'Acompanhamento administrativo das conversas.',
                'content' => "Nesta tela a operação administrativa acompanha o atendimento geral.\n\nUse filtros para localizar filas e situações críticas.\nRevise histórico antes de qualquer ação.\nMantenha o acompanhamento diário para evitar acúmulos.",
                'required_roles' => json_encode(['reseller_admin'], JSON_THROW_ON_ERROR),
                'required_permissions' => null,
            ],
            [
                'title' => 'Tela Usuários (admin)',
                'category' => 'screen',
                'target_key' => 'admin-usuarios',
                'summary' => 'Gestão de usuários em ambiente administrativo.',
                'content' => "A tela de usuários administrativos permite criar e manter acessos.\n\nDefina o perfil correto e revise permissões sempre que necessário.\nDesative contas sem uso para manter segurança.\nDocumente alterações relevantes.",
                'required_roles' => json_encode(['system_admin', 'reseller_admin'], JSON_THROW_ON_ERROR),
                'required_permissions' => null,
            ],
            [
                'title' => 'Tela Auditoria (admin)',
                'category' => 'screen',
                'target_key' => 'admin-auditoria',
                'summary' => 'Consulta administrativa de eventos e ações do sistema.',
                'content' => "A auditoria administrativa ajuda no controle operacional.\n\nUse filtros por período e usuário para investigar mudanças.\nValide o contexto antes de concluir qualquer análise.\nEsse registro é essencial para governança.",
                'required_roles' => json_encode(['reseller_admin'], JSON_THROW_ON_ERROR),
                'required_permissions' => null,
            ],
            [
                'title' => 'Tela Simulador (admin)',
                'category' => 'screen',
                'target_key' => 'admin-simulador',
                'summary' => 'Teste de respostas automáticas em ambiente controlado.',
                'content' => "Use o simulador para validar comportamento antes de publicar mudanças.\n\nTeste cenários comuns e cenários de exceção.\nRevise o resultado com atenção e ajuste o que for necessário.\nSomente depois avance para uso real.",
                'required_roles' => json_encode(['system_admin'], JSON_THROW_ON_ERROR),
                'required_permissions' => null,
            ],
            [
                'title' => 'Tela Minha revenda (admin)',
                'category' => 'screen',
                'target_key' => 'admin-minha-revenda',
                'summary' => 'Configuração da revenda para perfil reseller admin.',
                'content' => "A tela Minha revenda reúne dados da operação da revenda.\n\nAtualize informações institucionais sempre que necessário.\nVerifique dados antes de salvar para evitar inconsistências.\nEssa manutenção garante base confiável para gestão.",
                'required_roles' => json_encode(['reseller_admin'], JSON_THROW_ON_ERROR),
                'required_permissions' => null,
            ],
            [
                'title' => 'Tela Assistente IA',
                'category' => 'screen',
                'target_key' => 'chat-ia',
                'summary' => 'Uso do assistente de IA para apoio operacional.',
                'content' => "Use o Assistente IA para acelerar tarefas de análise e apoio.\n\nEscreva perguntas claras e objetivas para obter respostas melhores.\nRevise as sugestões antes de aplicar em contexto real.\nA decisão final deve sempre considerar as regras do negócio.",
                'required_roles' => json_encode(['system_admin'], JSON_THROW_ON_ERROR),
                'required_permissions' => null,
            ],
            [
                'title' => 'Tela Configurações de IA',
                'category' => 'screen',
                'target_key' => 'ia-configuracoes',
                'summary' => 'Ajustes de comportamento e uso de IA.',
                'content' => "Nesta tela você define regras de funcionamento da IA.\n\nAltere configurações com cuidado e registre o motivo.\nApós cada ajuste, acompanhe o comportamento em produção.\nMudanças graduais ajudam a reduzir risco.",
                'required_roles' => json_encode(['system_admin'], JSON_THROW_ON_ERROR),
                'required_permissions' => null,
            ],
            [
                'title' => 'Tela Analytics IA',
                'category' => 'screen',
                'target_key' => 'ia-analytics',
                'summary' => 'Acompanhamento de uso e desempenho da IA.',
                'content' => "Use esta tela para acompanhar métricas de utilização da IA.\n\nObserve padrões de uso, volume e resultado das interações.\nCom base nisso, ajuste estratégia e configurações.\nAnálise contínua melhora o retorno do recurso.",
                'required_roles' => json_encode(['system_admin'], JSON_THROW_ON_ERROR),
                'required_permissions' => null,
            ],
            [
                'title' => 'Tela Auditoria IA',
                'category' => 'screen',
                'target_key' => 'ia-auditoria',
                'summary' => 'Rastreio de ações e eventos envolvendo IA.',
                'content' => "A Auditoria IA registra eventos importantes ligados ao uso da IA.\n\nUse os filtros para localizar eventos por período e contexto.\nEsse histórico apoia investigação, segurança e melhoria de processo.",
                'required_roles' => json_encode(['system_admin'], JSON_THROW_ON_ERROR),
                'required_permissions' => null,
            ],
            [
                'title' => 'Tela Base de conhecimento',
                'category' => 'screen',
                'target_key' => 'base-conhecimento',
                'summary' => 'Conteúdos usados como referência para respostas de IA.',
                'content' => "Aqui você mantém materiais que apoiam respostas da IA.\n\nEscreva conteúdos claros, atualizados e sem ambiguidade.\nRemova informações antigas para evitar orientação incorreta.\nUma base bem cuidada melhora a qualidade das respostas.",
                'required_roles' => json_encode(['system_admin'], JSON_THROW_ON_ERROR),
                'required_permissions' => null,
            ],
            [
                'title' => 'Fluxo de campanhas',
                'category' => 'flow',
                'target_key' => 'fluxo-campanhas',
                'summary' => 'Preparação, revisão e disparo seguro de campanhas.',
                'content' => "Comece definindo o objetivo da campanha.\n\nSelecione os contatos corretos e revise o conteúdo da mensagem.\nValide tudo antes de iniciar o disparo.\nApós o envio, acompanhe resultados e ajuste próximos envios.",
                'required_roles' => null,
                'required_permissions' => json_encode(['page_campaigns'], JSON_THROW_ON_ERROR),
            ],
            [
                'title' => 'Fluxo de agendamentos',
                'category' => 'flow',
                'target_key' => 'fluxo-agendamentos',
                'summary' => 'Como configurar agenda e manter marcações atualizadas.',
                'content' => "Configure serviços e horários disponíveis.\n\nAo criar agendamento, confirme data, hora e responsável.\nUse bloqueios quando houver indisponibilidade.\nAtualize ou cancele registros sempre que houver mudança.",
                'required_roles' => null,
                'required_permissions' => json_encode(['page_appointments'], JSON_THROW_ON_ERROR),
            ],
            [
                'title' => 'Fluxo de gestão de contatos',
                'category' => 'flow',
                'target_key' => 'fluxo-contatos',
                'summary' => 'Cadastro, revisão e atualização da base de contatos.',
                'content' => "Cadastre contatos com dados corretos desde o início.\n\nRevise duplicidades e corrija informações incompletas.\nQuando necessário, use importação em lote com validação prévia.\nBase organizada melhora todo o restante da operação.",
                'required_roles' => null,
                'required_permissions' => json_encode(['page_contacts'], JSON_THROW_ON_ERROR),
            ],
        ];

        $now = now();
        $rows = array_map(static function (array $manual) use ($now): array {
            return [
                'title' => $manual['title'],
                'category' => $manual['category'],
                'target_key' => $manual['target_key'] ?? null,
                'summary' => $manual['summary'] ?? null,
                'content' => $manual['content'],
                'image_urls' => json_encode([], JSON_THROW_ON_ERROR),
                'required_roles' => $manual['required_roles'] ?? null,
                'required_permissions' => $manual['required_permissions'] ?? null,
                'is_published' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }, $manuals);

        DB::table('manuals')->insert($rows);
    }

    public function down(): void
    {
        Schema::dropIfExists('manuals');
    }
};
