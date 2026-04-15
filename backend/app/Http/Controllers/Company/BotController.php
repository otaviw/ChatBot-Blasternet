<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Models\Area;
use App\Models\Company;
use App\Models\CompanyBotSetting;
use App\Services\AuditLogService;
use App\Services\Ai\AiAccessService;
use App\Services\Company\CompanyUsageLimitsService;
use App\Services\WhatsAppCredentialsValidatorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BotController extends Controller
{
    public function __construct(
        private AuditLogService $auditLog,
        private AiAccessService $aiAccess,
        private WhatsAppCredentialsValidatorService $credentialsValidator,
        private CompanyUsageLimitsService $usageLimits,
    ) {}

    /** Configuracoes do bot da empresa logada (respostas, horarios etc.). */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user || ! $this->aiAccess->canAccessBotSettings($user)) {
            return response()->json([
                'authenticated' => false,
                'redirect' => '/entrar',
            ], 403);
        }

        $companies = $user->isSystemAdmin()
            ? Company::orderBy('name')->get(['id', 'name'])
            : null;

        $companyId = $user->isSystemAdmin()
            ? (int) $request->integer('company_id', 0)
            : (int) $user->company_id;

        if ($companyId <= 0) {
            return response()->json([
                'authenticated' => true,
                'role' => 'admin',
                'is_admin' => true,
                'companies' => $companies,
                'company' => null,
                'settings' => null,
            ]);
        }

        $company = Company::find($companyId);
        if (! $company) {
            return response()->json(['message' => 'Empresa não encontrada.'], 404);
        }

        $settings = $company->botSetting ?: $this->buildDefaultSettings($company->id);

        return response()->json([
            'authenticated' => true,
            'role' => $user->isSystemAdmin() ? 'admin' : 'company',
            'is_admin' => $user->isSystemAdmin(),
            'companies' => $companies,
            'company' => $company,
            'settings' => $settings,
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user || ! $this->aiAccess->canAccessBotSettings($user)) {
            return response()->json([
                'authenticated' => false,
                'redirect' => '/entrar',
            ], 403);
        }

        $companyId = $user->isSystemAdmin()
            ? (int) $request->integer('company_id', 0)
            : (int) $user->company_id;

        if ($companyId <= 0) {
            return response()->json(['message' => 'Informe company_id.'], 422);
        }

        $company = Company::find($companyId);
        if (! $company) {
            return response()->json(['message' => 'Empresa não encontrada.'], 404);
        }

        $validated = $request->validate([
            // ── Credenciais do WhatsApp (opcionais — só salva se enviado) ──
            'meta_phone_number_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'meta_access_token'    => ['sometimes', 'nullable', 'string', 'max:1000'],
            // ── Campos legados do bot clássico ─────────────────────────────
            'is_active' => ['required', 'boolean'],
            'timezone' => ['required', 'string', Rule::in(timezone_identifiers_list())],
            'welcome_message' => ['nullable', 'string', 'max:2000'],
            'fallback_message' => ['nullable', 'string', 'max:2000'],
            'out_of_hours_message' => ['nullable', 'string', 'max:2000'],
            'business_hours' => ['required', 'array'],
            'business_hours.*.enabled' => ['required', 'boolean'],
            'business_hours.*.start' => ['nullable', 'date_format:H:i'],
            'business_hours.*.end' => ['nullable', 'date_format:H:i'],
            'keyword_replies' => ['nullable', 'array', 'max:200'],
            'keyword_replies.*.keyword' => ['required_with:keyword_replies', 'string', 'max:120'],
            'keyword_replies.*.reply' => ['required_with:keyword_replies', 'string', 'max:2000'],
            'service_areas' => ['nullable', 'array', 'max:50'],
            'service_areas.*' => ['string', 'max:120'],
            'stateful_menu_flow' => ['nullable', 'array'],
            'inactivity_close_hours' => ['nullable', 'integer', 'min:1', 'max:720'],
            // ── Campos de IA (todos opcionais para manter compat. legada) ──
            'ai_enabled' => ['sometimes', 'boolean'],
            'ai_internal_chat_enabled' => ['sometimes', 'boolean'],
            'ai_usage_enabled' => ['sometimes', 'boolean'],
            'ai_usage_limit_monthly' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'ai_chatbot_enabled' => ['sometimes', 'boolean'],
            'ai_chatbot_auto_reply_enabled' => ['sometimes', 'boolean'],
            'ai_chatbot_mode' => ['sometimes', 'nullable', 'string', Rule::in(['disabled', 'always', 'fallback', 'outside_business_hours'])],
            'ai_persona' => ['sometimes', 'nullable', 'string', 'max:500'],
            'ai_tone' => ['sometimes', 'nullable', 'string', 'max:120'],
            'ai_language' => ['sometimes', 'nullable', 'string', 'max:50'],
            'ai_formality' => ['sometimes', 'nullable', 'string', 'max:50'],
            'ai_system_prompt' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'ai_max_context_messages' => ['sometimes', 'nullable', 'integer', 'min:10', 'max:20'],
            'ai_temperature' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:2'],
            'ai_max_response_tokens' => ['sometimes', 'nullable', 'integer', 'min:64', 'max:4096'],
            'ai_provider' => ['sometimes', 'nullable', 'string', 'max:60'],
            'ai_model' => ['sometimes', 'nullable', 'string', 'max:120'],
            'ai_chatbot_rules' => ['sometimes', 'nullable', 'array', 'max:50'],
            'ai_chatbot_rules.*' => ['string', 'max:500'],
        ]);

        // Campos de IA presentes no request são adicionados dinamicamente para
        // não sobrescrever valores existentes quando payloads legados os omitem.
        $aiFields = [
            'ai_enabled', 'ai_internal_chat_enabled', 'ai_usage_enabled',
            'ai_usage_limit_monthly', 'ai_chatbot_enabled', 'ai_chatbot_auto_reply_enabled',
            'ai_chatbot_mode', 'ai_persona', 'ai_tone', 'ai_language', 'ai_formality',
            'ai_system_prompt', 'ai_max_context_messages', 'ai_temperature',
            'ai_max_response_tokens', 'ai_provider', 'ai_model', 'ai_chatbot_rules',
        ];

        $aiData = [];
        foreach ($aiFields as $field) {
            if (array_key_exists($field, $validated)) {
                $aiData[$field] = $validated[$field];
            }
        }

        // Salva credenciais do WhatsApp se enviadas no payload
        $credentialsChanged = false;
        if (array_key_exists('meta_phone_number_id', $validated) || array_key_exists('meta_access_token', $validated)) {
            $newPhoneId = array_key_exists('meta_phone_number_id', $validated)
                ? ($validated['meta_phone_number_id'] !== null ? trim((string) $validated['meta_phone_number_id']) : null)
                : null;
            $newToken = array_key_exists('meta_access_token', $validated)
                ? ($validated['meta_access_token'] !== null ? trim((string) $validated['meta_access_token']) : null)
                : null;

            $phoneChanged = $newPhoneId !== null && $newPhoneId !== '' && $newPhoneId !== (string) ($company->meta_phone_number_id ?? '');
            $tokenChanged = $newToken !== null && $newToken !== '' && $newToken !== (string) ($company->meta_access_token ?? '');
            $credentialsChanged = $phoneChanged || $tokenChanged;

            if ($credentialsChanged) {
                $phoneToValidate = ($newPhoneId !== null && $newPhoneId !== '') ? $newPhoneId : (string) ($company->meta_phone_number_id ?? '');
                $tokenToValidate = ($newToken !== null && $newToken !== '') ? $newToken : (string) ($company->meta_access_token ?? '');

                $validation = $this->credentialsValidator->validate($phoneToValidate, $tokenToValidate);
                if (! $validation['ok']) {
                    return response()->json([
                        'message' => 'Credenciais do WhatsApp inválidas: ' . $validation['error'],
                    ], 422);
                }
            }

            if ($newPhoneId !== null && $newPhoneId !== '') {
                $company->meta_phone_number_id = $newPhoneId;
            }
            if ($newToken !== null && $newToken !== '') {
                $company->meta_access_token = $newToken;
            }
            if ($company->isDirty()) {
                $company->save();
            }
        }

        $settings = CompanyBotSetting::updateOrCreate(
            ['company_id' => $company->id],
            array_merge(
                [
                    'is_active' => (bool) $validated['is_active'],
                    'timezone' => $validated['timezone'],
                    'welcome_message' => $validated['welcome_message'] ?? null,
                    'fallback_message' => $validated['fallback_message'] ?? null,
                    'out_of_hours_message' => $validated['out_of_hours_message'] ?? null,
                    'business_hours' => $this->normalizeBusinessHours($validated['business_hours']),
                    'keyword_replies' => $this->normalizeKeywordReplies($validated['keyword_replies'] ?? []),
                    'service_areas' => $this->normalizeServiceAreas($validated['service_areas'] ?? []),
                    'stateful_menu_flow' => $this->normalizeStatefulMenuFlow($validated['stateful_menu_flow'] ?? null),
                    'inactivity_close_hours' => $this->resolveInactivityCloseHours($company, $validated),
                ],
                $aiData
            )
        );

        $this->syncServiceAreas($company->id, $settings->service_areas ?? []);

        $this->auditLog->record(
            $request,
            'company.bot_settings.updated',
            $company->id,
            [
                'is_active' => $settings->is_active,
                'timezone' => $settings->timezone,
                'keyword_replies_count' => count($settings->keyword_replies ?? []),
            ]
        );

        return response()->json([
            'ok' => true,
            'settings' => $settings,
        ]);
    }

    /** Testa as credenciais do WhatsApp contra a API da Meta. */
    public function validateWhatsApp(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user || ! $this->aiAccess->canAccessBotSettings($user)) {
            return response()->json(['authenticated' => false, 'redirect' => '/entrar'], 403);
        }

        $companyId = $user->isSystemAdmin()
            ? (int) $request->integer('company_id', 0)
            : (int) $user->company_id;

        if ($companyId <= 0) {
            return response()->json(['message' => 'Informe company_id.'], 422);
        }

        $company = Company::find($companyId);
        if (! $company) {
            return response()->json(['message' => 'Empresa não encontrada.'], 404);
        }

        $request->validate([
            'phone_number_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'access_token'    => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        // Usa os valores enviados no request; se omitidos, usa os salvos na empresa ou env
        $phoneNumberId = trim((string) ($request->input('phone_number_id') ?? $company->meta_phone_number_id ?? config('whatsapp.phone_number_id', '')));
        $accessToken   = trim((string) ($request->input('access_token')    ?? $company->meta_access_token    ?? config('whatsapp.access_token', '')));

        if ($phoneNumberId === '') {
            return response()->json(['ok' => false, 'error' => 'phone_number_id não configurado.'], 422);
        }
        if ($accessToken === '') {
            return response()->json(['ok' => false, 'error' => 'access_token não configurado.'], 422);
        }

        $result = $this->credentialsValidator->validate($phoneNumberId, $accessToken);

        return response()->json($result, $result['ok'] ? 200 : 422);
    }

    private function buildDefaultSettings(int $companyId): CompanyBotSetting
    {
        return new CompanyBotSetting([
            // Campos do bot clássico
            'company_id' => $companyId,
            'is_active' => true,
            'timezone' => 'America/Sao_Paulo',
            'welcome_message' => 'Oi. Como posso ajudar?',
            'fallback_message' => 'Nao entendi sua mensagem. Pode reformular?',
            'out_of_hours_message' => 'Estamos fora do horario de atendimento no momento.',
            'business_hours' => $this->defaultBusinessHours(),
            'keyword_replies' => [],
            'service_areas' => [],
            'stateful_menu_flow' => null,
            'inactivity_close_hours' => 24,
            // Campos de IA — espelham os defaults das colunas no banco
            'ai_enabled' => false,
            'ai_internal_chat_enabled' => false,
            'ai_usage_enabled' => true,
            'ai_usage_limit_monthly' => null,
            'ai_chatbot_enabled' => false,
            'ai_chatbot_auto_reply_enabled' => false,
            'ai_chatbot_mode' => 'disabled',
            'ai_chatbot_rules' => null,
            'ai_persona' => null,
            'ai_tone' => null,
            'ai_language' => null,
            'ai_formality' => null,
            'ai_system_prompt' => null,
            'ai_max_context_messages' => 10,
            'ai_temperature' => null,
            'ai_max_response_tokens' => null,
            'ai_provider' => null,
            'ai_model' => null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function resolveInactivityCloseHours(Company $company, array $validated): int
    {
        if (array_key_exists('inactivity_close_hours', $validated) && $validated['inactivity_close_hours'] !== null) {
            return (int) $validated['inactivity_close_hours'];
        }

        $existing = $company->botSetting?->inactivity_close_hours;
        if (is_numeric($existing)) {
            $hours = (int) $existing;
            if ($hours >= 1 && $hours <= 720) {
                return $hours;
            }
        }

        return 24;
    }

    private function defaultBusinessHours(): array
    {
        return [
            'monday' => ['enabled' => true, 'start' => '08:00', 'end' => '18:00'],
            'tuesday' => ['enabled' => true, 'start' => '08:00', 'end' => '18:00'],
            'wednesday' => ['enabled' => true, 'start' => '08:00', 'end' => '18:00'],
            'thursday' => ['enabled' => true, 'start' => '08:00', 'end' => '18:00'],
            'friday' => ['enabled' => true, 'start' => '08:00', 'end' => '18:00'],
            'saturday' => ['enabled' => false, 'start' => null, 'end' => null],
            'sunday' => ['enabled' => false, 'start' => null, 'end' => null],
        ];
    }

    private function normalizeBusinessHours(array $hours): array
    {
        $defaults = $this->defaultBusinessHours();

        foreach ($defaults as $day => $defaultValue) {
            $current = $hours[$day] ?? [];
            $defaults[$day] = [
                'enabled' => (bool) ($current['enabled'] ?? false),
                'start' => $current['start'] ?? null,
                'end' => $current['end'] ?? null,
            ];
        }

        return $defaults;
    }

    private function normalizeKeywordReplies(array $replies): array
    {
        $normalized = [];

        foreach ($replies as $item) {
            $keyword = trim((string) ($item['keyword'] ?? ''));
            $reply = trim((string) ($item['reply'] ?? ''));
            if ($keyword === '' || $reply === '') {
                continue;
            }

            $normalized[] = [
                'keyword' => $keyword,
                'reply' => $reply,
            ];
        }

        return $normalized;
    }

    private function normalizeServiceAreas(array $areas): array
    {
        $normalized = [];
        $seen = [];

        foreach ($areas as $area) {
            $label = trim((string) $area);
            if ($label === '') {
                continue;
            }

            $key = mb_strtolower($label);
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $normalized[] = $label;
        }

        return $normalized;
    }

    private function syncServiceAreas(int $companyId, array $areaNames): void
    {
        $names = collect($areaNames)
            ->map(fn($name) => trim((string) $name))
            ->filter()
            ->unique(fn($name) => mb_strtolower($name))
            ->values();

        $areas = Area::query()
            ->where('company_id', $companyId)
            ->get();

        $keepIds = [];
        foreach ($names as $name) {
            $existing = $areas->first(
                fn(Area $area) => mb_strtolower(trim((string) $area->name)) === mb_strtolower((string) $name)
            );

            if ($existing) {
                if ($existing->name !== $name) {
                    $existing->name = (string) $name;
                    $existing->save();
                }
                $keepIds[] = $existing->id;
                continue;
            }

            $created = Area::create([
                'company_id' => $companyId,
                'name' => (string) $name,
            ]);
            $keepIds[] = $created->id;
        }

        if ($keepIds === []) {
            return;
        }

        Area::query()
            ->where('company_id', $companyId)
            ->whereNotIn('id', $keepIds)
            ->whereDoesntHave('currentConversations')
            ->whereDoesntHave('users')
            ->delete();
    }

    /**
     * @param  array<string, mixed>|null  $flow
     * @return array<string, mixed>|null
     */
    private function normalizeStatefulMenuFlow(?array $flow): ?array
    {
        if (! is_array($flow)) {
            return null;
        }

        return $flow;
    }

    /** Usage snapshot for the current company (limits + counters). */
    public function usageSnapshot(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user || ! $user->isCompanyUser()) {
            return response()->json(['authenticated' => false, 'redirect' => '/entrar'], 403);
        }

        $companyId = $user->isSystemAdmin()
            ? (int) $request->integer('company_id', (int) ($user->company_id ?? 0))
            : (int) $user->company_id;

        if ($companyId <= 0) {
            return response()->json(['usage' => null]);
        }

        return response()->json([
            'usage' => $this->usageLimits->snapshot($companyId),
        ]);
    }
}
