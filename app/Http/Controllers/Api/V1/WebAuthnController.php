<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\WebauthnCredential;
use App\Support\DeviceName;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use lbuchs\WebAuthn\WebAuthn;

class WebAuthnController extends Controller
{
    /** Thời hạn cookie refresh_token (phút) — khớp AuthController. */
    private const REFRESH_TOKEN_MINUTES = 60 * 24 * 30;

    private function webauthn(Request $request): WebAuthn
    {
        // rpId = domain hiện tại (qua trustProxies đọc đúng host ngrok/production)
        return new WebAuthn('CaloEye', $request->getHost());
    }

    private function b64url(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    private function b64urlDecode(string $s): string
    {
        return base64_decode(strtr($s, '-_', '+/'));
    }

    // ── ĐĂNG KÝ passkey (yêu cầu đăng nhập) ────────────────────────────────
    public function registerOptions(Request $request)
    {
        $user      = $request->user();
        $challenge = random_bytes(32);
        Cache::put("webauthn_reg_{$user->id}", base64_encode($challenge), now()->addMinutes(5));

        $exclude = $user->webauthnCredentials()->pluck('credential_id')
            ->map(fn ($id) => ['type' => 'public-key', 'id' => $id])
            ->all();

        return response()->json([
            'challenge' => $this->b64url($challenge),
            'rp'        => ['name' => 'CaloEye', 'id' => $request->getHost()],
            'user'      => [
                'id'          => $this->b64url((string) $user->id),
                'name'        => $user->email ?: 'user',
                'displayName' => $user->name ?: 'CaloEye',
            ],
            'pubKeyCredParams' => [
                ['type' => 'public-key', 'alg' => -7],
                ['type' => 'public-key', 'alg' => -257],
            ],
            'authenticatorSelection' => [
                'authenticatorAttachment' => 'platform',
                'residentKey'             => 'required',  // discoverable → đăng nhập không cần email
                'userVerification'        => 'required',  // bắt buộc vân tay/Face ID
            ],
            'timeout'            => 60000,
            'attestation'        => 'none',
            'excludeCredentials' => $exclude,
        ]);
    }

    public function registerVerify(Request $request)
    {
        $request->validate([
            'clientDataJSON'    => 'required|string',
            'attestationObject' => 'required|string',
        ]);

        $user   = $request->user();
        $stored = Cache::pull("webauthn_reg_{$user->id}");
        if (!$stored) {
            return response()->json(['detail' => 'Phiên đăng ký đã hết hạn. Thử lại.'], 422);
        }

        try {
            $data = $this->webauthn($request)->processCreate(
                $this->b64urlDecode($request->clientDataJSON),
                $this->b64urlDecode($request->attestationObject),
                base64_decode($stored),
                true,  // requireUserVerification
                true,  // requireUserPresent
            );
        } catch (\Throwable $e) {
            return response()->json(['detail' => 'Không thể đăng ký vân tay. Vui lòng thử lại.'], 422);
        }

        $credId = $this->b64url($data->credentialId);
        $user->webauthnCredentials()->updateOrCreate(
            ['credential_id' => $credId],
            ['public_key' => $data->credentialPublicKey, 'counter' => $data->signatureCounter ?? 0],
        );

        return response()->json(['ok' => true]);
    }

    // ── ĐĂNG NHẬP bằng passkey (công khai) ─────────────────────────────────
    public function loginOptions(Request $request)
    {
        $challenge = random_bytes(32);
        $flowId    = (string) Str::uuid();
        Cache::put("webauthn_login_{$flowId}", base64_encode($challenge), now()->addMinutes(5));

        return response()->json([
            'flowId'           => $flowId,
            'challenge'        => $this->b64url($challenge),
            'rpId'             => $request->getHost(),
            'allowCredentials' => [],          // discoverable (usernameless)
            'userVerification' => 'required',
            'timeout'          => 60000,
        ]);
    }

    public function loginVerify(Request $request)
    {
        $request->validate([
            'flowId'            => 'required|string',
            'id'                => 'required|string',
            'clientDataJSON'    => 'required|string',
            'authenticatorData' => 'required|string',
            'signature'         => 'required|string',
        ]);

        $stored = Cache::pull("webauthn_login_{$request->flowId}");
        if (!$stored) {
            return response()->json(['detail' => 'Phiên đăng nhập đã hết hạn. Thử lại.'], 401);
        }

        $cred = WebauthnCredential::where('credential_id', $request->id)->first();
        if (!$cred) {
            return response()->json(['detail' => 'Thiết bị này chưa đăng ký vân tay.'], 401);
        }

        try {
            $wa = $this->webauthn($request);
            $ok = $wa->processGet(
                $this->b64urlDecode($request->clientDataJSON),
                $this->b64urlDecode($request->authenticatorData),
                $this->b64urlDecode($request->signature),
                $cred->public_key,
                base64_decode($stored),
                $cred->counter > 0 ? $cred->counter : null,
                true,  // requireUserVerification
            );
        } catch (\Throwable $e) {
            return response()->json(['detail' => 'Xác thực vân tay thất bại.'], 401);
        }

        if (!$ok) {
            return response()->json(['detail' => 'Xác thực vân tay thất bại.'], 401);
        }

        // Cập nhật counter chống replay
        $newCounter = $wa->getSignatureCounter();
        if ($newCounter !== null) {
            $cred->update(['counter' => $newCounter]);
        }

        $user  = $cred->user;
        $token = $user->createToken(DeviceName::fromRequest($request))->plainTextToken;

        // Trả access_token + đặt refresh cookie (giống các luồng đăng nhập khác).
        // Frontend lấy user qua /auth/me sau khi set token.
        return response()->json(['access_token' => $token, 'token_type' => 'Bearer'])
            ->cookie('refresh_token', $token, self::REFRESH_TOKEN_MINUTES, '/', null, false, true, false, 'Lax');
    }

    // ── QUẢN LÝ ────────────────────────────────────────────────────────────
    /** Có thiết bị này đã đăng ký passkey nào chưa? (cho frontend ẩn/hiện nút) */
    public function status(Request $request)
    {
        return response()->json([
            'enabled' => $request->user()->webauthnCredentials()->exists(),
        ]);
    }

    /** Tắt: xoá toàn bộ passkey của user. */
    public function disable(Request $request)
    {
        $request->user()->webauthnCredentials()->delete();
        return response()->json(['ok' => true]);
    }
}
