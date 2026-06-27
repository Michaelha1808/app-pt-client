<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
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

    public function updateProfile(Request $request)
    {
        $request->validate([
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

        return response()->json([
            'user' => $this->formatUser($user->fresh()),
        ]);
    }

    public function uploadAvatar(Request $request)
    {
        $request->validate([
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

    public function changePassword(Request $request)
    {
        $request->validate([
            'current_password' => 'required',
            'new_password'     => 'required|min:8',
        ]);

        $user = $request->user();

        if (!\Illuminate\Support\Facades\Hash::check($request->current_password, $user->password)) {
            return response()->json(['detail' => 'Mật khẩu hiện tại không đúng'], 422);
        }

        $user->update(['password' => \Illuminate\Support\Facades\Hash::make($request->new_password)]);

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
