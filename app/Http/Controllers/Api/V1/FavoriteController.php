<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\FavoriteMeal;
use App\Models\MealLog;
use App\Models\User;
use App\Services\PreferenceService;
use App\Services\StreakService;
use App\Support\UsageTracker;
use App\Support\VietnameseText;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FavoriteController extends Controller
{
    private const MAX_FAVORITES = 50;

    public function index(Request $request): JsonResponse
    {
        $items = $request->user()->favoriteMeals()->orderByDesc('id')->get()->map(fn ($f) => $this->format($f));

        return response()->json(['items' => $items]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->favoriteMeals()->count() >= self::MAX_FAVORITES) {
            return response()->json(['detail' => 'Tối đa 50 món yêu thích'], 422);
        }

        if ($request->filled('meal_log_id')) {
            $data = $request->validate(['meal_log_id' => 'required|integer']);
            $log  = MealLog::where('id', $data['meal_log_id'])->where('user_id', $user->id)->firstOrFail();

            $payload = [
                'food_name'  => $log->food_name,
                'serving'    => $log->serving,
                'calories'   => $log->calories,
                'protein'    => $log->protein,
                'carbs'      => $log->carbs,
                'fat'        => $log->fat,
                'sodium'     => $log->sodium,
                'image_path' => $log->image_path,
            ];
        } else {
            $payload = $request->validate([
                'food_name' => 'required|string|max:200',
                'serving'   => 'nullable|string|max:100',
                // numeric: calo có thể là số lẻ khi khớp thư viện + hệ số khẩu phần — favorite_meals.calories
                // vẫn là cột integer nên ép tròn ngay dưới đây trước khi lưu.
                'calories'  => 'required|numeric|min:0|max:10000',
                'protein'   => 'required|integer|min:0',
                'carbs'     => 'required|integer|min:0',
                'fat'       => 'required|integer|min:0',
                'sodium'    => 'required|integer|min:0',
            ]);
            $payload['calories'] = (int) round($payload['calories']);
        }

        $exists = $user->favoriteMeals()
            ->where('food_name', $payload['food_name'])
            ->where('serving', $payload['serving'] ?? null)
            ->exists();

        if ($exists) {
            return response()->json(['detail' => 'Món này đã có trong danh sách yêu thích'], 409);
        }

        $favorite = $user->favoriteMeals()->create($payload);

        // Món yêu thích cũng là món "thích" → AI ưu tiên gợi ý (trang /profile/preferences mục Thích)
        $this->syncLikePreference($user, $favorite->food_name);

        return response()->json(['item' => $this->format($favorite)], 201);
    }

    public function logFavorite(Request $request, FavoriteMeal $favorite, StreakService $streakService): JsonResponse
    {
        abort_if($favorite->user_id !== $request->user()->id, 404);

        $user = $request->user();
        $log  = $user->mealLogs()->create([
            'food_name'  => $favorite->food_name,
            'serving'    => $favorite->serving,
            'calories'   => $favorite->calories,
            'protein'    => $favorite->protein,
            'carbs'      => $favorite->carbs,
            'fat'        => $favorite->fat,
            'sodium'     => $favorite->sodium,
            'image_path' => $favorite->image_path,
        ]);

        app(PreferenceService::class)->bustHabitCache($user->id);
        $streak = $streakService->recordMealActivity($user->load('streakMilestones', 'notificationSubscriptions'));

        UsageTracker::record('quick_log_favorite', $user->id);

        return response()->json([
            'message' => 'Đã lưu bữa ăn',
            'id'      => $log->id,
            'streak'  => $streak,
        ], 201);
    }

    public function destroy(Request $request, FavoriteMeal $favorite): \Illuminate\Http\Response
    {
        abort_if($favorite->user_id !== $request->user()->id, 404);

        $foodName = $favorite->food_name;
        $favorite->delete();

        // Bỏ yêu thích → gỡ khỏi mục "Thích" (chỉ kind=like; không đụng allergy/dislike/diet)
        $request->user()->preferences()
            ->where('kind', 'like')
            ->where('value', VietnameseText::normalize($foodName))
            ->delete();

        return response()->noContent();
    }

    /**
     * Ghi món vào user_preferences kind=like nếu nguyên liệu/món này chưa mang
     * "thái độ" nào khác (allergy/dislike/diet giữ nguyên — không ghi đè).
     * Best-effort: đạt trần 50 preferences hay lỗi khác thì favorite vẫn thành công.
     */
    private function syncLikePreference(User $user, string $foodName): void
    {
        try {
            $value = VietnameseText::normalize($foodName);
            if ($value === '' || $user->preferences()->where('value', $value)->exists()) {
                return;
            }
            app(PreferenceService::class)->add($user, 'like', $foodName, 'manual');
        } catch (\Throwable) {
            // nuốt lỗi — sync preference là phụ, không chặn luồng yêu thích
        }
    }

    private function format(FavoriteMeal $f): array
    {
        return [
            'id'        => $f->id,
            'food_name' => $f->food_name,
            'serving'   => $f->serving,
            'calories'  => $f->calories,
            'protein'   => $f->protein,
            'carbs'     => $f->carbs,
            'fat'       => $f->fat,
            'sodium'    => $f->sodium,
            'image_url' => $f->image_url,
        ];
    }
}
