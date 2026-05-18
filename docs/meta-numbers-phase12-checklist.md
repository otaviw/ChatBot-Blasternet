# Meta Numbers - Fase 12 (Checklist de Execucao)

## 1. Migracoes e modelagem
- `company_meta_numbers` criada (fase anterior).
- `contacts.meta_number_id` adicionado com indice (fase anterior).
- Restricoes de unicidade e regra de principal por empresa aplicadas no backend (fase anterior).

## 2. Servicos e regras
- `CompanyMetaNumberService` implementado com create/update/setPrimary/remove+reatribuicao.
- `ContactSendNumberResolver` implementado com fallback para principal e erro `NO_ACTIVE_META_NUMBER_FOR_COMPANY`.
- Auditoria aplicada nos eventos criticos de numero/contato/conversa/campanha.

## 3. API e seguranca
- Endpoints administrativos de gestao de numeros implementados.
- Endpoint de empresa adicionado para leitura de numeros ativos:
  - `GET /minha-conta/meta-numbers`
- Validacao de escopo de empresa e validacao de vinculo `contact.company_id == meta_number.company_id` no backend.
- Erros de negocio padronizados suportados:
  - `FORBIDDEN_SCOPE`
  - `META_NUMBER_NOT_FOUND`
  - `META_NUMBER_INACTIVE`
  - `META_NUMBER_COMPANY_MISMATCH`
  - `NO_ACTIVE_META_NUMBER_FOR_COMPANY`

## 4. Frontend (4 telas)
- Empresa (admin):
  - Tela de edicao com secao para listar/adicionar/definir principal/inativar numero.
- Conversa:
  - Modal de nova conversa com selecao opcional de numero de envio.
  - Toolbar mostrando numero padrao resolvido da conversa/contato.
- Contato:
  - Exibicao de numero padrao no detalhe.
  - Alteracao de numero padrao no modal de edicao.
  - Novo contato com selecao de numero padrao.
- Campanha:
  - Regra de envio permanece no backend usando resolvedor por contato.

## 5. Backfill e rollout
- Backfill disponivel via comando:
  - `php artisan meta-numbers:backfill-contact-defaults`
- Rollout recomendado:
  1. Deploy backend + migracoes + fallback seguro.
  2. Rodar backfill.
  3. Deploy frontend.
  4. Ativar monitoramento/alertas.
  5. Acompanhar 7 dias.

## 6. Operacao e monitoramento
- Logs estruturados com `company_id`, `contact_id`, `meta_number_id`, `campaign_id`, `conversation_id`.
- Alertas previstos:
  - empresa sem numero ativo;
  - pico de falhas `NO_ACTIVE_META_NUMBER_FOR_COMPANY`.

## 7. Validacao final sugerida
- Fluxo manual:
  1. Cadastrar 2 numeros na empresa e definir principal.
  2. Criar conversa escolhendo numero B.
  3. Confirmar persistencia no contato.
  4. Enviar nova mensagem e confirmar uso do numero salvo.
  5. Remover B e validar reatribuicao para principal.
  6. Rodar campanha e verificar resolucao por contato.
