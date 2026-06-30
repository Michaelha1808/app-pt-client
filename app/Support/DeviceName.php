<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Suy ra nhãn thiết bị thân thiện + loại thiết bị từ User-Agent.
 * Dùng để đặt tên token đăng nhập (personal_access_tokens.name) → trang admin
 * hiển thị được "thiết bị đăng nhập" mà không cần thêm bảng/migration.
 */
class DeviceName
{
    /** Tên token mặc định cũ (trước khi có nhãn thiết bị) — coi như không xác định. */
    public const LEGACY = 'auth_token';

    public const UNKNOWN = 'Thiết bị không xác định';

    public static function fromRequest(Request $request): string
    {
        return self::label($request->userAgent());
    }

    /** "Windows · Chrome", "iPhone · Safari"… */
    public static function label(?string $ua): string
    {
        $ua = trim((string) $ua);
        if ($ua === '') return self::UNKNOWN;

        $os      = self::os($ua);
        $browser = self::browser($ua);

        $label = trim($os . ($browser ? ' · ' . $browser : ''));

        return $label !== '' ? $label : self::UNKNOWN;
    }

    /** Nhãn đẹp để hiển thị (đổi tên token legacy thành "không xác định"). */
    public static function display(?string $name): string
    {
        $name = trim((string) $name);
        if ($name === '' || $name === self::LEGACY) return self::UNKNOWN;

        return $name;
    }

    /** ios | android | web — khớp enum của notification_subscriptions. */
    public static function deviceType(?string $ua): string
    {
        $ua = (string) $ua;
        if (preg_match('/iPhone|iPad|iPod/i', $ua)) return 'ios';
        if (preg_match('/Android/i', $ua))          return 'android';

        return 'web';
    }

    private static function os(string $ua): string
    {
        return match (true) {
            (bool) preg_match('/iPhone/i', $ua)                 => 'iPhone',
            (bool) preg_match('/iPad/i', $ua)                   => 'iPad',
            (bool) preg_match('/iPod/i', $ua)                   => 'iPod',
            (bool) preg_match('/Android/i', $ua)                => 'Android',
            (bool) preg_match('/Windows NT/i', $ua)             => 'Windows',
            (bool) preg_match('/Macintosh|Mac OS X/i', $ua)     => 'macOS',
            (bool) preg_match('/CrOS/i', $ua)                   => 'ChromeOS',
            (bool) preg_match('/Linux/i', $ua)                  => 'Linux',
            default                                             => '',
        };
    }

    private static function browser(string $ua): string
    {
        // Thứ tự quan trọng: Edge/Samsung/Opera nhồi cả chuỗi "Chrome";
        // CriOS/FxiOS là Chrome/Firefox trên iOS (vẫn render bằng Safari engine).
        return match (true) {
            (bool) preg_match('/Edg(A|iOS|)?\//i', $ua)         => 'Edge',
            (bool) preg_match('/SamsungBrowser/i', $ua)         => 'Samsung Internet',
            (bool) preg_match('/OPR|Opera/i', $ua)              => 'Opera',
            (bool) preg_match('/CriOS/i', $ua)                  => 'Chrome',
            (bool) preg_match('/FxiOS|Firefox/i', $ua)          => 'Firefox',
            (bool) preg_match('/Chrome/i', $ua)                 => 'Chrome',
            (bool) preg_match('/Safari/i', $ua)                 => 'Safari',
            default                                             => '',
        };
    }
}
