<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Conversas sem resposta</title>
  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { background-color: #f5f5f5; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; color: #171717; }
    .wrapper { max-width: 560px; margin: 40px auto; padding: 0 16px 40px; }
    .card { background: #ffffff; border: 1px solid #e5e5e5; border-radius: 12px; padding: 40px 36px; }
    .logo { font-size: 18px; font-weight: 700; color: #171717; margin-bottom: 32px; }
    h1 { font-size: 20px; font-weight: 600; color: #171717; margin-bottom: 12px; }
    p { font-size: 14px; color: #525252; line-height: 1.6; margin-bottom: 16px; }
    .badge { display: inline-block; background: #fee2e2; color: #991b1b; font-size: 12px; font-weight: 600; padding: 4px 10px; border-radius: 20px; margin-bottom: 24px; }
    .stat { background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; padding: 20px 24px; margin: 24px 0; text-align: center; }
    .stat-number { font-size: 40px; font-weight: 700; color: #dc2626; line-height: 1; }
    .stat-label { font-size: 13px; color: #6b7280; margin-top: 6px; }
    .btn-wrap { margin: 28px 0; }
    .btn {
      display: inline-block;
      background-color: #2563eb;
      color: #ffffff !important;
      text-decoration: none;
      font-size: 14px;
      font-weight: 600;
      padding: 12px 28px;
      border-radius: 8px;
    }
    .divider { border: none; border-top: 1px solid #e5e5e5; margin: 24px 0; }
    .footer { font-size: 12px; color: #a3a3a3; text-align: center; margin-top: 24px; }
  </style>
</head>
<body>
  <div class="wrapper">
    <div class="card">
      <div class="logo">Blasternet</div>

      <span class="badge">⚠️ Atenção necessária</span>
      <h1>Conversas sem resposta</h1>
      <p>Olá, {{ $admin->name }}. A empresa <strong>{{ $company->name }}</strong> possui conversas abertas sem resposta há mais de {{ $alertHours }}h.</p>

      <div class="stat">
        <div class="stat-number">{{ $unattendedCount }}</div>
        <div class="stat-label">conversa{{ $unattendedCount > 1 ? 's' : '' }} sem resposta</div>
      </div>

      <p>Acesse a caixa de entrada para verificar e responder os clientes que estão aguardando.</p>

      <div class="btn-wrap">
        <a href="{{ $inboxUrl }}" class="btn">Ver conversas</a>
      </div>

      <hr class="divider" />

      <p style="font-size:12px;color:#737373;">
        Você está recebendo este alerta pois é administrador(a) da empresa {{ $company->name }}.
        Este é um email automático, não responda.
      </p>
    </div>

    <p class="footer">© {{ date('Y') }} Blasternet.</p>
  </div>
</body>
</html>
