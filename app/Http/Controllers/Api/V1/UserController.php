<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\WeightService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class UserController extends Controller
{
    public function profile(Request $request)
    {
        return response()->json([
            'user' => $this->formatUser($request->user()),
        ]);
    }

    // DEFENSE: endpoint cập nhật hồ sơ — sửa profile fields, tự ghi WeightLog nếu đổi cân nặng
    public function updateProfile(Request $request, WeightService $weightService)
    {
        $data = $request->validate([
            // DEFENSE: validate profile edit — cùng khoảng với register (nới/siết ở đây và AuthController::register)
            'name'           => 'sometimes|string|min:2|max:100',
            'birth_year'     => 'sometimes|integer|between:1900,2015',
            'gender'         => 'sometimes|in:male,female,other',
            'height_cm'      => 'sometimes|numeric|between:50,300',
            'weight_kg'      => 'sometimes|numeric|between:20,500',
            'calorie_goal'   => 'sometimes|integer|between:1000,5000',
            'morning_notify' => 'sometimes|date_format:H:i',
            'evening_notify' => 'sometimes|date_format:H:i',
        ]);

        $user = $request->user();
        $user->update($request->only([
            'name', 'birth_year', 'gender', 'height_cm', 'weight_kg',
            'calorie_goal', 'morning_notify', 'evening_notify',
        ]));

        // DEFENSE: đổi cân nặng → tự log WeightLog — giữ nhật ký cân theo profile edit
        if (array_key_exists('weight_kg', $data)) {
            $weightService->logWeight($user, (float) $data['weight_kg']);
        }

        return response()->json([
            'user' => $this->formatUser($user->fresh()),
        ]);
    }

    // DEFENSE: endpoint upload avatar — lưu file vào Storage disk 'public', xoá file cũ nếu có
    public function uploadAvatar(Request $request)
    {
        $request->validate([
            // DEFENSE: giới hạn avatar — jpeg/png/webp, tối đa 5MB (max:5120 KB)
            'avatar' => 'required|image|mimes:jpeg,png,webp|max:5120',
        ]);

        $user = $request->user();

        // Remove old avatar file if it was stored locally
        if ($user->avatar_url) {
            $oldPath = $this->localPathFromUrl($user->avatar_url);
            if ($oldPath) Storage::disk('public')->delete($oldPath);
        }

        $ext      = $request->file('avatar')->extension();
        $filename = 'avatars/' . Str::uuid() . '.' . $ext;
        $request->file('avatar')->storeAs('', $filename, 'public');

        $url = Storage::disk('public')->url($filename);
        $user->update(['avatar_url' => $url]);

        return response()->json(['avatar_url' => $url]);
    }

    // DEFENSE: endpoint đổi mật khẩu — check current_password, hash new_password rồi update
    public function changePassword(Request $request)
    {
        $request->validate([
            'current_password' => 'required',
            // DEFENSE: mật khẩu mới tối thiểu — min:8 khi đổi password (đồng bộ với register)
            'new_password'     => 'required|min:8',
        ]);

        $user = $request->user();

        if (!\Illuminate\Support\Facades\Hash::check($request->current_password, $user->password)) {
            // DEFENSE: text lỗi password hiện tại sai — hiển thị khi Hash::check fail
            return response()->json(['detail' => 'Mật khẩu hiện tại không đúng'], 422);
        }

        $user->update(['password' => \Illuminate\Support\Facades\Hash::make($request->new_password)]);

        // DEFENSE: text đổi mật khẩu thành công
        return response()->json(['message' => 'Đã đổi mật khẩu thành công']);
    }

    public function deleteAvatar(Request $request)
    {
        $user = $request->user();

        if ($user->avatar_url) {
            $oldPath = $this->localPathFromUrl($user->avatar_url);
            if ($oldPath) Storage::disk('public')->delete($oldPath);
            $user->update(['avatar_url' => null]);
        }

        return response()->noContent();
    }

    private function formatUser($user): array
    {
        return [
            'id'             => (string) $user->id,
            'email'          => $user->email,
            'name'           => $user->name,
            'avatar_url'     => $user->avatar_url,
            'provider'       => 'email',
            'email_verified' => $user->email_verified_at !== null,
            'birth_year'     => $user->birth_year,
            'gender'         => $user->gender,
            'height_cm'      => $user->height_cm !== null ? (float) $user->height_cm : null,
            'weight_kg'      => $user->weight_kg !== null ? (float) $user->weight_kg : null,
            'calorie_goal'   => $user->calorie_goal,
            'morning_notify' => $user->morning_notify ? substr($user->morning_notify, 0, 5) : null,
            'evening_notify' => $user->evening_notify ? substr($user->evening_notify, 0, 5) : null,
            'calorie_streak' => $user->streak?->current_streak ?? 0,
        ];
    }

    // Extract relative storage path from an absolute URL (locally stored files only)
    private function localPathFromUrl(?string $url): ?string
    {
        if (!$url) return null;
        $publicUrl = Storage::disk('public')->url('');
        if (str_starts_with($url, $publicUrl)) {
            return ltrim(substr($url, strlen($publicUrl)), '/');
        }
        return null;
    }
}
