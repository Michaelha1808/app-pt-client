<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'status' => 'active']);
    }

    // ── Quyền truy cập ──────────────────────────────────────────────

    public function test_settings_requires_admin_role(): void
    {
        $this->getJson('/api/v1/admin/settings')->assertStatus(401);

        $user = User::factory()->create();
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/admin/settings')->assertStatus(403);
    }

    // ── Đọc & ghi ───────────────────────────────────────────────────

    public function test_index_returns_defaults_with_masked_secret(): void
    {
        app(SettingsService::class)->set('ai.api_key', 'AIzaSecretKey123456');

        $res = $this->actingAs($this->admin(), 'sanctum')->getJson('/api/v1/admin/settings');

        $res->assertOk()
            ->assertJsonPath('ai.provider', 'gemini')
            ->assertJsonPath('features.maintenance_mode', false);

        $this->assertStringContainsString('•', $res->json('ai.api_key'));
        $this->assertStringNotContainsString('SecretKey', $res->json('ai.api_key'));
    }

    public function test_update_persists_values_per_group(): void
    {
        $res = $this->actingAs($this->admin(), 'sanctum')->putJson('/api/v1/admin/settings', [
            'ai'       => ['model' => 'gemini-2.5-flash', 'temperature' => 1.2, 'max_tokens' => 4096],
            'features' => ['registration_open' => false],
        ]);

        $res->assertOk()
            ->assertJsonPath('ai.model', 'gemini-2.5-flash')
            ->assertJsonPath('features.registration_open', false);

        $settings = app(SettingsService::class);
        $this->assertSame('gemini-2.5-flash', $settings->get('ai.model'));
        $this->assertSame(4096, $settings->get('ai.max_tokens'));
        $this->assertFalse($settings->get('features.registration_open'));
    }

    /** Bug cũ: rule key "group.name" bị validator bỏ qua với mảng phẳng → mọi giá trị rác đều lọt. */
    public function test_update_rejects_invalid_values(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'sanctum')->putJson('/api/v1/admin/settings', [
            'ai' => ['max_tokens' => 99999],
        ])->assertStatus(422);

        $this->actingAs($admin, 'sanctum')->putJson('/api/v1/admin/settings', [
            'ai' => ['temperature' => 9],
        ])->assertStatus(422);

        $this->actingAs($admin, 'sanctum')->putJson('/api/v1/admin/settings', [
            'notifications' => ['morning_default' => 'xx:99'],
        ])->assertStatus(422);

        // Không có gì được lưu
        $this->assertSame(2048, app(SettingsService::class)->get('ai.max_tokens'));
    }

    public function test_update_ignores_masked_or_empty_secret(): void
    {
        $settings = app(SettingsService::class);
        $settings->set('ai.api_key', 'AIzaRealKey1234567890');

        $admin = $this->admin();

        // Gửi lại chuỗi đã mask (UI không đổi) → giữ nguyên key cũ
        $masked = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/settings')->json('ai.api_key');
        $this->actingAs($admin, 'sanctum')->putJson('/api/v1/admin/settings', [
            'ai' => ['api_key' => $masked],
        ])->assertOk();
        $this->assertSame('AIzaRealKey1234567890', $settings->get('ai.api_key'));

        // Gửi rỗng → giữ nguyên
        $this->actingAs($admin, 'sanctum')->putJson('/api/v1/admin/settings', [
            'ai' => ['api_key' => ''],
        ])->assertOk();
        $this->assertSame('AIzaRealKey1234567890', $settings->get('ai.api_key'));

        // Gửi key mới → cập nhật
        $this->actingAs($admin, 'sanctum')->putJson('/api/v1/admin/settings', [
            'ai' => ['api_key' => 'AIzaNewKey000111222333'],
        ])->assertOk();
        $this->assertSame('AIzaNewKey000111222333', $settings->get('ai.api_key'));
    }

    public function test_update_writes_audit_log(): void
    {
        $this->actingAs($this->admin(), 'sanctum')->putJson('/api/v1/admin/settings', [
            'features' => ['guest_mode_enabled' => false],
        ])->assertOk();

        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'settings.update']);
    }

    public function test_update_audit_records_old_to_new_changes(): void
    {
        app(SettingsService::class)->set('ai.model', 'gemini-2.0-flash');

        $this->actingAs($this->admin(), 'sanctum')->putJson('/api/v1/admin/settings', [
            'ai' => ['model' => 'gemini-2.5-flash'],
        ])->assertOk();

        $log = \App\Models\AdminAuditLog::where('action', 'settings.update')->latest('id')->firstOrFail();

        $this->assertSame('gemini-2.0-flash', $log->meta['changes']['ai.model']['from']);
        $this->assertSame('gemini-2.5-flash', $log->meta['changes']['ai.model']['to']);
    }

    public function test_update_audit_never_contains_raw_secret(): void
    {
        app(SettingsService::class)->set('ai.api_key', 'AIzaOldSecret1234567');

        $this->actingAs($this->admin(), 'sanctum')->putJson('/api/v1/admin/settings', [
            'ai' => ['api_key' => 'AIzaNewSecret7654321'],
        ])->assertOk();

        $log = \App\Models\AdminAuditLog::where('action', 'settings.update')->latest('id')->firstOrFail();

        $change = $log->meta['changes']['ai.api_key'];
        $this->assertSame('(đã đặt)', $change['from']);
        $this->assertSame('(đã thay đổi)', $change['to']);

        // Giá trị raw (cũ lẫn mới) không được xuất hiện ở bất kỳ đâu trong meta
        $encoded = json_encode($log->meta, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('AIzaOldSecret1234567', $encoded);
        $this->assertStringNotContainsString('AIzaNewSecret7654321', $encoded);
    }

    // ── Settings thực sự có hiệu lực ────────────────────────────────

    public function test_registration_closed_blocks_register(): void
    {
        app(SettingsService::class)->set('features.registration_open', false);

        $this->postJson('/api/v1/auth/register', [
            'email' => 'new@test.dev', 'password' => 'Password1',
            'name' => 'Người mới', 'birth_year' => 1995, 'gender' => 'male',
            'height_cm' => 170, 'weight_kg' => 65, 'calorie_goal' => 2000,
        ])->assertStatus(403);
    }

    public function test_maintenance_mode_blocks_non_admin_but_not_admin(): void
    {
        app(SettingsService::class)->set('features.maintenance_mode', true);

        $user = User::factory()->create();
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/user/profile')
            ->assertStatus(503)
            ->assertJsonPath('code', 'maintenance');

        $this->actingAs($this->admin(), 'sanctum')->getJson('/api/v1/admin/settings')->assertOk();

        // /config vẫn mở để FE hiển thị trạng thái
        $this->getJson('/api/v1/config')->assertOk()
            ->assertJsonPath('features.maintenance_mode', true);
    }

    public function test_chat_disabled_returns_503(): void
    {
        app(SettingsService::class)->set('ai.chat_enabled', false);

        $this->postJson('/api/v1/chat', ['messages' => [['role' => 'user', 'text' => 'xin chào']]])
            ->assertStatus(503)
            ->assertJsonPath('code', 'feature_disabled');
    }

    public function test_food_analysis_disabled_returns_503(): void
    {
        app(SettingsService::class)->set('ai.food_analysis_enabled', false);

        $this->postJson('/api/v1/food/analyze', ['text' => 'phở bò'])
            ->assertStatus(503)
            ->assertJsonPath('code', 'feature_disabled');

        $this->postJson('/api/v1/food/detect', ['text' => 'cơm tấm'])
            ->assertStatus(503);
    }

    public function test_dynamic_rate_limit_reads_settings(): void
    {
        app(SettingsService::class)->set('ai.chat_enabled', false); // chặn sớm, không gọi Gemini thật
        app(SettingsService::class)->set('rate_limit.chat_per_min', 2);

        $payload = ['messages' => [['role' => 'user', 'text' => 'hi']]];
        $this->postJson('/api/v1/chat', $payload)->assertStatus(503);
        $this->postJson('/api/v1/chat', $payload)->assertStatus(503);
        $this->postJson('/api/v1/chat', $payload)->assertStatus(429); // vượt limit 2/phút
    }

    public function test_new_user_gets_default_notify_times_from_settings(): void
    {
        app(SettingsService::class)->set('notifications.morning_default', '06:30');
        app(SettingsService::class)->set('notifications.evening_default', '20:15');

        $this->postJson('/api/v1/auth/register', [
            'email' => 'times@test.dev', 'password' => 'Password1',
            'name' => 'Giờ nhắc', 'birth_year' => 1995, 'gender' => 'female',
            'height_cm' => 160, 'weight_kg' => 50, 'calorie_goal' => 1800,
        ])->assertStatus(201);

        $user = User::where('email', 'times@test.dev')->first();
        $this->assertStringStartsWith('06:30', (string) $user->morning_notify);
        $this->assertStringStartsWith('20:15', (string) $user->evening_notify);
    }

    // ── Public config ───────────────────────────────────────────────

    public function test_public_config_exposes_flags_without_secrets(): void
    {
        app(SettingsService::class)->setMany([
            'oauth.google_enabled' => false,
            'features.guest_mode_enabled' => false,
        ]);

        $res = $this->getJson('/api/v1/config');

        $res->assertOk()
            ->assertJsonPath('oauth.google_enabled', false)
            ->assertJsonPath('features.guest_mode_enabled', false)
            ->assertJsonPath('features.registration_open', true);

        $this->assertStringNotContainsString('api_key', $res->getContent());
    }
}
