<!DOCTYPE html>
<html lang="vi">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>CaloEye — Mã xác thực email</title>
  <style>
    body { margin:0; padding:0; background:#F2F8F5; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; }
    .wrap { max-width:520px; margin:32px auto; background:#fff; border-radius:20px; overflow:hidden; box-shadow:0 2px 16px rgba(0,0,0,.08); }
    .header { background:linear-gradient(135deg,#18A874,#34C759); padding:36px 32px 28px; text-align:center; }
    .header h1 { margin:0; color:#fff; font-size:24px; font-weight:700; }
    .header p  { margin:6px 0 0; color:rgba(255,255,255,.8); font-size:14px; }
    .body { padding:28px 32px; }
    .body p  { color:#333; font-size:15px; line-height:1.6; margin:0 0 16px; }
    .code-box { background:#F2F8F5; border-radius:14px; padding:22px; text-align:center; margin:20px 0; }
    .code { font-size:36px; font-weight:700; letter-spacing:8px; color:#18A874; }
    .hint { font-size:13px; color:#8E8E93; margin-top:8px; }
    .footer { padding:16px 32px 24px; text-align:center; }
    .footer p { font-size:12px; color:#AEAEB2; margin:0; }
  </style>
</head>
<body>
  <div class="wrap">
    <div class="header">
      <h1>🥗 CaloEye</h1>
      <p>Xác thực email</p>
    </div>

    <div class="body">
      <p>Chào <strong>{{ $userName }}</strong>,</p>
      <p>Nhập mã bên dưới trong ứng dụng để xác thực email của bạn:</p>

      <div class="code-box">
        <div class="code">{{ $code }}</div>
        <div class="hint">Mã có hiệu lực trong 15 phút</div>
      </div>

      <p>Nếu bạn không thực hiện yêu cầu này, hãy bỏ qua email — tài khoản của bạn vẫn an toàn.</p>
    </div>

    <div class="footer">
      <p>Bạn nhận email này vì đã đăng ký tài khoản CaloEye.</p>
    </div>
  </div>
</body>
</html>
