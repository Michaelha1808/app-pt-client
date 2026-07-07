<?php

namespace App\Services;

use App\Models\User;
use App\Support\VietnameseText;

class QuickLogService
{
    private const MIN_COUNT = 2;

    /**
     * Món ăn "thường ăn" trong 30 ngày qua, gom nhóm theo tên+serving đã chuẩn hoá
     * (bỏ dấu, lowercase) để "Phở bò" và "phở Bò" tính chung. Chỉ trả món ≥2 lần.
     *
     * @return array<int, array<string, mixed>>
     */
    public function frequent(User $user, ?string $slot, int $limit): array
    {
        $query = $user->mealLogs()
            ->where('logged_at', '>=', today()->subDays(29)->startOfDay());

        if ($slot === 'morning') {
            $query->whereTime('logged_at', '>=', '04:00:00')->whereTime('logged_at', '<', '11:00:00');
        } elseif ($slot === 'noon') {
            $query->whereTime('logged_at', '>=', '11:00:00')->whereTime('logged_at', '<', '17:00:00');
        } elseif ($slot === 'evening') {
            $query->where(fn ($q) => $q->whereTime('logged_at', '>=', '17:00:00')->orWhereTime('logged_at', '<', '04:00:00'));
        }

        $logs = $query->orderByDesc('logged_at')->get();

        $favoriteKeys = $user->favoriteMeals()
            ->get()
            ->map(fn ($f) => $this->groupKey($f->food_name, $f->serving))
            ->flip();

        return $logs
            ->groupBy(fn ($l) => $this->groupKey($l->food_name, $l->serving))
            ->map(function ($group) use ($favoriteKeys) {
                $latest = $group->first(); // collection đã orderByDesc('logged_at') → phần tử đầu = gần nhất
                return [
                    'food_name'   => $latest->food_name,
                    'serving'     => $latest->serving,
                    'count'       => $group->count(),
                    'calories'    => $latest->calories,
                    'protein'     => $latest->protein,
                    'carbs'       => $latest->carbs,
                    'fat'         => $latest->fat,
                    'sodium'      => $latest->sodium,
                    'image_url'   => $latest->image_url,
                    'last_log_id' => $latest->id,
                    'is_favorite' => $favoriteKeys->has($this->groupKey($latest->food_name, $latest->serving)),
                ];
            })
            ->filter(fn ($item) => $item['count'] >= self::MIN_COUNT)
            ->sortByDesc('count')
            ->take($limit)
            ->values()
            ->all();
    }

    private function groupKey(string $foodName, ?string $serving): string
    {
        return VietnameseText::normalize($foodName) . '|' . VietnameseText::normalize((string) $serving);
    }
}
