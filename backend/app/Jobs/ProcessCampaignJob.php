<?php

declare(strict_types=1);


namespace App\Jobs;

use App\Models\Campaign;
use App\Models\CampaignContact;
use App\Models\Contact;
use App\Services\RealtimePublisher;
use App\Services\WhatsApp\WhatsAppSendService;
use App\Support\RealtimeEvents;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessCampaignJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(private readonly int $campaignId)
    {
        $this->onQueue('campaigns');
    }

    public function handle(WhatsAppSendService $whatsAppSend, RealtimePublisher $realtimePublisher): void
    {
        $campaign = Campaign::with('company')->find($this->campaignId);

        if ($campaign === null) {
            Log::warning('ProcessCampaignJob: campanha não encontrada.', [
                'campaign_id' => $this->campaignId,
            ]);

            return;
        }

        $campaign->status = 'sending';
        $campaign->save();

        $counters = $this->buildCounters($campaign);
        $this->publishCampaignUpdated($campaign, $realtimePublisher, $counters);

        Log::info('ProcessCampaignJob: iniciando envio.', [
            'campaign_id' => $campaign->id,
            'type' => $campaign->type,
        ]);

        $pending = $campaign->campaignContacts()
            ->where('status', 'pending')
            ->with('contact')
            ->get();

        $batches = $pending->chunk(10);
        $lastIndex = $batches->count() - 1;

        foreach ($batches as $batchIndex => $batch) {
            foreach ($batch as $campaignContact) {
                $this->processContact(
                    $campaignContact,
                    $campaign,
                    $whatsAppSend,
                    $realtimePublisher,
                    $counters
                );
            }

            if ($batchIndex < $lastIndex) {
                sleep(2);
            }
        }

        $campaign->status = 'finished';
        $campaign->save();
        $this->publishCampaignUpdated($campaign, $realtimePublisher, $counters);

        Log::info('ProcessCampaignJob: campanha finalizada.', [
            'campaign_id' => $campaign->id,
            'total' => $pending->count(),
        ]);
    }

    /**
     * @param  array<string, int>  $counters
     */
    private function processContact(
        CampaignContact $campaignContact,
        Campaign $campaign,
        WhatsAppSendService $whatsAppSend,
        RealtimePublisher $realtimePublisher,
        array &$counters
    ): void {
        try {
            $contact = $campaignContact->contact;

            if (! $contact instanceof Contact) {
                $campaignContact->status = 'failed';
                $campaignContact->error = 'contato_nao_encontrado';
                $campaignContact->save();

                $this->applyCountersAfterProcessedContact($counters, 'failed');
                $this->publishCampaignUpdated($campaign, $realtimePublisher, $counters);

                return;
            }

            $result = match ($campaign->type) {
                'free' => $this->sendFree($campaign, $contact, $whatsAppSend),
                'template', 'open' => $this->sendTemplate($campaign, $contact, $whatsAppSend),
                default => ['ok' => false, 'skipped' => true, 'error' => 'tipo_desconhecido'],
            };

            if ($result['skipped'] ?? false) {
                $campaignContact->status = 'skipped';
            } elseif ($result['ok']) {
                $campaignContact->status = 'sent';
                $campaignContact->sent_at = now();
                $campaignContact->error = null;
            } else {
                $campaignContact->status = 'failed';
                $campaignContact->error = $this->serializeError($result['error'] ?? null);
            }

            $campaignContact->save();

            $this->applyCountersAfterProcessedContact($counters, (string) $campaignContact->status);
            $this->publishCampaignUpdated($campaign, $realtimePublisher, $counters);

            Log::info('ProcessCampaignJob: contato processado.', [
                'campaign_id' => $campaign->id,
                'campaign_contact_id' => $campaignContact->id,
                'contact_phone' => $contact->phone,
                'status' => $campaignContact->status,
            ]);
        } catch (Throwable $e) {
            Log::error('ProcessCampaignJob: erro inesperado ao processar contato.', [
                'campaign_id' => $campaign->id,
                'campaign_contact_id' => $campaignContact->id,
                'error' => $e->getMessage(),
            ]);

            try {
                $campaignContact->status = 'failed';
                $campaignContact->error = $e->getMessage();
                $campaignContact->save();

                $this->applyCountersAfterProcessedContact($counters, 'failed');
                $this->publishCampaignUpdated($campaign, $realtimePublisher, $counters);
            } catch (Throwable) {
                // Ignore secondary failure and continue processing next contacts.
            }
        }
    }

    private function sendFree(Campaign $campaign, Contact $contact, WhatsAppSendService $whatsAppSend): array
    {
        if (! $contact->isWithin24h()) {
            return ['ok' => false, 'skipped' => true, 'error' => null];
        }

        return $whatsAppSend->sendText(
            $campaign->company,
            $contact->phone,
            (string) $campaign->message
        );
    }

    private function sendTemplate(Campaign $campaign, Contact $contact, WhatsAppSendService $whatsAppSend): array
    {
        return $whatsAppSend->sendTemplateMessage(
            $campaign->company,
            $contact->phone,
            (string) $campaign->template_id
        );
    }

    /**
     * @return array<string, int>
     */
    private function buildCounters(Campaign $campaign): array
    {
        return [
            'sent' => (int) $campaign->campaignContacts()->where('status', 'sent')->count(),
            'failed' => (int) $campaign->campaignContacts()->where('status', 'failed')->count(),
            'skipped' => (int) $campaign->campaignContacts()->where('status', 'skipped')->count(),
            'pending' => (int) $campaign->campaignContacts()->where('status', 'pending')->count(),
        ];
    }

    /**
     * @param  array<string, int>  $counters
     */
    private function applyCountersAfterProcessedContact(array &$counters, string $newStatus): void
    {
        $counters['pending'] = max(0, (int) ($counters['pending'] ?? 0) - 1);

        if (in_array($newStatus, ['sent', 'failed', 'skipped'], true)) {
            $counters[$newStatus] = (int) ($counters[$newStatus] ?? 0) + 1;
        }
    }

    /**
     * @param  array<string, int>  $counters
     */
    private function publishCampaignUpdated(
        Campaign $campaign,
        RealtimePublisher $realtimePublisher,
        array $counters
    ): void {
        $sent = max(0, (int) ($counters['sent'] ?? 0));
        $failed = max(0, (int) ($counters['failed'] ?? 0));
        $skipped = max(0, (int) ($counters['skipped'] ?? 0));
        $pending = max(0, (int) ($counters['pending'] ?? 0));

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

    /**
     * Chamado pelo framework quando o job falha após esgotar todas as tentativas.
     *
     * Com tries = 1 qualquer exceção não capturada no handle() chega aqui.
     * Sem este método, a campanha ficaria presa no status 'sending' indefinidamente.
     * Contatos já processados mantêm seus status — apenas os que ainda estão
     * 'pending' continuam pendentes para diagnóstico manual.
     */
    public function failed(?\Throwable $exception): void
    {
        Log::error('ProcessCampaignJob: falhou — campanha encerrada com erro.', [
            'campaign_id'     => $this->campaignId,
            'exception_class' => $exception !== null ? get_class($exception) : null,
            'exception'       => $exception?->getMessage(),
        ]);

        $campaign = Campaign::find($this->campaignId);

        if ($campaign !== null && $campaign->status === 'sending') {
            $campaign->status = 'finished';
            $campaign->save();
        }
    }

    private function serializeError(mixed $error): string
    {
        if ($error === null) {
            return 'erro_desconhecido';
        }

        if (is_string($error)) {
            return $error;
        }

        return json_encode($error) ?: 'erro_desconhecido';
    }
}
