<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Models\Area;
use App\Models\Company;
use App\Models\CompanyBotSetting;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BotController extends Controller
{
    public function __construct(
        private AuditLogService $auditLog
    ) {}

    /** Configuracoes do bot da empresa logada (respostas, horarios, etc.). */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user || ! $user->isCompanyUser()) {
            return response()->json([
                'authenticated' => false,
                'redirect' => '/entrar',
            ], 403);
        }
        $companyId = (int) $user->company_id;

        $company = Company::find($companyId);
        if (! $company) {
            return response()->json([
                'authenticated' => false,
                'redirect' => '/entrar',
            ], 404);
        }

        $settings = $company->botSetting ?: $this->buildDefaultSettings($company->id);

        return response()->json([
            'authenticated' => true,
            'role' => 'company',
            'company' => $company,
            'settings' => $settings,
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user || ! $user->isCompanyUser()) {
            return response()->json([
                'authenticated' => false,
                'redirect' => '/entrar',
            ], 403);
        }
        $companyId = (int) $user->company_id;

        $company = Company::find($companyId);
        if (! $company) {
            return response()->json([
                'authenticated' => false,
                'redirect' => '/entrar',
            ], 404);
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
            'service_areas' => ['nullable', 'array', 'max:50'],
            'service_areas.*' => ['string', 'max:120'],
            'inactivity_close_hours' => ['required', 'integer', 'min:1', 'max:720'],
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
                'service_areas' => $this->normalizeServiceAreas($validated['service_areas'] ?? []),
                'inactivity_close_hours' => $validated['inactivity_close_hours'],
            ]
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

    private function buildDefaultSettings(int $companyId): CompanyBotSetting
    {
        return new CompanyBotSetting([
            'company_id' => $companyId,
            'is_active' => true,
            'timezone' => 'America/Sao_Paulo',
            'welcome_message' => 'Oi. Como posso ajudar?',
            'fallback_message' => 'Nao entendi sua mensagem. Pode reformular?',
            'out_of_hours_message' => 'Estamos fora do horario de atendimento no momento.',
            'business_hours' => $this->defaultBusinessHours(),
            'keyword_replies' => [],
            'service_areas' => [],
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
}
