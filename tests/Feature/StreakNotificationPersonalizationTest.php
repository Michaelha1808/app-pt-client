<?php

namespace Tests\Feature;

use App\Models\NotificationLog;
use App\Models\User;
use App\Models\UserStreak;
use App\Services\StreakService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Messaging\MulticastSendReport;
use Tests\TestCase;

/**
 * Trước đây nội dung thông báo streak-risk/milestone là chuỗi tĩnh giống hệt nhau cho mọi
 * user. Test này xác nhận nội dung giờ có chèn số liệu thật (current_streak/best_streak,
 * cột mốc tiếp theo).
 *
 * Container tự resolve Kreait\Firebase\Contract\Messaging khi khởi tạo FcmService — việc này
 * đọc file credential thật (storage/app/firebase/service_account.json) không tồn tại ở máy
 * local, nên phải bind mock để test chạy được mà không cần Firebase thật.
 */
class StreakNotificationPersonalizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->bind(Messaging::class, fn () => \Mockery::mock(Messaging::class));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_streak_risk_reminder_mentions_personal_best_when_below_it(): void
    {
        Carbon::setTestNow(now()->setTime(21, 0));
        $user = User::factory()->create();
        UserStreak::create([
            'user_id' => $user->id, 'current_streak' => 3, 'best_streak' => 10,
            'last_activity_date' => today()->subDay(),
        ]);

        $this->artisan('notify:streak-risk');

        $log = NotificationLog::where('user_id', $user->id)->where('type', 'streak_risk')->first();
        $this->assertNotNull($log);
        $this->assertStringContainsString('kỷ lục 10 ngày', $log->body);
    }

    public function test_streak_risk_reminder_says_personal_best_when_at_or_above_it(): void
    {
        Carbon::setTestNow(now()->setTime(21, 0));
        $user = User::factory()->create();
        UserStreak::create([
            'user_id' => $user->id, 'current_streak' => 12, 'best_streak' => 12,
            'last_activity_date' => today()->subDay(),
        ]);

        $this->artisan('notify:streak-risk');

        $log = NotificationLog::where('user_id', $user->id)->where('type', 'streak_risk')->first();
        $this->assertNotNull($log);
        $this->assertStringContainsString('DÀI NHẤT', $log->body);
    }

    public function test_streak_risk_reminder_skips_users_not_at_risk_or_already_sent(): void
    {
        Carbon::setTestNow(now()->setTime(21, 0));
        $user = User::factory()->create();
        // Đã log hôm nay rồi → không ở diện "sắp gián đoạn"
        UserStreak::create([
            'user_id' => $user->id, 'current_streak' => 5, 'best_streak' => 5,
            'last_activity_date' => today(),
        ]);

        $this->artisan('notify:streak-risk');

        $this->assertDatabaseCount('notification_logs', 0);
    }

    /**
     * StreakService::sendMilestonePush() không ghi NotificationLog (khác Console/Commands/
     * Notifications/* dùng DispatchesUserPush) — gọi thẳng FcmService::sendMulticast(), nên
     * cách duy nhất xác nhận đúng nội dung push là bắt tham số CloudMessage qua mock Messaging.
     * Cần tạo NotificationSubscription giả để FcmService không return sớm vì tokens rỗng.
     */
    /**
     * Trả về object (không phải array) chủ ý — PHP array trả bằng giá trị nên nếu return
     * mảng rỗng rồi mutate qua closure "use (&$captured)" thì biến ở nơi gọi vẫn là bản
     * snapshot rỗng lúc return, tách rời khỏi mảng thật sự bị closure sửa sau đó. Object thì
     * luôn cùng 1 tham chiếu ở cả hai nơi, không dính bẫy này.
     */
    private function capturedPushBody(): object
    {
        $captured = new \stdClass();
        $captured->body = null;

        $mock = \Mockery::mock(Messaging::class);
        $mock->shouldReceive('sendMulticast')
            ->once()
            ->andReturnUsing(function ($message, $tokens) use ($captured) {
                $captured->body = $message->jsonSerialize()['data']['body'] ?? null;
                return MulticastSendReport::withItems([]);
            });
        $this->app->bind(Messaging::class, fn () => $mock);

        return $captured;
    }

    public function test_milestone_push_body_mentions_next_milestone_with_correct_remaining_days(): void
    {
        $user = User::factory()->create();
        $user->notificationSubscriptions()->create(['fcm_token' => 'fake-token', 'device_type' => 'web']);
        $captured = $this->capturedPushBody();

        // 1 ngày trước mốc 7 → recordActivity() hôm nay sẽ chạm mốc 7 (mốc kế tiếp là 14)
        UserStreak::create([
            'user_id' => $user->id, 'current_streak' => 6, 'best_streak' => 6,
            'last_activity_date' => today()->subDay(),
        ]);

        app(StreakService::class)->recordActivity($user->fresh(['notificationSubscriptions']));

        $this->assertNotNull($captured->body);
        $this->assertStringContainsString('Cột mốc tiếp theo: 14 ngày', $captured->body);
        $this->assertStringContainsString('còn 7 ngày nữa', $captured->body);
    }

    public function test_milestone_push_body_has_no_next_milestone_hint_at_the_last_one(): void
    {
        $user = User::factory()->create();
        $user->notificationSubscriptions()->create(['fcm_token' => 'fake-token', 'device_type' => 'web']);
        $captured = $this->capturedPushBody();

        // 1 ngày trước mốc 100 (mốc cuối cùng) → không còn mốc nào sau đó
        UserStreak::create([
            'user_id' => $user->id, 'current_streak' => 99, 'best_streak' => 99,
            'last_activity_date' => today()->subDay(),
        ]);

        app(StreakService::class)->recordActivity($user->fresh(['notificationSubscriptions']));

        $this->assertNotNull($captured->body);
        $this->assertStringNotContainsString('Cột mốc tiếp theo', $captured->body);
    }
}
