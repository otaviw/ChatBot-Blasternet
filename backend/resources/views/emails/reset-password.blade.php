<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Redefinição de senha</title>
  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { background-color: #f5f5f5; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; color: #171717; }
    .wrapper { max-width: 560px; margin: 40px auto; padding: 0 16px 40px; }
    .card { background: #ffffff; border: 1px solid #e5e5e5; border-radius: 12px; padding: 40px 36px; }
    .logo { font-size: 18px; font-weight: 700; color: #171717; margin-bottom: 32px; }
    h1 { font-size: 20px; font-weight: 600; color: #171717; margin-bottom: 12px; }
    p { font-size: 14px; color: #525252; line-height: 1.6; margin-bottom: 16px; }
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
    .link-fallback { font-size: 12px; color: #737373; word-break: break-all; }
    .link-fallback a { color: #2563eb; }
    .divider { border: none; border-top: 1px solid #e5e5e5; margin: 24px 0; }
    .footer { font-size: 12px; color: #a3a3a3; text-align: center; margin-top: 24px; }
  </style>
</head>
<body>
  <div class="wrapper">
    <div class="card">
      <div class="logo">Blasternet</div>

      <h1>Redefinição de senha</h1>
      <p>Olá, {{ $user->name }}.</p>
      <p>
        Recebemos uma solicitação para redefinir a senha da sua conta.
        Clique no botão abaixo para criar uma nova senha. O link é válido por <strong>60 minutos</strong>.
      </p>

      <div class="btn-wrap">
        <a href="{{ $resetUrl }}" class="btn">Redefinir minha senha</a>
      </div>

      <p>Se você não solicitou a redefinição, ignore este email — sua senha permanece a mesma.</p>

      <hr class="divider" />

      <p class="link-fallback">
        Se o botão não funcionar, copie e cole este link no navegador:<br />
        <a href="{{ $resetUrl }}">{{ $resetUrl }}</a>
      </p>
    </div>

    <p class="footer">© {{ date('Y') }} Blasternet. Este é um email automático, não responda.</p>
  </div>
</body>
</html>
