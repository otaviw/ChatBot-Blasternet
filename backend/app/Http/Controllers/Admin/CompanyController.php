<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CompanyBotSetting;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CompanyController extends Controller
{
    public function __construct(
        private AuditLogService $auditLog
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user || ! $user->isAdmin()) {
            return response()->json([
                'authenticated' => false,
                'redirect' => '/entrar',
            ], 403);
        }

        $companies = Company::with(['botSetting'])
            ->withCount('conversations')
            ->orderBy('name')
            ->get();

        return response()->json([
            'authenticated' => true,
            'role' => 'admin',
            'companies' => $companies,
        ]);
    }

    public function show(Request $request, Company $company): JsonResponse
    {
        $user = $request->user();
        if (! $user || ! $user->isAdmin()) {
            return response()->json([
                'authenticated' => false,
                'redirect' => '/entrar',
            ], 403);
        }

        $company->loadCount('conversations');
        $company->load([
            'conversations' => fn ($q) => $q->latest()->limit(10),
            'botSetting',
        ]);

        return response()->json([
            'authenticated' => true,
            'role' => 'admin',
            'company' => $company,
        ]);
    }

    public function updateBotSettings(Request $request, Company $company): JsonResponse
    {
        $user = $request->user();
        if (! $user || ! $user->isAdmin()) {
            return response()->json([
                'authenticated' => false,
                'redirect' => '/entrar',
            ], 403);
        }

        $validated = $request->validate([
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
        ]);

        $settings = CompanyBotSetting::updateOrCreate(
            ['company_id' => $company->id],
            [
                'is_active' => (bool) $validated['is_active'],
                'timezone' => $validated['timezone'],
                'welcome_message' => $validated['welcome_message'] ?? null,
                'fallback_message' => $validated['fallback_message'] ?? null,
                'out_of_hours_message' => $validated['out_of_hours_message'] ?? null,
                'business_hours' => $this->normalizeBusinessHours($validated['business_hours']),
                'keyword_replies' => $this->normalizeKeywordReplies($validated['keyword_replies'] ?? []),
            ]
        );

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

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user || ! $user->isAdmin()) {
            return response()->json([
                'authenticated' => false,
                'redirect' => '/entrar',
            ], 403);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120', 'unique:companies,name'],
            'meta_phone_number_id' => ['nullable', 'string', 'max:255', 'unique:companies,meta_phone_number_id'],
            'meta_access_token' => ['nullable', 'string', 'max:8000'],
        ]);

        $company = Company::create([
            'name' => $validated['name'],
            'meta_phone_number_id' => $validated['meta_phone_number_id'] ?? null,
            'meta_access_token' => $validated['meta_access_token'] ?? null,
        ]);

        CompanyBotSetting::firstOrCreate(
            ['company_id' => $company->id],
            [
                'is_active' => true,
                'timezone' => 'America/Sao_Paulo',
                'welcome_message' => 'Oi. Como posso ajudar?',
                'fallback_message' => 'Nao entendi sua mensagem. Pode reformular?',
                'out_of_hours_message' => 'Estamos fora do horario de atendimento no momento.',
                'business_hours' => $this->defaultBusinessHours(),
                'keyword_replies' => [],
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
            'company' => $company,
        ], 201);
    }

    public function update(Request $request, Company $company): JsonResponse
    {
        $user = $request->user();
        if (! $user || ! $user->isAdmin()) {
            return response()->json([
                'authenticated' => false,
                'redirect' => '/entrar',
            ], 403);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('companies', 'name')->ignore($company->id)],
            'meta_phone_number_id' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('companies', 'meta_phone_number_id')->ignore($company->id),
            ],
            'meta_access_token' => ['nullable', 'string', 'max:8000'],
        ]);

        $before = [
            'name' => $company->name,
            'meta_phone_number_id' => $company->meta_phone_number_id,
            'has_meta_credentials' => $company->hasMetaCredentials(),
        ];

        $company->name = $validated['name'];
        $company->meta_phone_number_id = $validated['meta_phone_number_id'] ?? null;
        if (array_key_exists('meta_access_token', $validated)) {
            $company->meta_access_token = $validated['meta_access_token'] ?: null;
        }
        $company->save();
        $company->refresh();

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
            'company' => $company,
        ]);
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
}
