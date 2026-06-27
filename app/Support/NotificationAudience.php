<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Dựng truy vấn danh sách user mục tiêu cho 1 chiến dịch thông báo
 * từ bộ lọc phân khúc. Luôn loại tài khoản bị khoá (suspended).
 *
 * Filters hỗ trợ:
 *   audience       : 'all' | 'segment'
 *   role           : 'user' | 'admin'
 *   provider       : 'email' | 'google' | 'facebook'
 *   gender         : 'male' | 'female' | 'other'
 *   activity       : 'active_7d' | 'inactive_7d' | 'inactive_30d'
 *   has_streak     : bool   (đang có chuỗi > 0)
 *   only_subscribed: bool   (chỉ user có thiết bị đăng ký push)
 */
class NotificationAudience
{
    public static function query(array $filters): Builder
    {
        // Luôn chỉ gửi cho tài khoản đang hoạt động
        $q = User::query()->where('status', 'active');

        if (($filters['audience'] ?? 'all') === 'all') {
            return $q;
        }

        if (!empty($filters['role'])) {
            $q->where('role', $filters['role']);
        }
        if (!empty($filters['provider'])) {
            $q->where('provider', $filters['provider']);
        }
        if (!empty($filters['gender'])) {
            $q->where('gender', $filters['gender']);
        }

        switch ($filters['activity'] ?? null) {
            case 'active_7d':
                $q->where('last_seen_at', '>=', now()->subDays(7));
                break;
            case 'inactive_7d':
                $q->where(fn ($w) => $w->whereNull('last_seen_at')->orWhere('last_seen_at', '<', now()->subDays(7)));
                break;
            case 'inactive_30d':
                $q->where(fn ($w) => $w->whereNull('last_seen_at')->orWhere('last_seen_at', '<', now()->subDays(30)));
                break;
        }

        if (!empty($filters['has_streak'])) {
            $q->whereHas('streak', fn ($s) => $s->where('current_streak', '>', 0));
        }

        if (!empty($filters['only_subscribed'])) {
            $q->whereHas('notificationSubscriptions');
        }

        return $q;
    }
}
