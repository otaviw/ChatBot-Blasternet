# WhatsApp Meta Number - Regra Operacional e Fallback Legado

## Escopo
Este documento descreve a regra de envio manual aplicada em `backend/app/Actions/Company/Conversation/ManualReplyAction.php` para resolução de número de envio no WhatsApp.

## 1. Regra preferencial (padrão)
- O sistema deve usar `company_meta_numbers` ativos da empresa.
- A resolução é feita por contato (`ContactSendNumberResolver`) e prioriza:
  1. `contact.meta_number_id` ativo da própria empresa;
  2. número primário ativo da empresa;
  3. primeiro número ativo da empresa.
- Se houver `meta_number_id` explícito em outros fluxos, a validação de ownership/tenant deve continuar obrigatória no backend.

## 2. Fallback legado (quando é aceito)
- No `ManualReplyAction`, o fallback legado é aceito **somente** quando:
  - `send_outbound = true`;
  - não há `meta_number` ativo resolvido;
  - a empresa possui credenciais legadas preenchidas em `companies`:
    - `meta_phone_number_id`
    - `meta_access_token`
- Se essas credenciais legadas não existirem, o envio manual deve ser bloqueado com `NO_ACTIVE_META_NUMBER_FOR_COMPANY`.

## 3. Quando deve ser bloqueado
- Envio outbound manual sem `meta_number` ativo **e** sem credenciais legadas.
- Qualquer tentativa de usar número fora do escopo da empresa (proteção multi-tenant permanece obrigatória).

## 4. Por que esse fallback existe
- Compatibilidade temporária para empresas migradas parcialmente do modelo legado (credenciais diretas em `companies`) para o modelo novo (`company_meta_numbers`).
- Evita indisponibilidade imediata de envio manual durante a transição.

## 5. Riscos do fallback legado
- Aumenta complexidade operacional (dois modelos de configuração coexistindo).
- Pode esconder inconsistências de cadastro se o time assumir que já está 100% no modelo novo.
- Dificulta auditoria e suporte se não houver plano de desativação.

## 6. Recomendação para produção
- Tratar fallback legado como **transitório**.
- Preferir sempre `company_meta_numbers` ativos por empresa.
- Monitorar ocorrências de uso do fallback e reduzir progressivamente até zero.

## 7. Migração e desativação futura
- Passos sugeridos:
  1. Garantir ao menos um `company_meta_number` ativo (e preferencialmente primário) por empresa.
  2. Reatribuir contatos sem `meta_number_id` para número ativo válido.
  3. Validar fluxos de envio manual, template e webhook em staging.
  4. Remover dependência de `meta_phone_number_id/meta_access_token` legados em `companies`.
  5. Endurecer regra: sem número ativo, bloquear outbound manual sem fallback.

## 8. Impacto em multi-tenant
- O fallback legado não deve permitir cross-tenant.
- A segurança de tenant continua baseada em:
  - escopo por `company_id` nas buscas;
  - validações backend de ownership para `meta_number_id`.

## 9. Testes relacionados
- `MetaNumberSecurityTest` (isolamento e ownership por empresa).
- `MetaNumberFeatureTest` (fluxos de seleção/uso/reassign de números).
- `ManualInboxFlowTest` (fluxo manual).
- `WhatsAppMessageStatusTrackingTest` (envio manual com rastreio).

