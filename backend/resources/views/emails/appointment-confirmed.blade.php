<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Agendamento confirmado</title>
  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { background-color: #f5f5f5; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; color: #171717; }
    .wrapper { max-width: 560px; margin: 40px auto; padding: 0 16px 40px; }
    .card { background: #ffffff; border: 1px solid #e5e5e5; border-radius: 12px; padding: 40px 36px; }
    .logo { font-size: 18px; font-weight: 700; color: #171717; margin-bottom: 32px; }
    h1 { font-size: 20px; font-weight: 600; color: #171717; margin-bottom: 12px; }
    p { font-size: 14px; color: #525252; line-height: 1.6; margin-bottom: 16px; }
    .badge { display: inline-block; background: #dcfce7; color: #15803d; font-size: 12px; font-weight: 600; padding: 4px 10px; border-radius: 20px; margin-bottom: 24px; }
    .details { background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; padding: 20px 24px; margin: 24px 0; }
    .detail-row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #f3f4f6; }
    .detail-row:last-child { border-bottom: none; }
    .detail-label { font-size: 13px; color: #6b7280; }
    .detail-value { font-size: 13px; color: #111827; font-weight: 500; text-align: right; }
    .divider { border: none; border-top: 1px solid #e5e5e5; margin: 24px 0; }
    .footer { font-size: 12px; color: #a3a3a3; text-align: center; margin-top: 24px; }
  </style>
</head>
<body>
  <div class="wrapper">
    <div class="card">
      <div class="logo">Blasternet</div>

      <span class="badge">✓ Confirmado</span>
      <h1>Seu agendamento está confirmado!</h1>
      @if($customerName)
      <p>Olá, {{ $customerName }}! Aqui estão os detalhes do seu agendamento.</p>
      @else
      <p>Aqui estão os detalhes do seu agendamento.</p>
      @endif

      <div class="details">
        <div class="detail-row">
          <span class="detail-label">Serviço</span>
          <span class="detail-value">{{ $serviceName }}</span>
        </div>
        @if($staffName)
        <div class="detail-row">
          <span class="detail-label">Atendente</span>
          <span class="detail-value">{{ $staffName }}</span>
        </div>
        @endif
        @if($startsAt)
        <div class="detail-row">
          <span class="detail-label">Data</span>
          <span class="detail-value">{{ $startsAt->translatedFormat('l, d \d\e F \d\e Y') }}</span>
        </div>
        <div class="detail-row">
          <span class="detail-label">Horário</span>
          <span class="detail-value">{{ $startsAt->format('H:i') }}</span>
        </div>
        @endif
        @if($appointment->service_duration_minutes)
        <div class="detail-row">
          <span class="detail-label">Duração</span>
          <span class="detail-value">{{ $appointment->service_duration_minutes }} min</span>
        </div>
        @endif
      </div>

      <p>Em caso de imprevisto, entre em contato o quanto antes para reagendar ou cancelar.</p>

      <hr class="divider" />

      <p style="font-size:12px;color:#737373;">
        Este é um e-mail automático de confirmação. Não responda a esta mensagem.
      </p>
    </div>

    <p class="footer">© {{ date('Y') }} Blasternet.</p>
  </div>
</body>
</html>
