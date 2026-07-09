<?php

namespace App\Services;

use App\Mail\EmailVerificationMail;
use App\Models\EmailVerificationCode;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

class EmailVerificationService
{
    private const CODE_TTL_MINUTES     = 15;
    private const MAX_ATTEMPTS         = 5;
    private const RESEND_COOLDOWN_SECS = 60;

    /**
     * Sinh mã 6 số mới, ghi đè mã cũ (nếu có) và gửi email. Best-effort — lỗi mail
     * không nên chặn luồng đăng ký, caller tự quyết định có bọc try/catch hay không.
     */
    public function sendCode(User $user): void
    {
        $code = (string) random_int(100000, 999999);

        EmailVerificationCode::updateOrCreate(
            ['user_id' => $user->id],
            [
                'code_hash'  => Hash::make($code),
                'attempts'   => 0,
                'expires_at' => now()->addMinutes(self::CODE_TTL_MINUTES),
            ]
        );

        Mail::to($user->email)->queue(new EmailVerificationMail($user, $code));
    }

    /**
     * @return array{ok:bool, reason:?string} reason ∈ null|'invalid'|'expired'|'too_many_attempts'|'no_code'
     */
    public function verify(User $user, string $code): array
    {
        $record = $user->emailVerificationCode;

        if (!$record) {
            return ['ok' => false, 'reason' => 'no_code'];
        }

        if ($record->attempts >= self::MAX_ATTEMPTS) {
            return ['ok' => false, 'reason' => 'too_many_attempts'];
        }

        if ($record->expires_at->isPast()) {
            return ['ok' => false, 'reason' => 'expired'];
        }

        if (!Hash::check($code, $record->code_hash)) {
            $record->increment('attempts');
            return ['ok' => false, 'reason' => 'invalid'];
        }

        $user->update(['email_verified_at' => now()]);
        $record->delete();

        return ['ok' => true, 'reason' => null];
    }

    public function canResend(User $user): bool
    {
        return $this->secondsUntilResendAllowed($user) <= 0;
    }

    public function secondsUntilResendAllowed(User $user): int
    {
        $record = $user->emailVerificationCode;
        if (!$record) {
            return 0;
        }

        // Dùng updated_at vì sendCode() cập nhật (không tạo mới) bản ghi khi gửi lại mã.
        // Trừ timestamp trực tiếp (không dùng diffInSeconds) để tránh phụ thuộc quy ước dấu của Carbon.
        $elapsed = now()->getTimestamp() - $record->updated_at->getTimestamp();
        return max(0, self::RESEND_COOLDOWN_SECS - $elapsed);
    }
}
