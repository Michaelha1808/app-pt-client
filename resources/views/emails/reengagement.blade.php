<!DOCTYPE html>
<html lang="vi">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>CaloEye — Chúng tôi nhớ bạn!</title>
  <style>
    body { margin:0; padding:0; background:#F2F8F5; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; }
    .wrap { max-width:520px; margin:32px auto; background:#fff; border-radius:20px; overflow:hidden; box-shadow:0 2px 16px rgba(0,0,0,.08); }
    .header { background:linear-gradient(135deg,#18A874,#34C759); padding:36px 32px 28px; text-align:center; }
    .header h1 { margin:0; color:#fff; font-size:24px; font-weight:700; }
    .header p  { margin:6px 0 0; color:rgba(255,255,255,.8); font-size:14px; }
    .body { padding:28px 32px; }
    .body p  { color:#333; font-size:15px; line-height:1.6; margin:0 0 16px; }
    .stats { display:flex; gap:12px; margin:20px 0; }
    .stat  { flex:1; background:#F2F8F5; border-radius:12px; padding:14px 12px; text-align:center; }
    .stat .val { font-size:22px; font-weight:700; color:#18A874; }
    .stat .lbl { font-size:12px; color:#8E8E93; margin-top:2px; }
    .cta { display:block; background:#18A874; color:#fff; text-decoration:none; text-align:center;
           padding:14px 24px; border-radius:12px; font-size:16px; font-weight:600; margin:24px 0 8px; }
    .footer { padding:16px 32px 24px; text-align:center; }
    .footer p { font-size:12px; color:#AEAEB2; margin:0; }
    .footer a { color:#AEAEB2; }
  </style>
</head>
<body>
  <div class="wrap">
    <div class="header">
      <h1>🥗 CaloEye</h1>
      <p>AI Nutrition Tracker</p>
    </div>

    <div class="body">
      <p>Chào <strong>{{ $userName }}</strong>,</p>
      <p>Đã một thời gian rồi bạn chưa ghé thăm CaloEye. Hành trình sức khoẻ của bạn vẫn đang chờ! 💪</p>

      <div class="stats">
        <div class="stat">
          <div class="val">{{ number_format($dailyGoal) }}</div>
          <div class="lbl">Calo mục tiêu</div>
        </div>
        <div class="stat">
          <div class="val">{{ $bestStreak }}</div>
          <div class="lbl">🔥 Streak cao nhất</div>
        </div>
      </div>

      <p>Chỉ cần log một bữa ăn là bạn đã bắt đầu lại rồi. Đừng để chuỗi thói quen lành mạnh bị gián đoạn!</p>

      <a href="{{ $appUrl }}" class="cta">Mở CaloEye ngay →</a>
    </div>

    <div class="footer">
      <p>
        Bạn nhận email này vì đã đăng ký CaloEye.<br/>
        <a href="{{ $appUrl }}/settings/notifications">Tắt email nhắc nhở</a>
      </p>
    </div>
  </div>
</body>
</html>
