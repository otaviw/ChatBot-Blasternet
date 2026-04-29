# Guia de Contribuição — Backend

Este documento feito por IA explica os padrões arquiteturais do projeto. Leia antes de implementar qualquer feature ou corrigir qualquer bug. O objetivo é que todo código novo pareça que foi escrito pela mesma pessoa.

---

## Fluxo correto de um endpoint

```
HTTP Request
  → FormRequest      (valida e sanitiza a entrada)
  → Controller       (orquestra: chama Action, devolve JSON)
  → Action           (executa a operação de negócio)
  → Service          (lógica reutilizável por múltiplas Actions)
  → Model            (entidade, relacionamentos, scopes)
```

**Controller não processa.** Só recebe, delega e responde.  
**Action não é reutilizável.** É 1:1 com uma operação de endpoint.  
**Service é reutilizável.** Se dois lugares precisam da mesma lógica, vai para Service.

---

## Padrão correto de Controller

Veja `app/Http/Controllers/Admin/CompanyController.php` como referência.

```php
// CORRETO — controller delega tudo para a Action
public function store(StoreCompanyRequest $request): JsonResponse
{
    $result = $this->storeCompanyAction->handle($request);

    return response()->json($result['body'], $result['status']);
}
```

Regras:
- O controller **não** instancia Models diretamente (exceto para resolução de rota)
- O controller **não** contém `if`/`foreach` de negócio
- O controller **não** injeta mais de ~5 dependências — se precisar de mais, a Action está faltando
- Use `$this->errorResponse('mensagem', 'codigo_snake_case', 403)` para erros (método herdado de `Controller`)

---

## Padrão correto de Action

Veja `app/Actions/Admin/Company/StoreAdminCompanyAction.php` como referência.

```php
class StoreAdminCompanyAction
{
    public function __construct(
        private readonly AuditLogService $auditLog,
        private readonly BotSettingsSupportService $botSettingsSupport,
    ) {}

    /** @return array{status: int, body: array<string, mixed>} */
    public function handle(StoreCompanyRequest $request): array
    {
        // toda a lógica fica aqui
        return ['status' => 201, 'body' => ['ok' => true, 'company' => $company]];
    }
}
```

Regras:
- Retorna sempre `['status' => int, 'body' => array]`
- Nome segue o padrão: `{Verbo}{Contexto}{Recurso}Action` — ex: `UpdateCompanyBotSettingsAction`
- Fica em `app/Actions/{Namespace}/{Recurso}/`
- Usa `private readonly` em todas as dependências do construtor

---

## Padrão correto de Service

Veja `app/Services/Admin/CompanyOwnershipService.php` como referência.

```php
class CompanyOwnershipService
{
    public function canAccessCompany(?User $user, Company $company): bool
    {
        // lógica de negócio pura, sem HTTP, sem Response
    }
}
```

Regras:
- Nunca retorna `JsonResponse` — Services não conhecem HTTP
- Injetado no construtor da Action, não do Controller
- Nome segue: `{Contexto}{Responsabilidade}Service` — ex: `BotSettingsSupportService`

---

## Quando usar Action vs Service

| Situação | Use |
|----------|-----|
| Lógica específica de um endpoint | Action |
| Lógica usada por 2+ Actions | Service |
| Validação de acesso/autorização | Service |
| Operação atômica com banco | Action (com `DB::transaction`) |
| Comunicação com API externa | Service |
| Defaults/configurações de modelo | Service |

---

## Auditoria

O projeto tem dois serviços de auditoria com propósitos distintos:

- **`AuditLogService`** — para ações de usuário via HTTP (quem fez o quê, com qual IP, qual rota). Requer injeção no construtor e um `Request` explícito. Use em Actions que registram operações de usuário.

- **`AuditService`** — para mudanças em entidades (mensagem criada, modelo alterado). Chamado de forma estática, auto-resolve o request via container. Use em jobs, observers e fluxos sem contexto HTTP direto.

```php
// Em uma Action — registra a ação do usuário
$this->auditLog->record($request, 'company.bot_settings.updated', $company->id, [...]);

// Em um Observer/Job — registra a mudança na entidade
AuditService::log('send_message', 'message', $message->id, oldData: null, newData: [...]);
```

---

## Defaults de CompanyBotSetting

Toda inicialização de `CompanyBotSetting` deve usar `BotSettingsSupportService::defaultBotSettingsPayload(int $companyId)`. Nunca defina defaults de bot settings inline em controllers ou actions.

```php
// CORRETO
$defaults = $this->botSettingsSupport->defaultBotSettingsPayload($company->id);
CompanyBotSetting::firstOrCreate(['company_id' => $company->id], $defaults);

// ERRADO — duplica a fonte de verdade
CompanyBotSetting::firstOrCreate(['company_id' => $company->id], [
    'timezone' => 'America/Sao_Paulo',
    'welcome_message' => 'Oi...',
    // ... 30 linhas de defaults hardcoded
]);
```

---

## Validação de entrada

Toda entrada de usuário passa por um `FormRequest` antes de chegar ao controller.

```
app/Http/Requests/
├── Admin/          ← requests de rotas /admin/*
├── Company/        ← requests de rotas /minha-conta/*
├── Auth/           ← requests de autenticação
└── Chat/           ← requests de chat interno
```

O controller sempre usa `$request->validated()` — nunca `$request->input()` ou `$request->all()` para dados de negócio.

---

## Multi-tenancy

O isolamento por empresa é garantido pelo `CompanyScope` (aplicado automaticamente via `BelongsToCompany` trait). **Nunca** confie que um ID de URL pertence à empresa do usuário sem verificar — use sempre queries com `where('company_id', $user->company_id)` ou route model binding com scope aplicado.

O scope falha fechado: se o usuário não tiver `company_id`, retorna zero resultados em vez de expor tudo.

---

## Convenções de nomenclatura

| Tipo | Convenção | Exemplo |
|------|-----------|---------|
| Controller | `{Contexto}Controller` | `BotController` |
| Action | `{Verbo}{Contexto}{Recurso}Action` | `UpdateCompanyBotSettingsAction` |
| Service | `{Contexto}{Responsabilidade}Service` | `CompanyOwnershipService` |
| FormRequest | `{Verbo}{Recurso}Request` | `UpdateBotSettingsRequest` |
| Model | Singular PascalCase | `CompanyBotSetting` |
| Nomes de métodos e variáveis | camelCase em inglês | `resolveCompanyId` |
| Comentários | Português | `// Falha fechado: sem company_id retorna vazio` |

---

## Regra de idioma (obrigatoria)

- Nomes de classes, metodos, variaveis e parametros: sempre em ingles.
- Comentarios inline e docblocks: sempre em portugues.
- Nao e necessario corrigir o codigo legado inteiro de uma vez.
- Ao tocar em um arquivo existente, aplique essa regra nas linhas novas ou alteradas.

## Testes

Antes de abrir PR, certifique-se de que:

```bash
cd backend && php vendor/bin/pest
```

- Feature tests ficam em `tests/Feature/` — testam o endpoint de ponta a ponta
- Unit tests ficam em `tests/Unit/` — testam Services e Actions isoladamente
- Novos Services devem ter Unit tests correspondentes
- Novos endpoints devem ter Feature tests cobrindo: happy path, 403, 404, 422

## Schema dump

- Nao consolidar migrations de producao por padrao.
- Manter snapshot em `database/schema/` atualizado periodicamente.
- Comando recomendado (na raiz):

```bash
powershell -ExecutionPolicy Bypass -File scripts/update-schema-dump.ps1
```

