<?php

namespace App\Services;

use App\Models\StreakMilestone;
use App\Models\User;
use App\Models\UserStreak;

class StreakService
{
    private const MILESTONES = [3, 7, 14, 30, 60, 100];

    private const MILESTONE_META = [
        3   => ['🌱', 'Khởi đầu tốt!',       'Bạn đã log {days} ngày liên tiếp. Tiếp tục nhé!'],
        7   => ['🥑', '1 tuần liên tiếp!',    'Chuỗi 7 ngày — xuất sắc! Bạn vừa nhận được 1 Freeze Token ❄️'],
        14  => ['⚡', '2 tuần mạnh mẽ!',      'Bạn đang xây dựng thói quen thật sự rồi đó!'],
        30  => ['💪', '1 tháng kiên trì!',     '30 ngày liên tiếp! Đây là thành tích đáng tự hào.'],
        60  => ['🏆', 'Siêu kiên nhẫn!',      '60 ngày! Sức khoẻ của bạn đang thay đổi từng ngày.'],
        100 => ['👑', 'Huyền thoại CaloEye!', '100 ngày liên tiếp. Bạn là tấm gương của cộng đồng!'],
    ];

    /**
     * Gọi mỗi khi user log bữa ăn thành công.
     * Alias giữ tương thích — buổi tập (manual / Strava) dùng recordActivity().
     */
    public function recordMealActivity(User $user): array
    {
        return $this->recordActivity($user);
    }

    /**
     * Ghi nhận MỘT hoạt động bất kỳ trong ngày (bữa ăn hoặc buổi tập) để giữ streak.
     * Idempotent theo ngày: gọi nhiều lần cùng ngày chỉ tính 1 (guard last_activity_date).
     * Trả về thông tin để frontend hiển thị (streak tăng / milestone mới).
     */
    public function recordActivity(User $user): array
    {
        $streak = UserStreak::firstOrCreate(
            ['user_id' => $user->id],
            ['current_streak' => 0, 'best_streak' => 0, 'freeze_tokens' => 0]
        );

        $today      = now()->toDateString();
        $yesterday  = now()->subDay()->toDateString();
        $twoDaysAgo = now()->subDays(2)->toDateString();

        if ($streak->last_activity_date?->toDateString() === $today) {
            return ['current_streak' => $streak->current_streak, 'is_new_day' => false, 'new_milestone' => null];
        }

        $lastDate  = $streak->last_activity_date?->toDateString();
        $freezeDate = $streak->freeze_last_used_date?->toDateString();

        if ($lastDate === $yesterday) {
            $streak->current_streak++;
        } elseif ($lastDate === $twoDaysAgo && $freezeDate === $yesterday) {
            // Freeze bảo vệ hôm qua → vẫn liên tiếp
            $streak->current_streak++;
        } else {
            $streak->current_streak = 1;
        }

        $streak->last_activity_date = $today;
        $streak->best_streak        = max($streak->best_streak, $streak->current_streak);

        $this->awardFreezeToken($streak);
        $streak->save();

        $newMilestone = $this->checkAndRecordMilestone($user, $streak);

        return [
            'current_streak' => $streak->current_streak,
            'is_new_day'     => true,
            'new_milestone'  => $newMilestone,
        ];
    }

    /**
     * User dùng freeze token để bảo vệ chuỗi khi bỏ lỡ đúng 1 ngày.
     */
    public function useFreeze(User $user): UserStreak
    {
        $streak     = $user->streak ?? UserStreak::firstOrCreate(['user_id' => $user->id]);
        $yesterday  = now()->subDay()->toDateString();
        $twoDaysAgo = now()->subDays(2)->toDateString();

        throw_if($streak->freeze_tokens <= 0,
            \InvalidArgumentException::class, 'Không còn freeze token');
        throw_if($streak->current_streak <= 0,
            \InvalidArgumentException::class, 'Không có chuỗi để bảo vệ');
        throw_if($streak->last_activity_date?->toDateString() !== $twoDaysAgo,
            \InvalidArgumentException::class, 'Chỉ dùng được khi bỏ lỡ đúng 1 ngày');
        throw_if($streak->freeze_last_used_date?->toDateString() === $yesterday,
            \InvalidArgumentException::class, 'Đã dùng freeze token cho ngày này rồi');

        $streak->freeze_tokens--;
        $streak->freeze_last_used_date = $yesterday;
        $streak->save();

        return $streak->fresh();
    }

    /**
     * Build response payload cho GET /streak.
     */
    public function getStreakData(User $user): array
    {
        $streak = UserStreak::firstOrCreate(
            ['user_id' => $user->id],
            ['current_streak' => 0, 'best_streak' => 0, 'freeze_tokens' => 0]
        );

        $today      = now()->toDateString();
        $yesterday  = now()->subDay()->toDateString();
        $twoDaysAgo = now()->subDays(2)->toDateString();

        $lastDate   = $streak->last_activity_date?->toDateString();
        $freezeDate = $streak->freeze_last_used_date?->toDateString();

        $isLoggedToday = $lastDate === $today;
        $streakAtRisk  = $streak->current_streak > 0 && !$isLoggedToday;

        $canUseFreeze = $streak->freeze_tokens > 0
            && $streak->current_streak > 0
            && $lastDate === $twoDaysAgo
            && $freezeDate !== $yesterday;

        $achieved = $streak->user->streakMilestones->pluck('days')->toArray();

        $nextMilestone = collect(self::MILESTONES)
            ->first(fn ($d) => $streak->current_streak < $d);

        return [
            'current_streak'        => $streak->current_streak,
            'best_streak'           => $streak->best_streak,
            'last_activity_date'    => $lastDate,
            'freeze_tokens'         => $streak->freeze_tokens,
            'freeze_last_used_date' => $freezeDate,
            'can_use_freeze'        => $canUseFreeze,
            'is_logged_today'       => $isLoggedToday,
            'streak_at_risk'        => $streakAtRisk,
            'achieved_milestones'   => $achieved,
            'next_milestone'        => $nextMilestone,
        ];
    }

    // -----------------------------------------------------------------

    private function awardFreezeToken(UserStreak $streak): void
    {
        if ($streak->current_streak > 0
            && $streak->current_streak % 7 === 0
            && $streak->freeze_tokens < 3
        ) {
            $streak->freeze_tokens++;
        }
    }

    private function checkAndRecordMilestone(User $user, UserStreak $streak): ?int
    {
        foreach (array_reverse(self::MILESTONES) as $days) {
            if ($streak->current_streak >= $days) {
                $exists = StreakMilestone::where('user_id', $user->id)
                    ->where('days', $days)
                    ->exists();

                if (!$exists) {
                    $milestone = StreakMilestone::create([
                        'user_id'     => $user->id,
                        'days'        => $days,
                        'achieved_at' => now(),
                    ]);
                    $this->sendMilestonePush($user, $milestone);
                    return $days;
                }
                break;
            }
        }
        return null;
    }

    private function sendMilestonePush(User $user, StreakMilestone $milestone): void
    {
        $meta  = self::MILESTONE_META[$milestone->days] ?? ['🥑', "Cột mốc {$milestone->days} ngày!", 'Tuyệt vời!'];
        $title = "{$meta[0]} {$meta[1]}";
        $body  = str_replace('{days}', $milestone->days, $meta[2]);

        try {
            app(FcmService::class)->sendMulticast(
                $user->notificationSubscriptions->pluck('fcm_token')->toArray(),
                $title,
                $body,
                ['type' => 'milestone', 'days' => (string) $milestone->days, 'url' => '/home']
            );
            $milestone->update(['push_sent_at' => now()]);
        } catch (\Throwable) {
            // Push thất bại không nên chặn log bữa ăn
        }
    }
}
