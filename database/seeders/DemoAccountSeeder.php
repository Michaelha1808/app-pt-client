<?php

namespace Database\Seeders;

use App\Models\Dish;
use App\Models\FavoriteMeal;
use App\Models\HealthActivity;
use App\Models\MealLog;
use App\Models\StreakMilestone;
use App\Models\UsageEvent;
use App\Models\User;
use App\Models\UserPreference;
use App\Models\UserStreak;
use App\Models\WaterLog;
use App\Models\WeightLog;
use App\Services\MealPlanService;
use App\Support\VietnameseText;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Tài khoản demo dùng cho buổi bảo vệ đồ án — có đủ lịch sử ăn uống, cân nặng,
 * vận động, streak, sở thích cá nhân... để mọi màn hình có dữ liệu thật khi trình bày.
 * Idempotent: chạy lại sẽ xoá dữ liệu phụ thuộc cũ của user demo rồi tạo lại từ đầu.
 */
class DemoAccountSeeder extends Seeder
{
    private const EMAIL = 'demo@caloeye.vn';

    /** Log đầy đủ từ 1 tháng trước tới hôm qua; hôm nay để dở (demo trực tiếp). */
    private const HISTORY_DAYS = 29;

    /** Ngày (tính theo daysAgo) cố tình không log gì — được Freeze Token bảo vệ streak. */
    private const FREEZE_PROTECTED_DAYS_AGO = 18;

    public function run(): void
    {
        $this->call(DishCatalogSeeder::class);

        $user = User::updateOrCreate(
            ['email' => self::EMAIL],
            [
                'name'                        => 'Minh Anh',
                'password'                    => 'Demo@1234',
                'email_verified_at'           => now(),
                'gender'                      => 'female',
                'birth_year'                  => 1998,
                'height_cm'                   => 160,
                'weight_kg'                   => 58.0,
                'calorie_goal'                => 1800,
                'role'                        => 'user',
                'status'                      => 'active',
                'morning_notify_enabled'      => true,
                'midday_notify_enabled'       => true,
                'evening_notify_enabled'      => true,
                'email_reengagement_enabled'  => true,
                'weigh_in_reminder_enabled'   => true,
                'last_seen_at'                => now(),
            ],
        );

        // Idempotent: dọn dữ liệu phụ thuộc cũ trước khi tạo lại.
        $user->mealLogs()->delete();
        $user->weightLogs()->delete();
        $user->healthActivities()->delete();
        $user->waterLogs()->delete();
        $user->favoriteMeals()->delete();
        $user->preferences()->delete();
        $user->streakMilestones()->delete();
        $user->mealPlans()->delete();
        UsageEvent::where('user_id', $user->id)->delete();
        UserStreak::where('user_id', $user->id)->delete();

        $this->seedWeightLogs($user);
        $this->seedMealLogsAndActivities($user);
        $this->seedPreferences($user);
        $this->seedFavorites($user);
        $this->seedStreak($user);
        $this->seedUsageEvents($user);
        $this->seedMealPlans($user);

        $this->command?->info('Demo account: ' . self::EMAIL . ' / Demo@1234 (user #' . $user->id . ')');
    }

    /** 45 ngày, mỗi ~3 ngày 1 lần, xu hướng giảm nhẹ 61.0kg -> 58.0kg. */
    private function seedWeightLogs(User $user): void
    {
        $start = 61.0;
        $end   = 58.0;
        $days  = range(45, 0, 3);
        $count = count($days);
        $notes = [
            'Sau buổi chạy bộ buổi sáng', 'Cảm thấy nhẹ nhàng hơn', null, null,
            'Ăn hơi mặn hôm qua', null, 'Sau kỳ nghỉ cuối tuần', null,
        ];

        foreach ($days as $i => $daysAgo) {
            $progress = $count > 1 ? $i / ($count - 1) : 1;
            $weight   = round($start - ($start - $end) * $progress + $this->wiggle(0.3), 1);

            WeightLog::create([
                'user_id'     => $user->id,
                'weight_kg'   => $weight,
                'logged_date' => Carbon::today()->subDays($daysAgo),
                'note'        => $notes[$i % count($notes)],
            ]);
        }
    }

    /** HISTORY_DAYS ngày gần nhất: 3 bữa/ngày từ thư viện món ăn + vài buổi tập thủ công. */
    private function seedMealLogsAndActivities(User $user): void
    {
        $dishes = Dish::inRandomOrder()->limit(60)->get();
        if ($dishes->isEmpty()) {
            $dishes = Dish::all();
        }

        $byMeal = [
            'breakfast' => $dishes->whereIn('name', ['Phở bò', 'Phở gà', 'Bánh mì thịt', 'Xôi', 'Cháo', 'Bánh cuốn', 'Bún bò Huế'])->values(),
            'lunch'     => $dishes->whereIn('name', ['Cơm tấm sườn', 'Cơm gà', 'Cơm chiên Dương Châu', 'Bún chả', 'Hủ tiếu', 'Mì Quảng', 'Cơm trắng', 'Rau muống xào', 'Canh chua'])->values(),
            'dinner'    => $dishes->whereIn('name', ['Thịt kho tàu', 'Cá kho', 'Đậu hũ sốt cà', 'Canh chua', 'Cơm trắng', 'Salad', 'Rau muống xào', 'Gỏi cuốn'])->values(),
            'snack'     => $dishes->whereIn('name', ['Chè', 'Sinh tố bơ', 'Trà sữa', 'Cà phê sữa', 'Gỏi cuốn', 'Chả giò'])->values(),
        ];

        $advices = [
            'Bữa ăn cân đối, đủ đạm cho buổi sáng năng lượng.',
            'Hơi nhiều tinh bột, nên thêm rau xanh vào bữa sau.',
            'Lượng natri hơi cao, uống thêm nước và giảm muối bữa tối.',
            'Tốt cho mục tiêu giảm cân, tiếp tục duy trì nhé!',
            null, null,
        ];

        $workouts = [
            ['type' => 'run',      'name' => 'Chạy bộ công viên',  'duration' => 30 * 60, 'calories' => 260, 'distance' => 4200],
            ['type' => 'strength', 'name' => 'Tập gym toàn thân',  'duration' => 45 * 60, 'calories' => 320, 'distance' => null],
            ['type' => 'cycling',  'name' => 'Đạp xe buổi tối',    'duration' => 40 * 60, 'calories' => 280, 'distance' => 9500],
            ['type' => 'yoga',     'name' => 'Yoga giãn cơ',       'duration' => 25 * 60, 'calories' => 110, 'distance' => null],
        ];

        // 1 tháng trước -> hôm qua: đủ 3 bữa mỗi ngày (nuôi streak liên tục ~1 tháng).
        // Riêng self::FREEZE_PROTECTED_DAYS_AGO: cố tình KHÔNG log gì — ngày này được
        // Freeze Token bảo vệ streak (minh hoạ đúng tính năng freeze khi demo).
        for ($daysAgo = self::HISTORY_DAYS; $daysAgo >= 1; $daysAgo--) {
            if ($daysAgo === self::FREEZE_PROTECTED_DAYS_AGO) {
                continue;
            }

            $date = Carbon::today()->subDays($daysAgo);

            $this->logMeal($user, $byMeal['breakfast'], $date->copy()->setTime(7, rand(0, 45)), $advices);
            $this->logMeal($user, $byMeal['lunch'], $date->copy()->setTime(12, rand(0, 45)), $advices);
            $this->logMeal($user, $byMeal['dinner'], $date->copy()->setTime(18, 30 + rand(0, 45) % 30), $advices);

            if ($daysAgo % 3 === 0) {
                $this->logMeal($user, $byMeal['snack'], $date->copy()->setTime(15, rand(0, 45)), $advices);
            }

            if ($daysAgo % 2 === 0) {
                $w = $workouts[array_rand($workouts)];
                HealthActivity::create([
                    'user_id'          => $user->id,
                    'provider'         => 'manual',
                    'source'           => 'manual',
                    'type'             => $w['type'],
                    'name'             => $w['name'],
                    'started_at'       => $date->copy()->setTime(6, 30),
                    'duration_seconds' => $w['duration'],
                    'distance_meters'  => $w['distance'],
                    'calories'         => $w['calories'],
                ]);
            }

            WaterLog::create([
                'user_id'   => $user->id,
                'amount_ml' => [1500, 1800, 2000, 2200][array_rand([1500, 1800, 2000, 2200])],
                'logged_at' => $date->copy()->setTime(20, 0),
            ]);
        }

        // Hôm nay: mới log sáng + trưa, còn tối để demo trực tiếp lúc bảo vệ.
        $today = Carbon::today();
        $this->logMeal($user, $byMeal['breakfast'], $today->copy()->setTime(7, 15), $advices);
        $this->logMeal($user, $byMeal['lunch'], $today->copy()->setTime(12, 10), $advices);
        WaterLog::create([
            'user_id'   => $user->id,
            'amount_ml' => 500,
            'logged_at' => $today->copy()->setTime(9, 0),
        ]);
    }

    private function logMeal(User $user, $candidates, Carbon $loggedAt, array $advices): void
    {
        if ($candidates->isEmpty()) {
            return;
        }

        $dish = $candidates->random();
        $factor = 1 + $this->wiggle(0.08);

        MealLog::create([
            'user_id'   => $user->id,
            'food_name' => $dish->name,
            'serving'   => $dish->serving,
            'calories'  => (int) round($dish->calories * $factor),
            'protein'   => (int) round($dish->protein * $factor),
            'carbs'     => (int) round($dish->carbs * $factor),
            'fat'       => (int) round($dish->fat * $factor),
            'sodium'    => (int) round($dish->sodium * $factor),
            'ai_advice' => $advices[array_rand($advices)],
            'logged_at' => $loggedAt,
        ]);
    }

    private function seedPreferences(User $user): void
    {
        $rows = [
            ['kind' => 'allergy', 'label' => 'Tôm, hải sản có vỏ', 'source' => 'chat'],
            ['kind' => 'dislike', 'label' => 'Nội tạng động vật', 'source' => 'chat'],
            ['kind' => 'like', 'label' => 'Rau xanh, ức gà', 'source' => 'manual'],
            ['kind' => 'diet', 'label' => 'Giảm cân, ưu tiên ít tinh bột buổi tối', 'source' => 'chat'],
            ['kind' => 'habit', 'label' => 'Hay ăn khuya cuối tuần', 'source' => 'inferred'],
        ];

        foreach ($rows as $r) {
            UserPreference::create([
                'user_id'           => $user->id,
                'kind'              => $r['kind'],
                'value'             => VietnameseText::normalize($r['label']),
                'label'             => $r['label'],
                'source'            => $r['source'],
                'last_confirmed_at' => now()->subDays(rand(0, 5)),
            ]);
        }
    }

    private function seedFavorites(User $user): void
    {
        $names = ['Phở gà', 'Cơm gà', 'Salad'];
        foreach (Dish::whereIn('name', $names)->get() as $dish) {
            FavoriteMeal::create([
                'user_id'   => $user->id,
                'food_name' => $dish->name,
                'serving'   => $dish->serving,
                'calories'  => $dish->calories,
                'protein'   => $dish->protein,
                'carbs'     => $dish->carbs,
                'fat'       => $dish->fat,
                'sodium'    => $dish->sodium,
            ]);
        }
    }

    private function seedStreak(User $user): void
    {
        // Streak liên tục suốt cả cửa sổ HISTORY_DAYS: ngày FREEZE_PROTECTED_DAYS_AGO
        // không có log thật nhưng được freeze token bảo vệ nên không bị đứt.
        $currentStreak = self::HISTORY_DAYS;

        UserStreak::create([
            'user_id'               => $user->id,
            'current_streak'        => $currentStreak,
            'best_streak'           => $currentStreak,
            'last_activity_date'    => Carbon::yesterday(),
            'freeze_tokens'         => 2,
            'freeze_last_used_date' => Carbon::today()->subDays(self::FREEZE_PROTECTED_DAYS_AGO),
        ]);

        // Mốc đạt được tính theo số ngày liên tục kể từ ngày bắt đầu cửa sổ log.
        foreach ([3, 7, 14] as $days) {
            $achievedDaysAgo = self::HISTORY_DAYS - $days + 1;
            StreakMilestone::create([
                'user_id'      => $user->id,
                'days'         => $days,
                'achieved_at'  => Carbon::today()->subDays($achievedDaysAgo)->setTime(21, 0),
                'push_sent_at' => Carbon::today()->subDays($achievedDaysAgo)->setTime(21, 1),
            ]);
        }
    }

    /** Vài usage_events để KPI admin dashboard không trống khi demo. */
    private function seedUsageEvents(User $user): void
    {
        for ($daysAgo = self::HISTORY_DAYS; $daysAgo >= 0; $daysAgo--) {
            $date = Carbon::today()->subDays($daysAgo);
            UsageEvent::create(['type' => 'food_analyze', 'user_id' => $user->id, 'created_at' => $date->copy()->setTime(12, 5)]);
            UsageEvent::create(['type' => 'chat', 'user_id' => $user->id, 'created_at' => $date->copy()->setTime(20, 30)]);
        }
    }

    /** Gọi thẳng MealPlanService thật (kể cả gọi Gemini) để dữ liệu plan khớp 100% với luồng thật. */
    private function seedMealPlans(User $user): void
    {
        $service = app(MealPlanService::class);

        foreach (['daily', 'weekly', 'monthly'] as $scope) {
            try {
                $context = $service->buildContext($user, $scope);
                $plan    = $service->getStructuredPlan($context, $scope);

                $targetDate = match ($scope) {
                    'monthly' => today()->startOfMonth()->toDateString(),
                    'weekly'  => today()->startOfWeek()->toDateString(),
                    default   => today()->addDay()->toDateString(),
                };

                $reasoning = '';
                foreach ($service->streamReasoning($context, $plan, $scope) as $delta) {
                    $reasoning .= $delta;
                }

                $user->mealPlans()->updateOrCreate(
                    ['scope' => $scope, 'target_date' => $targetDate],
                    [
                        'plan'             => $plan,
                        'context_snapshot' => $context,
                        'data_hash'        => $context['data_hash'],
                        'reasoning'        => $reasoning,
                    ]
                );

                $this->command?->info("  -> đã tạo meal plan '$scope' bằng Gemini thật");
            } catch (\Throwable $e) {
                $this->command?->warn("  -> bỏ qua meal plan '$scope' (lỗi: {$e->getMessage()})");
            }
        }
    }

    private function wiggle(float $range): float
    {
        return (mt_rand(-100, 100) / 100) * $range;
    }
}
