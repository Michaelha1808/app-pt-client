<?php

namespace App\Services;

use App\Models\UsageEvent;
use App\Models\User;
use App\Models\WeightLog;
use App\Support\UsageTracker;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class WeightService
{
    private const VALID_RANGES = [30, 90, 180];

    /**
     * Ghi/đè bản ghi cân nặng của 1 ngày rồi đồng bộ users.weight_kg theo bản ghi mới nhất.
     * $note = null nghĩa là "không đổi" (không xoá note cũ khi gọi từ side-effect của profile).
     */
    public function logWeight(User $user, float $weightKg, ?Carbon $date = null, ?string $note = null): WeightLog
    {
        $date ??= today();

        $attrs = ['weight_kg' => $weightKg];
        if ($note !== null) {
            $attrs['note'] = $note;
        }

        // Dùng Carbon (không phải chuỗi 'Y-m-d') cho khoá tìm kiếm: cột được cast 'date' nên
        // chuỗi ngày trần không khớp bản ghi sẵn có trên mọi driver → updateOrCreate sẽ INSERT
        // và đụng unique (user_id, logged_date) thay vì ghi đè.
        $entry = $user->weightLogs()->updateOrCreate(
            ['logged_date' => $date->copy()->startOfDay()],
            $attrs
        );

        $this->syncCurrentWeight($user);

        return $entry;
    }

    /** users.weight_kg luôn phản ánh bản ghi weight_log mới nhất (không thay đổi nếu không còn bản ghi nào). */
    public function syncCurrentWeight(User $user): void
    {
        $latest = $user->weightLogs()->first();

        if ($latest && (float) $user->weight_kg !== (float) $latest->weight_kg) {
            $user->weight_kg = $latest->weight_kg;
            $user->saveQuietly();
        }
    }

    public function deleteEntry(WeightLog $entry): void
    {
        $user = $entry->user;
        $entry->delete();
        $this->syncCurrentWeight($user);
    }

    public function history(User $user, int $range = 30): array
    {
        $range = in_array($range, self::VALID_RANGES, true) ? $range : 30;
        $since = today()->subDays($range - 1);

        $entries = $user->weightLogs()
            ->where('logged_date', '>=', $since)
            ->reorder('logged_date', 'asc')
            ->get(['id', 'weight_kg', 'logged_date', 'note']);

        return [
            'range'           => $range,
            'entries'         => $entries->map(fn ($e) => [
                'id'          => $e->id,
                'weight_kg'   => (float) $e->weight_kg,
                'logged_date' => $e->logged_date->toDateString(),
                'note'        => $e->note,
            ])->values(),
            'trend'           => $this->computeTrend($entries),
            'bmi'             => $this->computeBmi($user),
            'goal_suggestion' => $this->suggestGoal($user),
        ];
    }

    private function computeTrend(Collection $entries): ?array
    {
        if ($entries->isEmpty()) {
            return null;
        }

        $start   = (float) $entries->first()->weight_kg;
        $current = (float) $entries->last()->weight_kg;
        $delta   = round($current - $start, 1);

        $last7 = $entries->filter(fn ($e) => $e->logged_date->gte(today()->subDays(6)));
        $avg7d = $last7->isNotEmpty() ? round($last7->avg('weight_kg'), 1) : $current;

        $daySpan    = $entries->first()->logged_date->diffInDays($entries->last()->logged_date);
        $weeklyRate = $daySpan > 0 ? round($delta / $daySpan * 7, 2) : 0.0;

        return [
            'start_weight_kg'   => $start,
            'current_weight_kg' => $current,
            'delta_kg'          => $delta,
            'avg_7d_kg'         => $avg7d,
            'weekly_rate_kg'    => $weeklyRate,
        ];
    }

    private function computeBmi(User $user): ?array
    {
        if (!$user->height_cm || !$user->weight_kg) {
            return null;
        }

        $heightM = (float) $user->height_cm / 100;
        $bmi     = round((float) $user->weight_kg / ($heightM * $heightM), 1);

        $label = match (true) {
            $bmi < 18.5 => 'Thiếu cân',
            $bmi < 25   => 'Bình thường',
            $bmi < 30   => 'Thừa cân',
            default     => 'Béo phì',
        };

        return ['value' => $bmi, 'label' => $label];
    }

    /**
     * Đề xuất mục tiêu calo mới khi cân nặng lệch ≥2kg so với mốc lần áp dụng gần nhất
     * (hoặc bản ghi đầu tiên nếu chưa từng áp dụng). Không bao giờ tự đổi calorie_goal.
     */
    public function suggestGoal(User $user): ?array
    {
        if (!$user->birth_year || !$user->height_cm || !$user->gender || !$user->weight_kg) {
            return null;
        }

        $current = (float) $user->weight_kg;
        $anchor  = $this->goalAnchorWeight($user);

        if ($anchor === null) {
            return null;
        }

        $deltaKg = round($current - $anchor, 1);
        if (abs($deltaKg) < 2.0) {
            return null;
        }

        $age    = (int) date('Y') - (int) $user->birth_year;
        $height = (float) $user->height_cm;
        // BMR Mifflin-St Jeor + TDEE theo PAL user chọn (thay vì cứng 1.375).
        $bmr    = \App\Support\NutritionStandard::bmr($current, $height, $age, $user->gender ?? 'other');
        $tdee   = \App\Support\NutritionStandard::tdee($bmr, $user->activity_level);

        $currentGoal = (int) $user->calorie_goal;

        $suggested = match (true) {
            $currentGoal < $tdee - 50 => $tdee - 300,   // đang theo hướng giảm cân
            $currentGoal > $tdee + 50 => $tdee + 300,   // đang theo hướng tăng cân
            default                   => $tdee,          // duy trì
        };

        $suggested = (int) (round($suggested / 50) * 50);
        $suggested = max(1200, min(4000, $suggested));

        if (abs($suggested - $currentGoal) < 100) {
            return null;
        }

        $direction = $deltaKg < 0 ? 'giảm' : 'tăng';
        $reason    = sprintf('Bạn đã %s %.1fkg so với lúc đặt mục tiêu', $direction, abs($deltaKg));

        return [
            'current_goal'   => $currentGoal,
            'suggested_goal' => $suggested,
            'reason'         => $reason,
        ];
    }

    public function applyGoal(User $user, int $calorieGoal): void
    {
        $user->update(['calorie_goal' => $calorieGoal]);
        UsageTracker::record('weight_goal_apply', $user->id);
    }

    /** Cân nặng tại thời điểm mục tiêu hiện tại được đặt — dùng làm mốc so sánh cho suggestGoal(). */
    private function goalAnchorWeight(User $user): ?float
    {
        $lastApply = UsageEvent::where('user_id', $user->id)
            ->where('type', 'weight_goal_apply')
            ->orderByDesc('created_at')
            ->first();

        if ($lastApply) {
            $anchor = $user->weightLogs()
                ->where('logged_date', '<=', $lastApply->created_at->toDateString())
                ->first();

            if ($anchor) {
                return (float) $anchor->weight_kg;
            }
        }

        $first = $user->weightLogs()->reorder('logged_date', 'asc')->first();

        return $first ? (float) $first->weight_kg : null;
    }
}
