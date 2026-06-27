<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\MealLog;
use App\Models\NotificationLog;
use App\Models\UsageEvent;
use App\Models\User;
use App\Models\UserStreak;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class StatsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $range = (int) match ($request->query('range', '30d')) {
            '7d'  => 7,
            '90d' => 90,
            default => 30,
        };

        $data = Cache::remember("admin.stats.{$range}", now()->addMinutes(5), function () use ($range) {
            return [
                'kpi'       => $this->kpi(),
                'series'    => $this->series($range),
                'breakdown' => $this->breakdown(),
            ];
        });

        return response()->json($data);
    }

    private function kpi(): array
    {
        $today = Carbon::today();

        return [
            'total_users'           => User::count(),
            'new_users_today'       => User::whereDate('created_at', $today)->count(),
            'active_users_7d'       => User::where('last_seen_at', '>=', now()->subDays(7))->count(),
            'suspended_users'       => User::where('status', 'suspended')->count(),
            'total_meal_logs'       => MealLog::count(),
            'meal_logs_today'       => MealLog::whereDate('logged_at', $today)->count(),
            'ai_food_analyses_today' => UsageEvent::whereIn('type', ['food_analyze', 'food_detect'])
                ->whereDate('created_at', $today)->count(),
            'ai_chat_messages_today' => UsageEvent::where('type', 'chat')
                ->whereDate('created_at', $today)->count(),
            'push_sent_today'       => NotificationLog::whereDate('created_at', $today)->count(),
            'active_streaks'        => UserStreak::where('current_streak', '>', 0)->count(),
        ];
    }

    private function series(int $days): array
    {
        $from = Carbon::today()->subDays($days - 1);

        $newUsers = $this->dailyCounts(
            User::query()->where('created_at', '>=', $from), 'created_at', $from, $days
        );
        $mealLogs = $this->dailyCounts(
            MealLog::query()->where('logged_at', '>=', $from), 'logged_at', $from, $days
        );
        $aiCalls = $this->dailyCounts(
            UsageEvent::query()->where('created_at', '>=', $from), 'created_at', $from, $days
        );

        return [
            'new_users' => $newUsers,
            'meal_logs' => $mealLogs,
            'ai_calls'  => $aiCalls,
        ];
    }

    /**
     * Đếm theo ngày, lấp đầy ngày trống = 0. Trả [{date, count}].
     */
    private function dailyCounts($query, string $column, Carbon $from, int $days): array
    {
        $rows = $query
            ->selectRaw("DATE({$column}) as d, COUNT(*) as c")
            ->groupBy('d')
            ->pluck('c', 'd');

        $out = [];
        for ($i = 0; $i < $days; $i++) {
            $date = $from->copy()->addDays($i)->toDateString();
            $out[] = ['date' => $date, 'count' => (int) ($rows[$date] ?? 0)];
        }
        return $out;
    }

    private function breakdown(): array
    {
        $byProvider = User::select('provider', DB::raw('COUNT(*) as c'))
            ->groupBy('provider')->pluck('c', 'provider')
            ->mapWithKeys(fn ($c, $p) => [$p ?: 'email' => (int) $c])->toArray();

        $byGender = User::select('gender', DB::raw('COUNT(*) as c'))
            ->groupBy('gender')->pluck('c', 'gender')
            ->mapWithKeys(fn ($c, $g) => [$g ?: 'unknown' => (int) $c])->toArray();

        return [
            'by_provider' => $byProvider,
            'by_gender'   => $byGender,
        ];
    }
}
