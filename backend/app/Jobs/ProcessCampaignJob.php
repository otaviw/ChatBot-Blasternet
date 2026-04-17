<?php

namespace App\Jobs;

use App\Models\Campaign;
use App\Models\CampaignContact;
use App\Models\Contact;
use App\Services\WhatsAppSendService;
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

    public function handle(WhatsAppSendService $whatsAppSend): void
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

        Log::info('ProcessCampaignJob: iniciando envio.', [
            'campaign_id' => $campaign->id,
            'type'        => $campaign->type,
        ]);

        $pending = $campaign->campaignContacts()
            ->where('status', 'pending')
            ->with('contact')
            ->get();

        $batches   = $pending->chunk(10);
        $lastIndex = $batches->count() - 1;

        foreach ($batches as $batchIndex => $batch) {
            foreach ($batch as $campaignContact) {
                $this->processContact($campaignContact, $campaign, $whatsAppSend);
            }

            if ($batchIndex < $lastIndex) {
                sleep(2);
            }
        }

        $campaign->status = 'finished';
        $campaign->save();

        Log::info('ProcessCampaignJob: campanha finalizada.', [
            'campaign_id' => $campaign->id,
            'total'       => $pending->count(),
        ]);
    }

    private function processContact(
        CampaignContact $campaignContact,
        Campaign $campaign,
        WhatsAppSendService $whatsAppSend
    ): void {
        try {
            $contact = $campaignContact->contact;

            if (! $contact instanceof Contact) {
                $campaignContact->status = 'failed';
                $campaignContact->error  = 'contato_nao_encontrado';
                $campaignContact->save();

                return;
            }

            $result = match ($campaign->type) {
                'free'             => $this->sendFree($campaign, $contact, $whatsAppSend),
                'template', 'open' => $this->sendTemplate($campaign, $contact, $whatsAppSend),
                default            => ['ok' => false, 'skipped' => true, 'error' => 'tipo_desconhecido'],
            };

            if ($result['skipped'] ?? false) {
                $campaignContact->status = 'skipped';
            } elseif ($result['ok']) {
                $campaignContact->status  = 'sent';
                $campaignContact->sent_at = now();
                $campaignContact->error   = null;
            } else {
                $campaignContact->status = 'failed';
                $campaignContact->error  = $this->serializeError($result['error'] ?? null);
            }

            $campaignContact->save();

            Log::info('ProcessCampaignJob: contato processado.', [
                'campaign_id'         => $campaign->id,
                'campaign_contact_id' => $campaignContact->id,
                'contact_phone'       => $contact->phone,
                'status'              => $campaignContact->status,
            ]);
        } catch (Throwable $e) {
            Log::error('ProcessCampaignJob: erro inesperado ao processar contato.', [
                'campaign_id'         => $campaign->id,
                'campaign_contact_id' => $campaignContact->id,
                'error'               => $e->getMessage(),
            ]);

            try {
                $campaignContact->status = 'failed';
                $campaignContact->error  = $e->getMessage();
                $campaignContact->save();
            } catch (Throwable) {
                // falha silenciosa — não pode interromper o loop
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
