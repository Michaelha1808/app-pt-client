<?php

namespace App\Console\Commands\Notifications;

use App\Jobs\SendBroadcastNotification;
use App\Models\NotificationCampaign;
use App\Services\SettingsService;
use App\Support\NotificationAudience;
use Illuminate\Console\Command;

/**
 * Gửi thông báo "đã có bản cập nhật {version}" tới TOÀN BỘ user.
 *
 * Chạy tự động ở bước deploy (SSH vào VPS → docker exec) ngay sau khi merge/deploy
 * lên main. Version lấy từ git tag mới nhất (CI truyền vào tham số `version`).
 *
 * Chống gửi trùng: lưu `app.announced_version` qua SettingsService — cùng version
 * thì bỏ qua. Chỉ lưu lại sau khi tạo chiến dịch thành công → nếu bước gửi lỗi,
 * lần deploy sau (cùng tag) sẽ tự thử lại.
 */
class AnnounceUpdate extends Command
{
    protected $signature = 'notify:announce-update
        {version? : Phiên bản cần thông báo (vd v1.2.3). Bỏ trống → lấy git tag mới nhất}
        {--title= : Tiêu đề tuỳ chỉnh (mặc định theo version)}
        {--body= : Nội dung tuỳ chỉnh (mặc định theo version)}
        {--force : Gửi lại kể cả khi version trùng lần trước}';

    protected $description = 'Thông báo bản cập nhật (version) tới toàn bộ user — chạy tự động sau khi deploy main.';

    public function handle(SettingsService $settings): int
    {
        $version = trim((string) ($this->argument('version') ?: $this->latestGitTag()));

        if ($version === '') {
            $this->warn('Không xác định được version (không có git tag & không truyền tham số) → bỏ qua.');
            return self::SUCCESS;
        }

        $last = $settings->get('app.announced_version');
        if (! $this->option('force') && $last === $version) {
            $this->info("Version {$version} đã thông báo trước đó → bỏ qua (dùng --force để gửi lại).");
            return self::SUCCESS;
        }

        $audience = NotificationAudience::query(['audience' => 'all'])->count();
        if ($audience === 0) {
            $this->warn('Không có user nào để gửi → chỉ ghi nhận version, bỏ qua gửi.');
            $settings->set('app.announced_version', $version);
            return self::SUCCESS;
        }

        $title = $this->option('title') ?: "Đã có bản cập nhật {$version} 🎉";
        $body  = $this->option('body')
            ?: "CaloEye vừa lên phiên bản {$version} với nhiều cải tiến. Mở app để trải nghiệm nhé!";

        $campaign = NotificationCampaign::create([
            'admin_id'       => null,   // do hệ thống (CI) gửi, không phải admin cụ thể
            'title'          => $title,
            'body'           => $body,
            'url'            => '/home',
            'segment'        => ['audience' => 'all'],
            'audience_count' => $audience,
            'status'         => 'queued',
        ]);

        SendBroadcastNotification::dispatch($campaign->id);

        // Chỉ ghi nhận sau khi đã tạo campaign + đẩy job → deploy lỗi giữa chừng vẫn thử lại được.
        $settings->set('app.announced_version', $version);

        $this->info("Đã tạo chiến dịch #{$campaign->id}: thông báo version {$version} tới {$audience} user.");
        return self::SUCCESS;
    }

    /** Git tag mới nhất (best-effort) — dùng khi CI không truyền version. */
    private function latestGitTag(): ?string
    {
        $out  = [];
        $code = 1;
        @exec('git -C ' . escapeshellarg(base_path()) . ' describe --tags --abbrev=0 2>/dev/null', $out, $code);

        return $code === 0 && ! empty($out[0]) ? trim($out[0]) : null;
    }
}
