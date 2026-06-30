<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\DeviceName;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Socialite\Facades\Socialite;

class AuthController extends Controller
{
    /** Thời hạn cookie refresh_token (phút). Gia hạn lăn mỗi lần /auth/refresh. */
    private const REFRESH_TOKEN_MINUTES = 60 * 24 * 30; // 30 ngày

    public function register(Request $request)
    {
        if (app(\App\Services\SettingsService::class)->get('features.registration_open', true) !== true) {
            return response()->json(['detail' => 'Đăng ký tài khoản hiện đang tạm khoá.'], 403);
        }

        $request->validate([
            'email'        => 'required|email',
            'password'     => 'required|min:8',
            'name'         => 'required|min:2|max:100',
            'birth_year'   => 'required|integer|between:1900,2015',
            'gender'       => 'required|in:male,female,other',
            'height_cm'    => 'required|numeric|between:50,300',
            'weight_kg'    => 'required|numeric|between:20,500',
            'calorie_goal' => 'required|integer|between:1000,5000',
        ]);

        if (User::where('email', $request->email)->exists()) {
            return response()->json(['detail' => 'Email này đã được đăng ký'], 409);
        }

        $user = User::create([
            'name'         => $request->name,
            'email'        => $request->email,
            'password'     => Hash::make($request->password),
            'birth_year'   => $request->birth_year,
            'gender'       => $request->gender,
            'height_cm'    => $request->height_cm,
            'weight_kg'    => $request->weight_kg,
            'calorie_goal' => $request->calorie_goal ?? 2000,
        ]);

        $token = $user->createToken(DeviceName::fromRequest($request))->plainTextToken;

        return response()->json([
            'access_token' => $token,
            'token_type'   => 'Bearer',
            'user'         => $this->formatUser($user),
        ], 201)->cookie('refresh_token', $token, self::REFRESH_TOKEN_MINUTES, '/', null, false, true, false, 'Lax');
    }

    public function login(Request $request)
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required',
        ]);

        if (!Auth::attempt($request->only('email', 'password'))) {
            return response()->json(['detail' => 'Email hoặc mật khẩu không đúng'], 401);
        }

        $user = Auth::user();
        $token = $user->createToken(DeviceName::fromRequest($request))->plainTextToken;

        return response()->json([
            'access_token' => $token,
            'token_type'   => 'Bearer',
            'user'         => $this->formatUser($user),
        ])->cookie('refresh_token', $token, self::REFRESH_TOKEN_MINUTES, '/', null, false, true, false, 'Lax');
    }

    public function me(Request $request)
    {
        return response()->json([
            'user' => $this->formatUser($request->user()),
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->noContent()
            ->withoutCookie('refresh_token');
    }

    public function refresh(Request $request)
    {
        $refreshToken = $request->cookie('refresh_token');

        if (!$refreshToken) {
            return response()->json(['detail' => 'Phiên đăng nhập đã hết hạn'], 401);
        }

        $personalToken = PersonalAccessToken::findToken($refreshToken);

        if (!$personalToken) {
            return response()->json(['detail' => 'Phiên đăng nhập đã hết hạn'], 401);
        }

        $user = $personalToken->tokenable;

        // Giữ nguyên nhãn thiết bị qua mỗi lần refresh (token cũ xoá, token mới
        // mang lại đúng tên) — nếu là token legacy thì gán nhãn theo UA hiện tại.
        $deviceName = $personalToken->name === DeviceName::LEGACY || $personalToken->name === ''
            ? DeviceName::fromRequest($request)
            : $personalToken->name;

        $personalToken->delete();

        $newToken = $user->createToken($deviceName)->plainTextToken;

        return response()->json([
            'access_token' => $newToken,
        ])->cookie('refresh_token', $newToken, self::REFRESH_TOKEN_MINUTES, '/', null, false, true, false, 'Lax');
    }

    public function googleRedirect(Request $request)
    {
        $redirectUri = $request->query('redirect_uri', env('FRONTEND_URL') . '/auth/callback');

        return Socialite::driver('google')
            ->stateless()
            ->with(['state' => urlencode($redirectUri)])
            ->redirect();
    }

    public function googleCallback(Request $request)
    {
        try {
            $googleUser = Socialite::driver('google')->stateless()->user();
        } catch (\Exception $e) {
            $frontendUrl = env('FRONTEND_URL', 'http://localhost:3000');
            return redirect($frontendUrl . '/auth/login?error=google_failed');
        }

        $user = User::where('google_id', $googleUser->getId())
            ->orWhere('email', $googleUser->getEmail())
            ->first();

        if ($user) {
            // Link Google account if not already linked
            if (!$user->google_id) {
                $user->update([
                    'google_id'  => $googleUser->getId(),
                    'provider'   => 'google',
                    'avatar_url' => $user->avatar_url ?? $googleUser->getAvatar(),
                ]);
            }
        } else {
            $user = User::create([
                'name'       => $googleUser->getName(),
                'email'      => $googleUser->getEmail(),
                'google_id'  => $googleUser->getId(),
                'provider'   => 'google',
                'avatar_url' => $googleUser->getAvatar(),
                'password'   => null,
            ]);
        }

        $token = $user->createToken(DeviceName::fromRequest($request))->plainTextToken;

        // Decode the redirect_uri from state param
        $state       = $request->query('state', '');
        $redirectUri = $state ? urldecode($state) : env('FRONTEND_URL', 'http://localhost:3000') . '/auth/callback';

        return redirect($redirectUri . '?token=' . $token)
            ->cookie('refresh_token', $token, self::REFRESH_TOKEN_MINUTES, '/', null, false, true, false, 'Lax');
    }

    public function facebookRedirect(Request $request)
    {
        $redirectUri = $request->query('redirect_uri', env('FRONTEND_URL') . '/auth/callback');

        return Socialite::driver('facebook')
            ->stateless()
            ->with(['state' => urlencode($redirectUri)])
            ->redirect();
    }

    public function facebookCallback(Request $request)
    {
        try {
            $fbUser = Socialite::driver('facebook')->stateless()->user();
        } catch (\Exception $e) {
            $frontendUrl = env('FRONTEND_URL', 'http://localhost:3000');
            return redirect($frontendUrl . '/auth/login?error=facebook_failed');
        }

        $user = User::where('facebook_id', $fbUser->getId())
            ->orWhere('email', $fbUser->getEmail())
            ->first();

        if ($user) {
            if (!$user->facebook_id) {
                $user->update([
                    'facebook_id' => $fbUser->getId(),
                    'provider'    => 'facebook',
                    'avatar_url'  => $user->avatar_url ?? $fbUser->getAvatar(),
                ]);
            }
        } else {
            $user = User::create([
                'name'        => $fbUser->getName(),
                'email'       => $fbUser->getEmail(),
                'facebook_id' => $fbUser->getId(),
                'provider'    => 'facebook',
                'avatar_url'  => $fbUser->getAvatar(),
                'password'    => null,
            ]);
        }

        $token = $user->createToken(DeviceName::fromRequest($request))->plainTextToken;

        $state       = $request->query('state', '');
        $redirectUri = $state ? urldecode($state) : env('FRONTEND_URL', 'http://localhost:3000') . '/auth/callback';

        return redirect($redirectUri . '?token=' . $token)
            ->cookie('refresh_token', $token, self::REFRESH_TOKEN_MINUTES, '/', null, false, true, false, 'Lax');
    }

    public function forgotPassword(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        return response()->json([
            'message' => 'Nếu email tồn tại, bạn sẽ nhận được hướng dẫn trong vài phút.',
        ]);
    }

    public function resetPassword(Request $request)
    {
        $request->validate([
            'token'        => 'required',
            'new_password' => 'required|min:8',
        ]);

        return response()->json(['message' => 'Mật khẩu đã được cập nhật']);
    }

    private function formatUser($user): array
    {
        return [
            'id'             => (string) $user->id,
            'email'          => $user->email,
            'name'           => $user->name,
            'avatar_url'     => $user->avatar_url,
            'provider'       => $user->provider ?? 'email',
            'role'           => $user->role ?? 'user',
            'status'         => $user->status ?? 'active',
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
}
