<?php

namespace App\Services\Bot\Handlers;

use Carbon\CarbonImmutable;

class AppointmentFlowMessageBuilder
{
    /**
     * @param  array<int, array{id:int,name:string}>  $services
     * @return array<string, array{id:int,name:string}>
     */
    public function enumerateServices(array $services): array
    {
        $options = [];
        foreach (array_slice($services, 0, 8) as $index => $service) {
            $options[(string) ($index + 1)] = $service;
        }

        return $options;
    }

    /**
     * @param  array<int, array{id:int,name:string}>  $staffProfiles
     * @return array<string, array{id:int,name:string}>
     */
    public function enumerateStaff(array $staffProfiles): array
    {
        $options = [];
        foreach (array_slice($staffProfiles, 0, 7) as $index => $staff) {
            $options[(string) ($index + 1)] = $staff;
        }

        return $options;
    }

    /**
     * @param  array<string, array{id:int,name:string}>  $serviceOptions
     */
    public function appointmentServiceMenuText(array $serviceOptions, bool $invalid = false): string
    {
        $lines = [];
        if ($invalid) {
            $lines[] = 'Opção inválida.';
        }
        $lines[] = 'Agendamento: escolha o serviço:';
        foreach ($serviceOptions as $key => $service) {
            $lines[] = "{$key} - {$service['name']}";
        }
        $lines[] = '9 - Falar com atendente';

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, array{id:int,name:string}>  $staffOptions
     */
    public function appointmentStaffMenuText(array $staffOptions, bool $invalid = false): string
    {
        $lines = [];
        if ($invalid) {
            $lines[] = 'Opção inválida. Escolha um número da lista... ou "menu" para voltar ao menu principal.';
        }
        $lines[] = 'Escolha o atendente:';
        foreach ($staffOptions as $key => $staff) {
            $lines[] = "{$key} - {$staff['name']}";
        }
        $lines[] = '8 - Qualquer atendente';
        $lines[] = '9 - Falar com atendente';

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function appointmentDayPromptText(array $context): string
    {
        $staffName      = trim((string) ($context['staff_name'] ?? ''));
        $staffLine      = $staffName !== '' ? "Atendente: {$staffName}" : 'Atendente: qualquer disponível';
        $hasStaffChoice = (bool) ($context['has_staff_choice'] ?? false);
        $hasLastDay     = trim((string) ($context['last_day_date'] ?? '')) !== '';

        $lines = [
            $staffLine,
            '',
            'Qual dia você prefere?',
            'Digite: segunda, terça, quarta, hoje, amanhã...',
        ];
        if ($hasLastDay) {
            $lines[] = '7 - Próxima semana';
        }
        $lines[] = '8 - Próximos horários';
        $lines[] = '9 - Falar com atendente';
        if ($hasStaffChoice) {
            $lines[] = '0 - Trocar atendente';
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<int, array<string, mixed>>  $slots
     */
    public function appointmentSlotMenuText(
        string $selectedDate,
        array $slots,
        bool $hasMore,
        string $timezone = 'America/Sao_Paulo',
        bool $hasStaffChoice = false
    ): string {
        $date  = CarbonImmutable::parse($selectedDate);
        $lines = ['Horários de ' . $date->translatedFormat('D d/m') . ':'];
        foreach ($slots as $index => $slot) {
            $slotLabel = $this->slotTimeWithOptionalStaffLabel($slot, $timezone);
            $lines[]   = ($index + 1) . " - {$slotLabel}";
        }
        $lines[] = '7 - Próxima semana';
        if ($hasMore) {
            $lines[] = '8 - Ver mais horários';
        }
        $lines[] = '0 - Voltar (trocar dia' . ($hasStaffChoice ? ' ou atendente' : '') . ')';
        $lines[] = '9 - Falar com atendente';

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function appointmentConfirmText(array $context, string $timezone = 'America/Sao_Paulo'): string
    {
        $tz          = $timezone ?: 'America/Sao_Paulo';
        $startsAt    = CarbonImmutable::parse((string) ($context['slot_starts_at'] ?? ''))->setTimezone($tz);
        $serviceName = (string) ($context['service_name'] ?? 'serviço');
        $staffName   = trim((string) ($context['staff_name'] ?? ''));
        $staffText   = $staffName !== '' ? "Atendente: {$staffName}\n" : '';
        $dayName     = $startsAt->translatedFormat('l');

        return "Confirma o agendamento?\nData: {$dayName}, {$startsAt->format('d/m/Y')}\nHora: {$startsAt->format('H:i')}\nServiço: {$serviceName}\n{$staffText}1 - Confirmar\n2 - Escolher outro horário\n9 - Falar com atendente";
    }

    /**
     * @param  array<int, array<string, mixed>>  $slots
     */
    public function appointmentNearestSlotsText(
        array $slots,
        string $timezone = 'America/Sao_Paulo',
        bool $hasStaffChoice = false
    ): string {
        $lines = ['Próximos horários disponíveis:'];
        foreach (array_slice($slots, 0, 7) as $index => $slot) {
            $slotLabel = $this->slotDateTimeWithOptionalStaffLabel($slot, $timezone);
            $lines[]   = ($index + 1) . " - {$slotLabel}";
        }
        $lines[] = '0 - Voltar';
        $lines[] = '9 - Falar com atendente';

        return implode("\n", $lines);
    }

    /** @param array<string, mixed> $slot */
    public function slotTimeWithOptionalStaffLabel(array $slot, string $timezone): string
    {
        $startsAt = $this->slotStartsAt($slot, $timezone);

        return $startsAt->format('H:i') . $this->slotStaffSuffix($slot);
    }

    /** @param array<string, mixed> $slot */
    public function slotDateTimeWithOptionalStaffLabel(array $slot, string $timezone): string
    {
        $startsAt = $this->slotStartsAt($slot, $timezone);

        return $startsAt->translatedFormat('D d/m') . ' ' . $startsAt->format('H:i') . $this->slotStaffSuffix($slot);
    }

    /** @param array<string, mixed> $context */
    public function buildAppointmentDayListMessage(array $context, string $bodyText = ''): array
    {
        $hasStaffChoice = (bool) ($context['has_staff_choice'] ?? false);
        $hasLastDay     = trim((string) ($context['last_day_date'] ?? '')) !== '';
        $staffName      = trim((string) ($context['staff_name'] ?? ''));
        $staffLine      = $staffName !== '' ? "Atendente: {$staffName}" : 'Atendente: qualquer disponível';

        if ($bodyText === '') {
            $bodyText = $staffLine . "\n\nQual dia você prefere?";
        }

        $rows = [
            ['id' => 'hoje',    'title' => 'Hoje',    'description' => ''],
            ['id' => 'amanha',  'title' => 'Amanhã',  'description' => ''],
            ['id' => 'segunda', 'title' => 'Segunda', 'description' => ''],
            ['id' => 'terca',   'title' => 'Terça',   'description' => ''],
            ['id' => 'quarta',  'title' => 'Quarta',  'description' => ''],
            ['id' => 'quinta',  'title' => 'Quinta',  'description' => ''],
            ['id' => 'sexta',   'title' => 'Sexta',   'description' => ''],
            ['id' => 'sabado',  'title' => 'Sábado',  'description' => ''],
        ];
        if ($hasLastDay) {
            $rows[] = ['id' => '7', 'title' => 'Próxima semana',    'description' => ''];
        }
        $rows[] = ['id' => '8', 'title' => 'Próximos horários',     'description' => ''];
        $rows[] = ['id' => '9', 'title' => 'Falar com atendente',   'description' => ''];
        if ($hasStaffChoice) {
            $rows[] = ['id' => '0', 'title' => 'Trocar atendente',  'description' => ''];
        }

        return [
            'type'         => 'interactive_list',
            'body_text'    => $bodyText,
            'header_text'  => '',
            'footer_text'  => '',
            'action_label' => 'Escolher dia',
            'rows'         => $rows,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $slots
     */
    public function buildAppointmentSlotListMessage(
        array $slots,
        bool $hasMore,
        string $timezone,
        bool $hasStaffChoice,
        string $bodyText = ''
    ): array {
        $rows = [];
        foreach ($slots as $index => $slot) {
            $title  = $this->slotTimeWithOptionalStaffLabel($slot, $timezone);
            $rows[] = ['id' => (string) ($index + 1), 'title' => mb_substr($title, 0, 24), 'description' => ''];
        }
        $rows[] = ['id' => '7', 'title' => 'Próxima semana',  'description' => ''];
        if ($hasMore) {
            $rows[] = ['id' => '8', 'title' => 'Ver mais horários', 'description' => ''];
        }
        $rows[] = ['id' => '0', 'title' => 'Voltar',              'description' => ''];
        $rows[] = ['id' => '9', 'title' => 'Falar com atendente', 'description' => ''];

        return [
            'type'         => 'interactive_list',
            'body_text'    => $bodyText,
            'header_text'  => '',
            'footer_text'  => '',
            'action_label' => 'Escolher horário',
            'rows'         => $rows,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $slots
     */
    public function buildAppointmentNearestSlotsListMessage(
        array $slots,
        string $timezone,
        string $bodyText = ''
    ): array {
        $rows = [];
        foreach (array_slice($slots, 0, 7) as $index => $slot) {
            $title  = mb_substr($this->slotDateTimeWithOptionalStaffLabel($slot, $timezone), 0, 24);
            $rows[] = ['id' => (string) ($index + 1), 'title' => $title, 'description' => ''];
        }
        $rows[] = ['id' => '0', 'title' => 'Voltar',              'description' => ''];
        $rows[] = ['id' => '9', 'title' => 'Falar com atendente', 'description' => ''];

        return [
            'type'         => 'interactive_list',
            'body_text'    => $bodyText !== '' ? $bodyText : 'Próximos horários disponíveis:',
            'header_text'  => '',
            'footer_text'  => '',
            'action_label' => 'Escolher horário',
            'rows'         => $rows,
        ];
    }

    /**
     * @param array<string, array{id:int,name:string}> $serviceOptions
     */
    public function buildAppointmentServiceListMessage(array $serviceOptions, string $bodyText = ''): array
    {
        $body = $bodyText !== '' ? $bodyText : 'Agendamento: escolha o serviço:';
        $rows = [];
        foreach ($serviceOptions as $key => $service) {
            $rows[] = ['id' => (string) $key, 'title' => mb_substr((string) $service['name'], 0, 24), 'description' => ''];
        }
        $rows[] = ['id' => '9', 'title' => 'Falar com atendente', 'description' => ''];

        if (count($rows) <= 3) {
            return [
                'type'        => 'interactive_buttons',
                'body_text'   => $body,
                'header_text' => '',
                'footer_text' => '',
                'buttons'     => array_map(fn($r) => ['id' => $r['id'], 'title' => $r['title']], $rows),
            ];
        }

        return [
            'type'         => 'interactive_list',
            'body_text'    => $body,
            'header_text'  => '',
            'footer_text'  => '',
            'action_label' => 'Escolher serviço',
            'rows'         => $rows,
        ];
    }

    /**
     * @param array<string, array{id:int,name:string}> $staffOptions
     */
    public function buildAppointmentStaffListMessage(array $staffOptions, string $bodyText = ''): array
    {
        $body = $bodyText !== '' ? $bodyText : 'Escolha o atendente:';
        $rows = [];
        foreach ($staffOptions as $key => $staff) {
            $rows[] = ['id' => (string) $key, 'title' => mb_substr((string) $staff['name'], 0, 24), 'description' => ''];
        }
        $rows[] = ['id' => '8', 'title' => 'Qualquer atendente',  'description' => ''];
        $rows[] = ['id' => '9', 'title' => 'Falar com atendente', 'description' => ''];

        if (count($rows) <= 3) {
            return [
                'type'        => 'interactive_buttons',
                'body_text'   => $body,
                'header_text' => '',
                'footer_text' => '',
                'buttons'     => array_map(fn($r) => ['id' => $r['id'], 'title' => $r['title']], $rows),
            ];
        }

        return [
            'type'         => 'interactive_list',
            'body_text'    => $body,
            'header_text'  => '',
            'footer_text'  => '',
            'action_label' => 'Escolher atendente',
            'rows'         => $rows,
        ];
    }

    public function buildAppointmentConfirmButtonMessage(string $bodyText): array
    {
        return [
            'type'        => 'interactive_buttons',
            'body_text'   => $bodyText,
            'header_text' => '',
            'footer_text' => '',
            'buttons'     => [
                ['id' => '1', 'title' => 'Confirmar'],
                ['id' => '2', 'title' => 'Outro horário'],
                ['id' => '9', 'title' => 'Falar com atendente'],
            ],
        ];
    }

    /** @param array<string, mixed> $slot */
    private function slotStartsAt(array $slot, string $timezone): CarbonImmutable
    {
        $tz        = $timezone ?: 'America/Sao_Paulo';
        $candidate = (string) ($slot['starts_at_local'] ?? $slot['starts_at'] ?? '');

        return CarbonImmutable::parse($candidate)->setTimezone($tz);
    }

    /** @param array<string, mixed> $slot */
    private function slotStaffSuffix(array $slot): string
    {
        $staffName = trim((string) ($slot['staff_name'] ?? ''));

        return $staffName !== '' ? " ({$staffName})" : '';
    }
}
