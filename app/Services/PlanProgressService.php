<?php

namespace App\Services;

use App\Models\HealthActivity;
use App\Models\MealLog;
use App\Models\MealPlan;
use Illuminate\Support\Carbon;

/**
 * Phân giải nhiệm vụ ăn/tập theo NGÀY từ các kế hoạch đang active (daily → weekly → monthly),
 * và tính tiến độ hoàn thành. Dùng chung cho DailyTaskController (nhiệm vụ hôm nay trên Home)
 * và PlanProgressController (trang Tiến độ) — tránh 2 nơi tự suy luận "đã xong" khác nhau.
 *
 * Trạng thái "done" KHÔNG lưu trong DB: suy ra từ MealLog/HealthActivity thực tế của ngày đó,
 * nên user ghi bữa ăn bằng bất kỳ cách nào (nhận diện ảnh, log nhanh, "thực hiện") đều được tính.
 */
class PlanProgressService
{
    /** Nhãn thứ khớp schema AI dùng trong weeklyPrompt()/monthlyPrompt() (MealPlanService). */
    private const WEEKDAY_LABELS = [
        1 => 'Thứ 2', 2 => 'Thứ 3', 3 => 'Thứ 4', 4 => 'Thứ 5',
        5 => 'Thứ 6', 6 => 'Thứ 7', 7 => 'Chủ nhật',
    ];

    /** Nhãn ngắn hiển thị trên biểu đồ tuần. */
    private const WEEKDAY_SHORT = [
        1 => 'T2', 2 => 'T3', 3 => 'T4', 4 => 'T5', 5 => 'T6', 6 => 'T7', 7 => 'CN',
    ];

    /**
     * Bữa ăn & buổi tập của một NGÀY từ bất kỳ kế hoạch nào đang active — dữ liệu thô,
     * chưa gắn trạng thái done. Dùng cho cả hiển thị lẫn "thực hiện" nhiệm vụ.
     *
     * @return array{has_plan: bool, meals: ?array<int, array<string, mixed>>, workout: ?array<string, mixed>}
     */
    public function resolveTasks(int $userId, Carbon $date): array
    {
        // Kế hoạch daily đã thiết lập RIÊNG cho ngày này (từ chat "Thiết lập kế hoạch ăn...").
        $dayPlan = MealPlan::where('user_id', $userId)
            ->where('scope', 'daily')
            ->whereDate('target_date', $date->toDateString())
            ->first();

        $weeklyDay = $this->weeklyDayFor($userId, $date);

        $meals       = $dayPlan?->plan['meals'] ?? $weeklyDay['day']['meals'] ?? null;
        $mealsExists = (bool) $dayPlan || $weeklyDay['exists'];

        $workout = $weeklyDay['day']['workout'] ?? null;
        $hasPlan = $weeklyDay['exists'];

        // Fallback workout: plan của ngày này → plan daily mới nhất.
        if (!$workout) {
            $daily   = $dayPlan ?? MealPlan::where('user_id', $userId)
                ->where('scope', 'daily')
                ->latest('target_date')
                ->first();
            $hasPlan = $hasPlan || (bool) $daily;
            $workout = $daily?->plan['workouts'][0] ?? null;
        }

        // Fallback thứ 3: không daily, không weekly → buổi tập theo thứ trong kế hoạch THÁNG.
        if (!$workout) {
            $monthly = $this->workoutFromMonthly($userId, $date);
            $hasPlan = $hasPlan || $monthly['exists'];
            $workout = $monthly['workout'];
        }

        return [
            'has_plan' => $mealsExists || $hasPlan,
            'meals'    => $meals,
            'workout'  => $workout,
        ];
    }

    /**
     * Nhiệm vụ của một ngày kèm trạng thái done + số liệu tiến độ.
     *
     * @return array{has_plan: bool, meals: array<int, array<string, mixed>>, workout: ?array<string, mixed>, done: int, total: int, percent: int}
     */
    public function dayProgress(int $userId, Carbon $date): array
    {
        $tasks   = $this->resolveTasks($userId, $date);
        $meals   = $this->mealTasks($userId, $tasks['meals'], $date);
        $workout = null;

        if ($w = $tasks['workout']) {
            $workout = [
                'name'         => $w['name'] ?? 'Buổi tập',
                'type'         => $w['type'] ?? null,
                'duration_min' => isset($w['duration_min']) ? (int) $w['duration_min'] : null,
                'done'         => $this->workoutDone($userId, $date),
            ];
        }

        $total = count($meals) + ($workout ? 1 : 0);
        $done  = collect($meals)->where('done', true)->count() + (($workout && $workout['done']) ? 1 : 0);

        return [
            'has_plan' => $tasks['has_plan'],
            'meals'    => $meals,
            'workout'  => $workout,
            'done'     => $done,
            'total'    => $total,
            'percent'  => $total > 0 ? (int) round($done / $total * 100) : 0,
        ];
    }

    /**
     * Tiến độ tuần hiện tại (thứ 2 → hôm nay). Ngày tương lai KHÔNG tính vào phần trăm —
     * chưa tới thì không thể coi là "chưa hoàn thành", tính vào sẽ luôn ra con số bi quan.
     *
     * @return array{percent: int, done: int, total: int, days: array<int, array<string, mixed>>}
     */
    public function weekProgress(int $userId, Carbon $today): array
    {
        $start   = $today->copy()->startOfWeek();
        $days    = [];
        $done    = 0;
        $total   = 0;

        for ($i = 0; $i < 7; $i++) {
            $date     = $start->copy()->addDays($i);
            $isFuture = $date->gt($today);

            if ($isFuture) {
                $days[] = [
                    'date'      => $date->toDateString(),
                    'label'     => self::WEEKDAY_SHORT[$date->dayOfWeekIso],
                    'percent'   => null,      // chưa tới → không vẽ cột
                    'is_future' => true,
                    'is_today'  => false,
                ];
                continue;
            }

            $day    = $this->dayProgress($userId, $date);
            $done  += $day['done'];
            $total += $day['total'];

            $days[] = [
                'date'      => $date->toDateString(),
                'label'     => self::WEEKDAY_SHORT[$date->dayOfWeekIso],
                'percent'   => $day['percent'],
                'done'      => $day['done'],
                'total'     => $day['total'],
                'is_future' => false,
                'is_today'  => $date->isSameDay($today),
            ];
        }

        return [
            'percent' => $total > 0 ? (int) round($done / $total * 100) : 0,
            'done'    => $done,
            'total'   => $total,
            'days'    => $days,
        ];
    }

    /**
     * Câu động viên/khen theo mức hoàn thành — đổi giọng theo cả tiến độ hôm nay lẫn cả tuần
     * để không lặp đi lặp lại một câu, và để ngày lỡ hẹn vẫn được nhìn nhận trong bối cảnh tuần.
     *
     * @return array{title: string, message: string, emoji: string}
     */
    public function encouragement(int $todayPercent, int $weekPercent, bool $hasPlan): array
    {
        if (!$hasPlan) {
            return [
                'emoji'   => '🌱',
                'title'   => 'Bắt đầu từ hôm nay nhé!',
                'message' => 'Bạn chưa có kế hoạch nào đang theo. Tạo một kế hoạch để mình đồng hành cùng bạn mỗi ngày.',
            ];
        }

        if ($todayPercent >= 100) {
            return [
                'emoji'   => '🏆',
                'title'   => 'Hoàn hảo! Bạn đã xong hết hôm nay',
                'message' => $weekPercent >= 80
                    ? 'Cả tuần bạn đều giữ phong độ rất tốt. Đây chính là lúc thói quen bắt đầu hình thành — cứ giữ nhịp này nhé!'
                    : 'Một ngày trọn vẹn 100%. Hãy để hôm nay là bàn đạp cho những ngày còn lại trong tuần!',
            ];
        }

        if ($todayPercent >= 75) {
            return [
                'emoji'   => '🔥',
                'title'   => 'Sắp về đích rồi!',
                'message' => 'Bạn đã hoàn thành phần lớn kế hoạch hôm nay. Chỉ còn một chút nữa thôi — cố lên!',
            ];
        }

        if ($todayPercent >= 50) {
            return [
                'emoji'   => '💪',
                'title'   => 'Bạn đang đi đúng hướng',
                'message' => 'Quá nửa chặng đường hôm nay đã xong. Giữ nhịp độ này thì mục tiêu tuần nằm trong tầm tay.',
            ];
        }

        if ($todayPercent > 0) {
            return [
                'emoji'   => '🌤️',
                'title'   => 'Khởi đầu tốt!',
                'message' => $weekPercent >= 60
                    ? 'Hôm nay mới bắt đầu, nhưng cả tuần bạn đang làm rất tốt. Hoàn thành nốt các nhiệm vụ còn lại nhé!'
                    : 'Mỗi bữa ăn đúng kế hoạch đều có giá trị. Tiếp tục với nhiệm vụ tiếp theo thôi!',
            ];
        }

        return [
            'emoji'   => '☀️',
            'title'   => 'Hôm nay vẫn còn nguyên cơ hội',
            'message' => $weekPercent >= 50
                ? 'Tuần này bạn đang làm tốt rồi. Bắt đầu nhiệm vụ đầu tiên của hôm nay để giữ vững thành quả nhé!'
                : 'Chưa có nhiệm vụ nào được hoàn thành hôm nay. Bắt đầu từ bữa gần nhất — một bước nhỏ vẫn hơn không bước nào!',
        ];
    }

    /**
     * Các bữa trong kế hoạch của ngày → nhiệm vụ kèm done.
     * done = đã có bữa ăn ghi nhận trong khung giờ của slot (bằng bất kỳ cách nào).
     *
     * @param  ?array<int,array<string,mixed>>  $meals
     * @return array<int,array{slot:string,name:string,calories:?int,done:bool}>
     */
    public function mealTasks(int $userId, ?array $meals, Carbon $date): array
    {
        if (empty($meals)) {
            return [];
        }

        $logs = MealLog::where('user_id', $userId)
            ->whereDate('logged_at', $date->toDateString())
            ->get(['food_name', 'logged_at']);

        $normalize = fn (?string $s): string => mb_strtolower(trim((string) $s));

        $tasks = collect($meals)
            ->map(fn ($m) => [
                'slot'     => $m['slot'] ?? 'snack',
                'name'     => $m['name'] ?? ($m['items'][0] ?? 'Bữa ăn'),
                'calories' => isset($m['calories']) ? (int) $m['calories'] : null,
                'done'     => false,
            ])
            ->values()
            ->all();

        // Vòng 1 — khớp theo TÊN món: bấm "Thực hiện" bữa sáng lúc 20h sẽ ghi log vào 20h; nếu
        // chỉ xét khung giờ thì hoá ra đánh dấu nhầm bữa TỐI xong còn bữa sáng vừa bấm vẫn chưa.
        // Log đã khớp tên bị loại khỏi vòng 2 để một lần bấm không tính thành hai nhiệm vụ.
        $unmatched = [];
        foreach ($logs as $log) {
            $matchedIndex = null;
            foreach ($tasks as $i => $t) {
                if (!$t['done'] && $normalize($t['name']) === $normalize($log->food_name)) {
                    $matchedIndex = $i;
                    break;
                }
            }
            if ($matchedIndex !== null) {
                $tasks[$matchedIndex]['done'] = true;
            } else {
                $unmatched[] = $log->logged_at->hour;
            }
        }

        // Vòng 2 — các bữa ghi nhận theo cách khác (nhận diện ảnh, log nhanh…): suy theo khung giờ.
        foreach ($tasks as $i => $t) {
            if ($t['done']) {
                continue;
            }
            $tasks[$i]['done'] = (bool) array_filter($unmatched, fn (int $h) => match ($t['slot']) {
                'breakfast' => $h >= 4 && $h < 11,
                'lunch'     => $h >= 11 && $h < 15,
                'dinner'    => $h >= 17 && $h < 23,
                'snack'     => ($h >= 15 && $h < 17) || $h >= 21 || $h < 4,
                default     => false,
            });
        }

        return $tasks;
    }

    public function workoutDone(int $userId, Carbon $date): bool
    {
        return HealthActivity::where('user_id', $userId)
            ->whereDate('started_at', $date->toDateString())
            ->exists();
    }

    /**
     * Ngày (trong 7 ngày kế hoạch tuần chứa $date) khớp $date, nếu có kế hoạch tuần đó.
     *
     * @return array{exists: bool, day: ?array<string, mixed>}
     */
    private function weeklyDayFor(int $userId, Carbon $date): array
    {
        $weekly = MealPlan::where('user_id', $userId)
            ->where('scope', 'weekly')
            ->where('target_date', $date->copy()->startOfWeek()->toDateString())
            ->first();

        if (!$weekly) {
            return ['exists' => false, 'day' => null];
        }

        $weekday = $date->dayOfWeekIso; // 1 = Thứ 2 … 7 = Chủ nhật
        foreach ($weekly->plan['days'] ?? [] as $day) {
            if ((int) ($day['weekday'] ?? 0) === $weekday) {
                return ['exists' => true, 'day' => $day];
            }
        }

        return ['exists' => true, 'day' => null];
    }

    /**
     * Buổi tập theo thứ từ kế hoạch THÁNG (weekly_workout_split) — fallback cuối khi không có
     * kế hoạch daily/weekly. Monthly không có dữ liệu bữa ăn theo ngày nên không áp dụng cho meals.
     *
     * @return array{exists: bool, workout: ?array<string, mixed>}
     */
    private function workoutFromMonthly(int $userId, Carbon $date): array
    {
        $monthly = MealPlan::where('user_id', $userId)
            ->where('scope', 'monthly')
            ->where('target_date', $date->copy()->startOfMonth()->toDateString())
            ->first();

        if (!$monthly) {
            return ['exists' => false, 'workout' => null];
        }

        $label = self::WEEKDAY_LABELS[$date->dayOfWeekIso] ?? null;

        foreach ($monthly->plan['weekly_workout_split'] ?? [] as $item) {
            if ($label !== null && trim((string) ($item['day'] ?? '')) === $label) {
                return [
                    'exists'  => true,
                    'workout' => [
                        'name'         => $item['activity'] ?? 'Buổi tập hôm nay',
                        'type'         => null, // schema monthly không có "type" (cardio/strength/...)
                        'duration_min' => $item['duration_min'] ?? null,
                    ],
                ];
            }
        }

        return ['exists' => true, 'workout' => null];
    }
}
