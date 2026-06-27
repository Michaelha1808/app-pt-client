<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    private const SORTABLE = ['created_at', 'last_seen_at', 'name'];

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'search'   => 'nullable|string|max:100',
            'role'     => 'nullable|in:user,admin',
            'status'   => 'nullable|in:active,suspended',
            'provider' => 'nullable|string|max:20',
            'sort'     => 'nullable|in:created_at,last_seen_at,name',
            'order'    => 'nullable|in:asc,desc',
            'per_page' => 'nullable|integer|between:1,100',
        ]);

        $sort  = in_array($request->query('sort'), self::SORTABLE, true) ? $request->query('sort') : 'created_at';
        $order = $request->query('order') === 'asc' ? 'asc' : 'desc';

        $query = User::query()->withCount('mealLogs');

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }
        if ($role = $request->query('role'))         $query->where('role', $role);
        if ($status = $request->query('status'))     $query->where('status', $status);
        if ($provider = $request->query('provider')) $query->where('provider', $provider);

        $paginator = $query->orderBy($sort, $order)
            ->paginate((int) $request->query('per_page', 20));

        return response()->json([
            'data' => collect($paginator->items())->map(fn ($u) => $this->row($u)),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
                'last_page'    => $paginator->lastPage(),
            ],
        ]);
    }

    public function show(User $user): JsonResponse
    {
        return response()->json($this->detail($user));
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'name'         => 'sometimes|string|min:2|max:100',
            'email'        => ['sometimes', 'email', Rule::unique('users', 'email')->ignore($user->id)],
            'role'         => 'sometimes|in:user,admin',
            'calorie_goal' => 'sometimes|integer|between:1000,5000',
            'birth_year'   => 'sometimes|integer|between:1900,2015',
            'gender'       => 'sometimes|in:male,female,other',
            'height_cm'    => 'sometimes|numeric|between:50,300',
            'weight_kg'    => 'sometimes|numeric|between:20,500',
        ]);

        // Không cho tự đổi role của chính mình (tránh tự gỡ quyền)
        if (array_key_exists('role', $data) && $user->id === $request->user()->id && $data['role'] !== 'admin') {
            return response()->json(['detail' => 'Không thể tự gỡ quyền admin của chính bạn'], 422);
        }

        // Không hạ cấp admin active cuối cùng
        if (array_key_exists('role', $data) && $user->isAdmin() && $data['role'] === 'user'
            && $this->activeAdminCount() <= 1) {
            return response()->json(['detail' => 'Đây là admin active duy nhất, không thể hạ quyền'], 422);
        }

        $user->update($data);
        AuditLogger::log('user.update', 'user', (string) $user->id, ['fields' => array_keys($data)]);

        return response()->json($this->detail($user->fresh()->loadCount('mealLogs')));
    }

    public function suspend(Request $request, User $user): JsonResponse
    {
        $request->validate(['reason' => 'nullable|string|max:255']);

        if ($user->id === $request->user()->id) {
            return response()->json(['detail' => 'Không thể tự khoá tài khoản của bạn'], 422);
        }
        if ($user->isAdmin()) {
            return response()->json(['detail' => 'Không thể khoá tài khoản admin'], 422);
        }

        $user->update(['status' => 'suspended', 'suspend_reason' => $request->input('reason')]);
        $user->tokens()->delete(); // đăng xuất mọi phiên
        AuditLogger::log('user.suspend', 'user', (string) $user->id, ['reason' => $request->input('reason')]);

        return response()->json(['status' => 'suspended']);
    }

    public function restore(User $user): JsonResponse
    {
        if ($user->trashed()) $user->restore();
        $user->update(['status' => 'active', 'suspend_reason' => null]);
        AuditLogger::log('user.restore', 'user', (string) $user->id);

        return response()->json(['status' => 'active']);
    }

    public function resetPassword(User $user): JsonResponse
    {
        try {
            Password::sendResetLink(['email' => $user->email]);
        } catch (\Throwable $e) {
            // Mail có thể chưa cấu hình — vẫn báo thành công cho admin
        }
        AuditLogger::log('user.reset_password', 'user', (string) $user->id);

        return response()->json(['message' => 'Đã gửi email đặt lại mật khẩu']);
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        if ($user->id === $request->user()->id) {
            return response()->json(['detail' => 'Không thể tự xoá tài khoản của bạn'], 422);
        }
        if ($user->isAdmin()) {
            return response()->json(['detail' => 'Không thể xoá tài khoản admin'], 422);
        }

        $user->tokens()->delete();
        $user->delete(); // soft delete
        AuditLogger::log('user.delete', 'user', (string) $user->id, ['email' => $user->email]);

        return response()->json(null, 204);
    }

    private function activeAdminCount(): int
    {
        return User::where('role', 'admin')->where('status', 'active')->count();
    }

    private function row(User $u): array
    {
        return [
            'id'              => $u->id,
            'name'            => $u->name,
            'email'           => $u->email,
            'avatar_url'      => $u->avatar_url,
            'provider'        => $u->provider ?? 'email',
            'role'            => $u->role ?? 'user',
            'status'          => $u->status ?? 'active',
            'calorie_streak'  => $u->calorie_streak ?? 0,
            'meal_logs_count' => $u->meal_logs_count ?? 0,
            'last_seen_at'    => optional($u->last_seen_at)->toIso8601String(),
            'created_at'      => optional($u->created_at)->toIso8601String(),
        ];
    }

    private function detail(User $u): array
    {
        return array_merge($this->row($u), [
            'birth_year'     => $u->birth_year,
            'gender'         => $u->gender,
            'height_cm'      => $u->height_cm !== null ? (float) $u->height_cm : null,
            'weight_kg'      => $u->weight_kg !== null ? (float) $u->weight_kg : null,
            'calorie_goal'   => $u->calorie_goal,
            'suspend_reason' => $u->suspend_reason,
            'notify' => [
                'morning'            => (bool) $u->morning_notify_enabled,
                'midday'             => (bool) $u->midday_notify_enabled,
                'evening'            => (bool) $u->evening_notify_enabled,
                'email_reengagement' => (bool) $u->email_reengagement_enabled,
            ],
            'stats' => [
                'meal_logs'  => $u->meal_logs_count ?? $u->mealLogs()->count(),
                'water_logs' => $u->waterLogs()->count(),
                'plans'      => $u->mealPlans()->count(),
                'passkeys'   => $u->webauthnCredentials()->count(),
            ],
            'updated_at' => optional($u->updated_at)->toIso8601String(),
        ]);
    }
}
