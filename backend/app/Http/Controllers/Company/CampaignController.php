<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessCampaignJob;
use App\Models\Campaign;
use App\Models\CampaignContact;
use App\Models\Contact;
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
        ]);

        $campaign = Campaign::create([
            'company_id'  => $companyId,
            'name'        => $validated['name'],
            'type'        => $validated['type'],
            'message'     => $validated['message'] ?? null,
            'template_id' => $validated['template_id'] ?? null,
            'status'      => 'draft',
        ]);

        return response()->json(['campaign' => $campaign], 201);
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
    public function start(Request $request, int $campaignId): JsonResponse
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

        $contactIds = Contact::where('company_id', $companyId)->pluck('id');

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
