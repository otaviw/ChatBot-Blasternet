<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessCampaignJob;
use App\Models\Campaign;
use App\Models\CampaignContact;
use App\Models\Contact;
use App\Services\RealtimePublisher;
use App\Support\RealtimeEvents;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CampaignController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $companyId = $this->resolveCompanyId($request);
        if ($companyId === null) {
            return response()->json(['message' => 'Empresa não identificada.'], 403);
        }

        $campaigns = Campaign::where('company_id', $companyId)
            ->withCount([
                'campaignContacts',
                'campaignContacts as sent_count'     => fn ($q) => $q->where('status', 'sent'),
                'campaignContacts as failed_count'   => fn ($q) => $q->where('status', 'failed'),
                'campaignContacts as skipped_count'  => fn ($q) => $q->where('status', 'skipped'),
                'campaignContacts as pending_count'  => fn ($q) => $q->where('status', 'pending'),
            ])
            ->latest()
            ->get();

        return response()->json(['campaigns' => $campaigns]);
    }

    public function store(Request $request): JsonResponse
    {
        $companyId = $this->resolveCompanyId($request);
        if ($companyId === null) {
            return response()->json(['message' => 'Empresa não identificada.'], 403);
        }

        $validated = $request->validate([
            'name'        => ['required', 'string', 'max:255'],
            'type'        => ['required', Rule::in(['free', 'template', 'open'])],
            'message'     => ['nullable', 'string', 'max:4096'],
            'template_id' => ['nullable', 'string', 'max:255'],
            'contact_ids' => ['sometimes', 'array'],
            'contact_ids.*' => [
                'integer',
                Rule::exists('contacts', 'id')->where(fn ($query) => $query->where('company_id', $companyId)),
            ],
        ]);

        $campaign = Campaign::create([
            'company_id'  => $companyId,
            'name'        => $validated['name'],
            'type'        => $validated['type'],
            'message'     => $validated['message'] ?? null,
            'template_id' => $validated['template_id'] ?? null,
            'status'      => 'draft',
        ]);

        $selectedContactIds = collect($validated['contact_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values();

        if ($selectedContactIds->isNotEmpty()) {
            $now = now();
            CampaignContact::insertOrIgnore(
                $selectedContactIds->map(fn ($contactId) => [
                    'campaign_id' => $campaign->id,
                    'contact_id'  => $contactId,
                    'status'      => 'pending',
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ])->all()
            );
        }

        return response()->json(['campaign' => $campaign], 201);
    }

    public function validateContacts(Request $request): JsonResponse
    {
        $companyId = $this->resolveCompanyId($request);
        if ($companyId === null) {
            return response()->json(['message' => 'Empresa nÃ£o identificada.'], 403);
        }

        $validated = $request->validate([
            'type' => ['required', Rule::in(['free', 'template', 'open'])],
            'contact_ids' => ['required', 'array', 'min:1'],
            'contact_ids.*' => ['integer'],
        ]);

        $requestedIds = collect($validated['contact_ids'])
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values();

        if ($requestedIds->isEmpty()) {
            return response()->json([
                'eligible_count' => 0,
                'outside_window_count' => 0,
                'invalid_count' => 0,
            ]);
        }

        $contacts = Contact::where('company_id', $companyId)
            ->whereIn('id', $requestedIds->all())
            ->get(['id', 'phone', 'last_interaction_at']);

        $foundIds = $contacts->pluck('id')->map(fn ($id) => (int) $id)->all();
        $notFoundCount = $requestedIds->diff($foundIds)->count();

        $invalidPhoneCount = $contacts
            ->filter(fn (Contact $contact) => trim((string) $contact->phone) === '')
            ->count();

        $validContacts = $contacts->filter(fn (Contact $contact) => trim((string) $contact->phone) !== '');
        $outsideWindowCount = $validated['type'] === 'free'
            ? $validContacts->filter(fn (Contact $contact) => ! $contact->isWithin24h())->count()
            : 0;

        $eligibleCount = max(0, $validContacts->count() - $outsideWindowCount);
        $invalidCount = $notFoundCount + $invalidPhoneCount;

        return response()->json([
            'eligible_count' => $eligibleCount,
            'outside_window_count' => $outsideWindowCount,
            'invalid_count' => $invalidCount,
        ]);
    }

    public function show(Request $request, int $campaignId): JsonResponse
    {
        $companyId = $this->resolveCompanyId($request);
        if ($companyId === null) {
            return response()->json(['message' => 'Empresa não identificada.'], 403);
        }

        $campaign = Campaign::where('company_id', $companyId)
            ->withCount([
                'campaignContacts',
                'campaignContacts as sent_count'    => fn ($q) => $q->where('status', 'sent'),
                'campaignContacts as failed_count'  => fn ($q) => $q->where('status', 'failed'),
                'campaignContacts as skipped_count' => fn ($q) => $q->where('status', 'skipped'),
                'campaignContacts as pending_count' => fn ($q) => $q->where('status', 'pending'),
            ])
            ->find($campaignId);

        if ($campaign === null) {
            return response()->json(['message' => 'Campanha não encontrada.'], 404);
        }

        return response()->json(['campaign' => $campaign]);
    }

    /**
     * Inicia o envio de uma campanha:
     * 1. Valida que está em draft
     * 2. Cria campaign_contacts para todos os contatos da empresa (sem duplicar)
     * 3. Marca como "sending" de forma atômica
     * 4. Dispara o Job na fila
     */
    public function start(Request $request, int $campaignId, RealtimePublisher $realtimePublisher): JsonResponse
    {
        $companyId = $this->resolveCompanyId($request);
        if ($companyId === null) {
            return response()->json(['message' => 'Empresa não identificada.'], 403);
        }

        $campaign = Campaign::where('company_id', $companyId)->find($campaignId);

        if ($campaign === null) {
            return response()->json(['message' => 'Campanha não encontrada.'], 404);
        }

        if ($campaign->status === 'sending') {
            return response()->json(['message' => 'Campanha já está sendo enviada.'], 409);
        }

        if ($campaign->status === 'finished') {
            return response()->json(['message' => 'Campanha já foi finalizada.'], 409);
        }

        $preselectedIds = $campaign->campaignContacts()
            ->select('contact_id')
            ->pluck('contact_id')
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values();

        $contactIds = $preselectedIds->isNotEmpty()
            ? $preselectedIds
            : Contact::where('company_id', $companyId)->pluck('id');

        if ($contactIds->isEmpty()) {
            return response()->json(['message' => 'Nenhum contato encontrado para esta empresa.'], 422);
        }

        DB::transaction(function () use ($campaign, $contactIds) {
            $now = now();

            // insertOrIgnore respeita o unique (campaign_id, contact_id)
            // — re-tentativas não duplicam registros já existentes
            foreach ($contactIds->chunk(500) as $chunk) {
                CampaignContact::insertOrIgnore(
                    $chunk->map(fn ($contactId) => [
                        'campaign_id' => $campaign->id,
                        'contact_id'  => $contactId,
                        'status'      => 'pending',
                        'created_at'  => $now,
                        'updated_at'  => $now,
                    ])->all()
                );
            }

            $campaign->status = 'sending';
            $campaign->save();
        });

        ProcessCampaignJob::dispatch($campaign->id);

        $campaignWithCounts = $this->loadCampaignWithCounts($companyId, (int) $campaign->id);
        if ($campaignWithCounts instanceof Campaign) {
            $this->publishCampaignUpdated($campaignWithCounts, $realtimePublisher);
        }

        return response()->json([
            'ok'      => true,
            'message' => 'Campanha iniciada.',
            'total'   => $contactIds->count(),
        ]);
    }

    public function destroy(Request $request, int $campaignId): JsonResponse
    {
        $companyId = $this->resolveCompanyId($request);
        if ($companyId === null) {
            return response()->json(['message' => 'Empresa não identificada.'], 403);
        }

        $campaign = Campaign::where('company_id', $companyId)->find($campaignId);

        if ($campaign === null) {
            return response()->json(['message' => 'Campanha não encontrada.'], 404);
        }

        if ($campaign->status === 'sending') {
            return response()->json(['message' => 'Não é possível excluir uma campanha em envio.'], 409);
        }

        $campaign->delete();

        return response()->json(['ok' => true]);
    }

    private function loadCampaignWithCounts(int $companyId, int $campaignId): ?Campaign
    {
        return Campaign::where('company_id', $companyId)
            ->withCount([
                'campaignContacts',
                'campaignContacts as sent_count' => fn ($q) => $q->where('status', 'sent'),
                'campaignContacts as failed_count' => fn ($q) => $q->where('status', 'failed'),
                'campaignContacts as skipped_count' => fn ($q) => $q->where('status', 'skipped'),
                'campaignContacts as pending_count' => fn ($q) => $q->where('status', 'pending'),
            ])
            ->find($campaignId);
    }

    private function publishCampaignUpdated(Campaign $campaign, RealtimePublisher $realtimePublisher): void
    {
        $sent = (int) ($campaign->sent_count ?? 0);
        $failed = (int) ($campaign->failed_count ?? 0);
        $skipped = (int) ($campaign->skipped_count ?? 0);
        $pending = (int) ($campaign->pending_count ?? 0);

        $realtimePublisher->publish(
            RealtimeEvents::CAMPAIGN_UPDATED,
            ["company:{$campaign->company_id}"],
            [
                'campaignId' => (int) $campaign->id,
                'companyId' => (int) $campaign->company_id,
                'status' => (string) $campaign->status,
                'sentCount' => $sent,
                'failedCount' => $failed,
                'skippedCount' => $skipped,
                'pendingCount' => $pending,
                'sent_count' => $sent,
                'failed_count' => $failed,
                'skipped_count' => $skipped,
                'pending_count' => $pending,
                'updatedAt' => now()->toISOString(),
                'counters' => [
                    'sent' => $sent,
                    'failed' => $failed,
                    'skipped' => $skipped,
                    'pending' => $pending,
                    'sent_count' => $sent,
                    'failed_count' => $failed,
                    'skipped_count' => $skipped,
                    'pending_count' => $pending,
                ],
            ]
        );
    }

    private function resolveCompanyId(Request $request): ?int
    {
        $user = $request->user();
        if (! $user) {
            return null;
        }

        $companyId = $user->isSystemAdmin()
            ? (int) $request->integer('company_id', 0)
            : (int) $user->company_id;

        return $companyId > 0 ? $companyId : null;
    }
}
