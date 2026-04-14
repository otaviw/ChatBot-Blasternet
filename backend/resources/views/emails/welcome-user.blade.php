<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Bem-vindo(a) ao Blasternet</title>
  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { background-color: #f5f5f5; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; color: #171717; }
    .wrapper { max-width: 560px; margin: 40px auto; padding: 0 16px 40px; }
    .card { background: #ffffff; border: 1px solid #e5e5e5; border-radius: 12px; padding: 40px 36px; }
    .logo { font-size: 18px; font-weight: 700; color: #171717; margin-bottom: 32px; }
    h1 { font-size: 20px; font-weight: 600; color: #171717; margin-bottom: 12px; }
    p { font-size: 14px; color: #525252; line-height: 1.6; margin-bottom: 16px; }
    .credentials { background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; padding: 16px 20px; margin: 24px 0; }
    .credentials p { margin-bottom: 6px; }
    .credentials .label { font-size: 12px; color: #737373; text-transform: uppercase; letter-spacing: 0.05em; font-weight: 600; margin-bottom: 2px; }
    .credentials .value { font-size: 14px; color: #171717; font-family: 'Courier New', Courier, monospace; }
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
    .warning { font-size: 12px; color: #b45309; background: #fffbeb; border: 1px solid #fde68a; border-radius: 6px; padding: 10px 14px; margin-top: 16px; }
  </style>
</head>
<body>
  <div class="wrapper">
    <div class="card">
      <div class="logo">Blasternet</div>

      <h1>Bem-vindo(a), {{ $user->name }}!</h1>
      <p>Sua conta foi criada com sucesso. Use as credenciais abaixo para acessar a plataforma.</p>

      <div class="credentials">
        <p class="label">E-mail</p>
        <p class="value">{{ $user->email }}</p>
      </div>

      <div class="credentials">
        <p class="label">Senha temporária</p>
        <p class="value">{{ $plainPassword }}</p>
        <p class="warning">Recomendamos alterar sua senha no primeiro acesso.</p>
      </div>

      <div class="btn-wrap">
        <a href="{{ $loginUrl }}" class="btn">Acessar plataforma</a>
      </div>

      <hr class="divider" />

      <p style="font-size:12px;color:#737373;">
        Se você não esperava receber este e-mail, entre em contato com o suporte.
      </p>
    </div>

    <p class="footer">© {{ date('Y') }} Blasternet. Este é um email automático, não responda.</p>
  </div>
</body>
</html>
