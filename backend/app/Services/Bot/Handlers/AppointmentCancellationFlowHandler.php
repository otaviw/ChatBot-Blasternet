<?php

namespace App\Services\Bot\Handlers;

use App\Models\Appointment;
use App\Models\AppointmentEvent;
use App\Models\AppointmentSetting;
use App\Models\Company;
use App\Models\Conversation;
use App\Services\Bot\BotFlowRegistry;
use App\Support\AppointmentStatus;
use App\Support\Enums\BotFlow;
use Carbon\CarbonImmutable;

class AppointmentCancellationFlowHandler
{
    use BotHandlerHelpers;

    public function __construct(
        private BotFlowRegistry $registry,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function startCancellation(?Company $company, Conversation $conversation): array
    {
        $companyEntity    = $this->resolveCompany($company, $conversation);
        $settings         = $this->appointmentSettings($companyEntity);
        $timezone         = (string) ($settings?->timezone ?: 'America/Sao_Paulo');
        $minNoticeMinutes = (int) ($settings?->cancellation_min_notice_minutes ?? 120);

        $phone         = (string) $conversation->customer_phone;
        $phoneVariants = [$phone];
        if (strlen($phone) === 13 && str_starts_with($phone, '55')) {
            $phoneVariants[] = substr($phone, 0, 4) . substr($phone, 5);
        } elseif (strlen($phone) === 12 && str_starts_with($phone, '55')) {
            $phoneVariants[] = substr($phone, 0, 4) . '9' . substr($phone, 4);
        }

        $appointment = Appointment::query()
            ->where('company_id', (int) ($companyEntity?->id ?? 0))
            ->whereIn('customer_phone', $phoneVariants)
            ->whereIn('status', [AppointmentStatus::PENDING, AppointmentStatus::CONFIRMED])
            ->where('starts_at', '>', now())
            ->orderBy('starts_at')
            ->first();

        $menuState     = $this->buildInitialMenuResponse($this->registry->definitionForCompany($companyEntity));
        $mainMenuState = $menuState['new_state'] ?? ['flow' => BotFlow::MAIN->value, 'step' => 'menu', 'context' => []];

        if (! $appointment) {
            return $this->botStateResult(
                'Não encontrei nenhum agendamento ativo para o seu número.',
                $mainMenuState
            );
        }

        $startsAt = $appointment->starts_at->setTimezone($timezone);
        $cutoff   = CarbonImmutable::now($timezone)->addMinutes($minNoticeMinutes);

        if ($startsAt->lte($cutoff)) {
            $limitHours = (int) round($minNoticeMinutes / 60);

            return $this->botStateResult(
                "Seu agendamento é dia {$startsAt->format('d/m/Y')} às {$startsAt->format('H:i')}.\n" .
                "Cancelamentos só são permitidos com pelo menos {$limitHours}h de antecedência.\n" .
                "Para cancelar entre em contato com um atendente.\n\n9 - Falar com atendente",
                $mainMenuState
            );
        }

        $staffName = $appointment->staffProfile?->display_name
            ?: $appointment->staffProfile?->user?->name
            ?: '';
        $staffLine = $staffName !== '' ? "\nAtendente: {$staffName}" : '';

        return $this->botStateResult(
            "Seu agendamento:\nData: {$startsAt->translatedFormat('l')}, {$startsAt->format('d/m/Y')}\nHorário: {$startsAt->format('H:i')}{$staffLine}\n\nDeseja cancelar?\n1 - Sim, cancelar\n2 - Não, manter",
            [
                'flow'    => BotFlow::CANCEL_APPOINTMENT->value,
                'step'    => 'confirm',
                'context' => ['cancel_appointment' => ['appointment_id' => (int) $appointment->id]],
            ]
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function handleCancellation(
        ?Company $company,
        Conversation $conversation,
        string $step,
        string $normalizedText
    ): array {
        unset($step);
        $companyEntity = $this->resolveCompany($company, $conversation);
        $menuState     = $this->buildInitialMenuResponse($this->registry->definitionForCompany($companyEntity));
        $mainMenuState = $menuState['new_state'] ?? ['flow' => BotFlow::MAIN->value, 'step' => 'menu', 'context' => []];

        $rawContext    = is_array($conversation->bot_context ?? null) ? $conversation->bot_context : [];
        $cancelContext = is_array($rawContext['cancel_appointment'] ?? null) ? $rawContext['cancel_appointment'] : [];
        $appointmentId = (int) ($cancelContext['appointment_id'] ?? 0);

        if ($normalizedText === '2' || $appointmentId === 0) {
            return $this->botStateResult('Ok, seu agendamento foi mantido. Até logo!', $mainMenuState);
        }

        if ($normalizedText !== '1') {
            $appointment = $appointmentId > 0 ? Appointment::query()->find($appointmentId) : null;
            if ($appointment) {
                $settings  = $this->appointmentSettings($companyEntity);
                $timezone  = (string) ($settings?->timezone ?: 'America/Sao_Paulo');
                $startsAt  = $appointment->starts_at->setTimezone($timezone);
                $staffName = $appointment->staffProfile?->display_name ?: $appointment->staffProfile?->user?->name ?: '';
                $staffLine = $staffName !== '' ? "\nAtendente: {$staffName}" : '';

                return $this->botStateResult(
                    "Opção inválida. Responda com 1 ou 2.\n\nSeu agendamento:\nData: {$startsAt->format('d/m/Y')}\nHorário: {$startsAt->format('H:i')}{$staffLine}\n\n1 - Sim, cancelar\n2 - Não, manter",
                    [
                        'flow'    => BotFlow::CANCEL_APPOINTMENT->value,
                        'step'    => 'confirm',
                        'context' => ['cancel_appointment' => $cancelContext],
                    ]
                );
            }

            return $this->botStateResult('Ok, até logo!', $mainMenuState);
        }

        $appointment = Appointment::query()
            ->where('company_id', (int) ($companyEntity?->id ?? 0))
            ->find($appointmentId);

        if (! $appointment || ! in_array((string) $appointment->status, [AppointmentStatus::PENDING, AppointmentStatus::CONFIRMED], true)) {
            return $this->botStateResult(
                'Não foi possível cancelar: agendamento não encontrado ou já cancelado.',
                $mainMenuState
            );
        }

        $oldStatus                     = (string) $appointment->status;
        $appointment->status           = AppointmentStatus::CANCELLED;
        $appointment->cancelled_at     = now();
        $appointment->cancelled_reason = 'Cancelado pelo cliente via WhatsApp';
        $appointment->save();

        AppointmentEvent::create([
            'company_id'           => (int) $appointment->company_id,
            'appointment_id'       => (int) $appointment->id,
            'event_type'           => 'status_changed',
            'performed_by_user_id' => null,
            'payload'              => [
                'from'    => $oldStatus,
                'to'      => AppointmentStatus::CANCELLED,
                'reason'  => 'Cancelado pelo cliente via WhatsApp',
                'channel' => 'whatsapp_bot',
            ],
        ]);

        return $this->botStateResult('✅ Agendamento cancelado com sucesso!', $mainMenuState);
    }

    private function appointmentSettings(?Company $company): ?AppointmentSetting
    {
        if (! $company?->id) {
            return null;
        }

        return AppointmentSetting::query()->firstOrCreate(
            ['company_id' => (int) $company->id],
            [
                'timezone'                        => 'America/Sao_Paulo',
                'slot_interval_minutes'           => 15,
                'booking_min_notice_minutes'      => 120,
                'booking_max_advance_days'        => 30,
                'cancellation_min_notice_minutes' => 120,
                'reschedule_min_notice_minutes'   => 120,
                'allow_customer_choose_staff'     => true,
            ]
        );
    }
}
