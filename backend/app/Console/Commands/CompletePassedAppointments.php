<?php

namespace App\Console\Commands;

use App\Models\Appointment;
use App\Models\AppointmentEvent;
use App\Support\AppointmentStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CompletePassedAppointments extends Command
{
    protected $signature = 'appointments:auto-complete';

    protected $description = 'Marca como completed agendamentos confirmados/pendentes cujo horario ja passou';

    public function handle(): void
    {
        $now = now();
        $totalCompleted = 0;

        Appointment::query()
            ->whereIn('status', [AppointmentStatus::PENDING, AppointmentStatus::CONFIRMED])
            ->where('ends_at', '<', $now)
            ->select(['id', 'company_id', 'status'])
            ->chunkById(200, function ($appointments) use ($now, &$totalCompleted) {
                $ids = $appointments->pluck('id')->all();

                DB::transaction(function () use ($appointments, $ids, $now) {
                    Appointment::query()->whereIn('id', $ids)->update([
                        'status' => AppointmentStatus::COMPLETED,
                        'updated_at' => $now,
                    ]);

                    $events = $appointments->map(fn($a) => [
                        'company_id' => (int) $a->company_id,
                        'appointment_id' => (int) $a->id,
                        'event_type' => 'status_changed',
                        'performed_by_user_id' => null,
                        'payload' => json_encode([
                            'from' => $a->status,
                            'to' => AppointmentStatus::COMPLETED,
                            'channel' => 'auto',
                        ]),
                        'created_at' => $now,
                    ])->all();

                    AppointmentEvent::insert($events);
                });

                $totalCompleted += count($ids);
            });

        if ($totalCompleted === 0) {
            $this->info('Nenhum agendamento para concluir.');
            return;
        }

        $this->info("Marcados como concluidos: {$totalCompleted} agendamentos.");
    }
}
