<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCompanyRequest;
use App\Http\Requests\Admin\UpdateCompanyBotSettingsRequest;
use App\Http\Requests\Admin\UpdateCompanyRequest;
use App\Http\Requests\Admin\ValidateCompanyWhatsAppRequest;
use App\Models\Area;
use App\Models\Company;
use App\Models\CompanyBotSetting;
use App\Models\Reseller;
use App\Models\Message;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\WhatsAppCredentialsValidatorService;
use App\Support\ConversationHandlingMode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CompanyController extends Controller
{
    public function __construct(
        private AuditLogService $auditLog,
        private WhatsAppCredentialsValidatorService $credentialsValidator,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $resellerId = $this->resolveResellerId($request);

        $companies = Company::with(['botSetting'])
            ->withCount('conversations')
            ->forReseller($resellerId)
            ->orderBy('name')
            ->get();

        return response()->json([
            'authenticated' => true,
            'role' => 'admin',
            'companies' => $companies,
        ]);
    }

    private function resolveResellerId(Request $request): ?int
    {
        $user = $request->user();

        if ($user->isSystemAdmin()) {
            return null;
        }

        if ($user->isResellerAdmin()) {
            $resellerId = (int) ($user->company?->reseller_id ?? 0);
            return $resellerId > 0 ? $resellerId : -1;
        }

        return -1;
    }

    private function denyIfNotOwned(Request $request, Company $company): ?JsonResponse
    {
        $resellerId = $this->resolveResellerId($request);

        if ($resellerId !== null && (int) $company->reseller_id !== $resellerId) {
            return response()->json(['message' => 'Acesso negado para esta empresa.'], 403);
        }

        return null;
    }

    public function show(Request $request, Company $company): JsonResponse
    {
        if ($denied = $this->denyIfNotOwned($request, $company)) {
            return $denied;
        }

        $company->loadCount('conversations');
        $company->load([
            'botSetting',
        ]);

        return response()->json([
            'authenticated' => true,
            'role' => 'admin',
            'company' => $company,
        ]);
    }

    public function updateBotSettings(UpdateCompanyBotSettingsRequest $request, Company $company): JsonResponse
    {
        if ($denied = $this->denyIfNotOwned($request, $company)) {
            return $denied;
        }

        $validated = $request->validated();

        $settingsPayload = [
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
        ];

        if (array_key_exists('ai_enabled', $validated)) {
            $settingsPayload['ai_enabled'] = (bool) $validated['ai_enabled'];
        }
        if (array_key_exists('ai_internal_chat_enabled', $validated)) {
            $settingsPayload['ai_internal_chat_enabled'] = (bool) $validated['ai_internal_chat_enabled'];
        }
        if (array_key_exists('ai_chatbot_enabled', $validated)) {
            $settingsPayload['ai_chatbot_enabled'] = (bool) $validated['ai_chatbot_enabled'];
        }
        if (array_key_exists('ai_chatbot_auto_reply_enabled', $validated)) {
            $settingsPayload['ai_chatbot_auto_reply_enabled'] = (bool) $validated['ai_chatbot_auto_reply_enabled'];
        }
        if (array_key_exists('ai_chatbot_rules', $validated)) {
            $settingsPayload['ai_chatbot_rules'] = $validated['ai_chatbot_rules'];
        }
        if (array_key_exists('ai_usage_enabled', $validated)) {
            $settingsPayload['ai_usage_enabled'] = (bool) $validated['ai_usage_enabled'];
        }
        if (array_key_exists('ai_usage_limit_monthly', $validated)) {
            $settingsPayload['ai_usage_limit_monthly'] = $validated['ai_usage_limit_monthly'] !== null
                ? (int) $validated['ai_usage_limit_monthly']
                : null;
        }
        if (array_key_exists('max_users', $validated)) {
            $settingsPayload['max_users'] = $validated['max_users'] !== null ? (int) $validated['max_users'] : null;
        }
        if (array_key_exists('max_conversation_messages_monthly', $validated)) {
            $settingsPayload['max_conversation_messages_monthly'] = $validated['max_conversation_messages_monthly'] !== null
                ? (int) $validated['max_conversation_messages_monthly']
                : null;
        }
        if (array_key_exists('max_template_messages_monthly', $validated)) {
            $settingsPayload['max_template_messages_monthly'] = $validated['max_template_messages_monthly'] !== null
                ? (int) $validated['max_template_messages_monthly']
                : null;
        }

        $settings = CompanyBotSetting::updateOrCreate(
            ['company_id' => $company->id],
            $settingsPayload
        );

        $this->syncServiceAreas($company->id, $settings->service_areas ?? []);

        $this->auditLog->record(
            $request,
            'admin.company.bot_settings.updated',
            $company->id,
            [
                'is_active' => $settings->is_active,
                'timezone' => $settings->timezone,
                'keyword_replies_count' => count($settings->keyword_replies ?? []),
            ],
            [
                'target_company_id' => $company->id,
            ]
        );

        return response()->json([
            'ok' => true,
            'settings' => $settings,
        ]);
    }

    public function store(StoreCompanyRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $user = $request->user();

        if ($user->isSystemAdmin()) {
            $resellerId = $validated['reseller_id'] ?? Reseller::getBySlug('default')?->id;

            if ($resellerId === null) {
                Log::warning('Company criada sem reseller_id: reseller default não encontrado. Execute DefaultResellerSeeder.');
            }
        } else {
            $resellerId = (int) ($user->company?->reseller_id ?? 0);
            if ($resellerId <= 0) {
                return response()->json([
                    'message' => 'Usuario sem reseller vinculado.',
                ], 403);
            }
        }

        $company = Company::create([
            'name'                 => $validated['name'],
            'meta_phone_number_id' => $validated['meta_phone_number_id'] ?? null,
            'meta_waba_id'         => $validated['meta_waba_id'] ?? null,
            'reseller_id'          => $resellerId,
        ]);

        CompanyBotSetting::firstOrCreate(
            ['company_id' => $company->id],
            [
                'is_active' => true,
                'ai_enabled' => (bool) ($validated['ai_enabled'] ?? false),
                'ai_internal_chat_enabled' => (bool) ($validated['ai_internal_chat_enabled'] ?? false),
                'ai_chatbot_enabled' => (bool) ($validated['ai_chatbot_enabled'] ?? false),
                'ai_chatbot_auto_reply_enabled' => (bool) ($validated['ai_chatbot_auto_reply_enabled'] ?? false),
                'ai_chatbot_rules' => $validated['ai_chatbot_rules'] ?? null,
                'ai_usage_enabled' => (bool) ($validated['ai_usage_enabled'] ?? true),
                'ai_usage_limit_monthly' => $validated['ai_usage_limit_monthly'] ?? null,
                'max_users' => $validated['max_users'] ?? null,
                'max_conversation_messages_monthly' => $validated['max_conversation_messages_monthly'] ?? null,
                'max_template_messages_monthly' => $validated['max_template_messages_monthly'] ?? null,
                'timezone' => 'America/Sao_Paulo',
                'welcome_message' => 'Oi. Como posso ajudar?',
                'fallback_message' => 'Não entendi sua mensagem. Pode reformular?',
                'out_of_hours_message' => 'Estamos fora do horario de atendimento no momento.',
                'business_hours' => $this->defaultBusinessHours(),
                'keyword_replies' => [],
                'inactivity_close_hours' => 24,
                'service_areas' => [],
                'stateful_menu_flow' => null,
            ]
        );

        $this->auditLog->record(
            $request,
            'admin.company.created',
            $company->id,
            [
                'name' => $company->name,
                'meta_phone_number_id' => $company->meta_phone_number_id,
            ]
        );

        return response()->json([
            'ok' => true,
            'company' => $company->load('botSetting'),
        ], 201);
    }

    public function update(UpdateCompanyRequest $request, Company $company): JsonResponse
    {
        if ($denied = $this->denyIfNotOwned($request, $company)) {
            return $denied;
        }

        $validated = $request->validated();

        $before = [
            'name' => $company->name,
            'meta_phone_number_id' => $company->meta_phone_number_id,
            'has_meta_credentials' => $company->hasMetaCredentials(),
        ];

        $newPhoneId = $validated['meta_phone_number_id'] ?? null;
        $newToken   = array_key_exists('meta_access_token', $validated) ? ($validated['meta_access_token'] ?: null) : null;

        $phoneChanged = $newPhoneId !== null && $newPhoneId !== '' && $newPhoneId !== (string) ($company->meta_phone_number_id ?? '');
        $tokenChanged = $newToken !== null && $newToken !== (string) ($company->meta_access_token ?? '');

        if ($phoneChanged || $tokenChanged) {
            $phoneToValidate = $newPhoneId ?: (string) ($company->meta_phone_number_id ?? '');
            $tokenToValidate = $newToken   ?: (string) ($company->meta_access_token    ?? config('whatsapp.access_token', ''));

            if ($phoneToValidate !== '' && $tokenToValidate !== '') {
                $validation = $this->credentialsValidator->validate($phoneToValidate, $tokenToValidate);
                if (! $validation['ok']) {
                    return response()->json([
                        'message' => 'Credenciais do WhatsApp inválidas: ' . $validation['error'],
                    ], 422);
                }
            }
        }

        $company->name = $validated['name'];
        $company->meta_phone_number_id = $newPhoneId;
        $company->meta_waba_id = $validated['meta_waba_id'] ?? null;
        if ($newToken !== null) {
            $company->meta_access_token = $newToken;
        }
        $company->save();
        $company->refresh();

        $aiSettingsPayload = [];
        if (array_key_exists('ai_enabled', $validated)) {
            $aiSettingsPayload['ai_enabled'] = (bool) $validated['ai_enabled'];
        }
        if (array_key_exists('ai_internal_chat_enabled', $validated)) {
            $aiSettingsPayload['ai_internal_chat_enabled'] = (bool) $validated['ai_internal_chat_enabled'];
        }
        if (array_key_exists('ai_chatbot_enabled', $validated)) {
            $aiSettingsPayload['ai_chatbot_enabled'] = (bool) $validated['ai_chatbot_enabled'];
        }
        if (array_key_exists('ai_chatbot_auto_reply_enabled', $validated)) {
            $aiSettingsPayload['ai_chatbot_auto_reply_enabled'] = (bool) $validated['ai_chatbot_auto_reply_enabled'];
        }
        if (array_key_exists('ai_chatbot_rules', $validated)) {
            $aiSettingsPayload['ai_chatbot_rules'] = $validated['ai_chatbot_rules'];
        }
        if (array_key_exists('ai_usage_enabled', $validated)) {
            $aiSettingsPayload['ai_usage_enabled'] = (bool) $validated['ai_usage_enabled'];
        }
        if (array_key_exists('ai_usage_limit_monthly', $validated)) {
            $aiSettingsPayload['ai_usage_limit_monthly'] = $validated['ai_usage_limit_monthly'] !== null
                ? (int) $validated['ai_usage_limit_monthly']
                : null;
        }

        if ($aiSettingsPayload !== []) {
            CompanyBotSetting::updateOrCreate(
                ['company_id' => $company->id],
                $aiSettingsPayload
            );
            $company->load('botSetting');
        }

        $this->auditLog->record(
            $request,
            'admin.company.updated',
            $company->id,
            [
                'before' => $before,
                'after' => [
                    'name' => $company->name,
                    'meta_phone_number_id' => $company->meta_phone_number_id,
                    'has_meta_credentials' => $company->hasMetaCredentials(),
                ],
            ]
        );

        return response()->json([
            'ok' => true,
            'company' => $company->loadMissing('botSetting'),
        ]);
    }

    public function destroy(Request $request, Company $company): JsonResponse
    {
        if ($denied = $this->denyIfNotOwned($request, $company)) {
            return $denied;
        }

        $companyId = $company->id;
        $companyName = $company->name;

        $company->delete();

        $this->auditLog->record(
            $request,
            'admin.company.deleted',
            $companyId,
            [
                'name' => $companyName,
            ]
        );

        return response()->json([
            'ok' => true,
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

    /** Testa as credenciais do WhatsApp da empresa contra a API da Meta. */
    public function validateWhatsApp(ValidateCompanyWhatsAppRequest $request, Company $company): JsonResponse
    {
        if ($denied = $this->denyIfNotOwned($request, $company)) {
            return $denied;
        }

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

    public function metrics(Request $request, Company $company): JsonResponse
    {
        if ($denied = $this->denyIfNotOwned($request, $company)) {
            return $denied;
        }

        $conversationIds = $company->conversations()->select('id');

        $byStatus = $company->conversations()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $byMode = $company->conversations()
            ->where('status', 'closed')
            ->selectRaw(
                'CASE WHEN handling_mode = ? THEN ? ELSE handling_mode END as normalized_mode, count(*) as total',
                [ConversationHandlingMode::LEGACY_MANUAL, ConversationHandlingMode::HUMAN]
            )
            ->groupBy('normalized_mode')
            ->pluck('total', 'normalized_mode');

        $byDay = $company->conversations()
            ->selectRaw('DATE(created_at) as day, count(*) as total')
            ->where('created_at', '>=', now()->subDays(30))
            ->groupBy('day')
            ->orderBy('day')
            ->get();

        $totalMessages = Message::whereIn('conversation_id', $conversationIds)->count();
        $totalUsers = User::where('company_id', $company->id)->count();

        return response()->json([
            'authenticated' => true,
            'metrics' => [
                'by_status' => $byStatus,
                'by_mode' => $byMode,
                'by_day' => $byDay,
                'total' => $company->conversations()->count(),
                'total_messages' => $totalMessages,
                'total_users' => $totalUsers,
            ],
        ]);
    }
}

