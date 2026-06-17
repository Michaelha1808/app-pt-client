<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    public function register(Request $request)
    {
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
            'name'     => $request->name,
            'email'    => $request->email,
            'password' => Hash::make($request->password),
        ]);

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'access_token' => $token,
            'token_type'   => 'Bearer',
            'user'         => $this->formatUser($user),
        ], 201)->cookie('refresh_token', $token, 60 * 24 * 7, '/', null, false, true, false, 'Lax');
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
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'access_token' => $token,
            'token_type'   => 'Bearer',
            'user'         => $this->formatUser($user),
        ])->cookie('refresh_token', $token, 60 * 24 * 7, '/', null, false, true, false, 'Lax');
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
        $personalToken->delete();

        $newToken = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'access_token' => $newToken,
        ])->cookie('refresh_token', $newToken, 60 * 24 * 7, '/', null, false, true, false, 'Lax');
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
            'id'         => (string) $user->id,
            'email'      => $user->email,
            'name'       => $user->name,
            'avatar_url' => null,
            'provider'   => 'email',
        ];
    }
}
