<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\FavoriteMeal;
use App\Models\MealLog;
use App\Services\PreferenceService;
use App\Services\StreakService;
use App\Support\UsageTracker;
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
                'calories'  => 'required|integer|min:0|max:10000',
                'protein'   => 'required|integer|min:0',
                'carbs'     => 'required|integer|min:0',
                'fat'       => 'required|integer|min:0',
                'sodium'    => 'required|integer|min:0',
            ]);
        }

        $exists = $user->favoriteMeals()
            ->where('food_name', $payload['food_name'])
            ->where('serving', $payload['serving'] ?? null)
            ->exists();

        if ($exists) {
            return response()->json(['detail' => 'Món này đã có trong danh sách yêu thích'], 409);
        }

        $favorite = $user->favoriteMeals()->create($payload);

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

        $favorite->delete();

        return response()->noContent();
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
