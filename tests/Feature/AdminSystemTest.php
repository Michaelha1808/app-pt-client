<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminSystemTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'status' => 'active']);
    }

    /** Chèn 1 row failed_jobs với payload chuẩn Laravel, trả về uuid. */
    private function insertFailedJob(string $jobName = 'App\\Jobs\\FakeJob', ?string $exception = null): string
    {
        $uuid = (string) Str::uuid();
        DB::table('failed_jobs')->insert([
            'uuid'       => $uuid,
            'connection' => 'database',
            'queue'      => 'default',
            'payload'    => json_encode([
                'uuid'        => $uuid,
                'displayName' => $jobName,
                'job'         => 'Illuminate\\Queue\\CallQueuedHandler@call',
                'maxTries'    => null,
                'backoff'     => null,
                'timeout'     => null,
                // command phải là chuỗi serialize hợp lệ — queue:retry unserialize nó để refresh retryUntil
                'data'        => ['commandName' => $jobName, 'command' => serialize(new \stdClass())],
            ]),
            'exception'  => $exception ?? "RuntimeException: Lỗi giả lập dòng đầu\n#0 /app/Jobs/FakeJob.php(10): stack trace",
            'failed_at'  => now(),
        ]);

        return $uuid;
    }

    public function test_system_endpoints_require_admin(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/admin/system')->assertStatus(403);
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/admin/system/logs')->assertStatus(403);
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/admin/system/cache-clear', ['target' => 'view'])->assertStatus(403);
    }

    public function test_info_returns_environment_and_health(): void
    {
        $res = $this->actingAs($this->admin(), 'sanctum')->getJson('/api/v1/admin/system');

        $res->assertOk()
            ->assertJsonStructure([
                'app'      => ['name', 'env', 'debug', 'timezone', 'laravel', 'php'],
                'server'   => ['os', 'memory_limit', 'server_time'],
                'database' => ['driver'],
                'cache'    => ['driver'],
                'queue'    => ['driver'],
                'storage'  => ['logs_size'],
                'health',
            ]);

        $health = collect($res->json('health'));
        $this->assertTrue($health->firstWhere('name', 'database')['ok']);
        $this->assertTrue($health->firstWhere('name', 'cache')['ok']);
    }

    public function test_cache_clear_validates_target_and_audits(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/admin/system/cache-clear', ['target' => 'nope'])
            ->assertStatus(422);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/admin/system/cache-clear', ['target' => 'view'])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'system.cache_clear']);
    }

    public function test_logs_returns_recent_entries_with_level_filter(): void
    {
        // Ghi log thật vào storage/logs rồi đọc lại qua API
        Log::error('SystemTest lỗi giả lập');
        Log::info('SystemTest info giả lập');

        $admin = $this->admin();

        $res = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/system/logs?lines=50');
        $res->assertOk();
        $this->assertNotEmpty($res->json('entries'));

        $errors = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/admin/system/logs?lines=50&level=error')
            ->json('entries');
        foreach ($errors as $entry) {
            $this->assertSame('error', $entry['level']);
        }
    }

    // ── Failed jobs ──────────────────────────────────────────────────

    public function test_failed_jobs_endpoints_require_admin(): void
    {
        $user = User::factory()->create();
        $uuid = $this->insertFailedJob();

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/admin/system/failed-jobs')->assertStatus(403);
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/admin/system/failed-jobs/retry')->assertStatus(403);
        $this->actingAs($user, 'sanctum')->deleteJson("/api/v1/admin/system/failed-jobs/{$uuid}")->assertStatus(403);
    }

    public function test_failed_jobs_list_returns_paginated_with_parsed_fields(): void
    {
        $uuid = $this->insertFailedJob('App\\Jobs\\SendPushJob');

        $res = $this->actingAs($this->admin(), 'sanctum')->getJson('/api/v1/admin/system/failed-jobs');

        $res->assertOk()
            ->assertJsonStructure([
                'data' => [['id', 'uuid', 'connection', 'queue', 'job_name', 'exception_excerpt', 'failed_at']],
                'meta' => ['current_page', 'per_page', 'total', 'last_page'],
            ])
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.uuid', $uuid)
            ->assertJsonPath('data.0.job_name', 'App\\Jobs\\SendPushJob')
            ->assertJsonPath('data.0.exception_excerpt', 'RuntimeException: Lỗi giả lập dòng đầu');
    }

    public function test_failed_jobs_job_name_falls_back_to_uuid_and_excerpt_is_truncated(): void
    {
        $uuid = (string) Str::uuid();
        DB::table('failed_jobs')->insert([
            'uuid'       => $uuid,
            'connection' => 'database',
            'queue'      => 'default',
            'payload'    => 'không phải json',
            'exception'  => str_repeat('A', 900) . "\ndòng hai",
            'failed_at'  => now(),
        ]);

        $row = $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/admin/system/failed-jobs')
            ->json('data.0');

        $this->assertSame($uuid, $row['job_name']);
        $this->assertSame(500, mb_strlen($row['exception_excerpt']));
    }

    public function test_retry_single_failed_job_pushes_back_to_queue_and_audits(): void
    {
        $uuid  = $this->insertFailedJob();
        $other = $this->insertFailedJob();

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/admin/system/failed-jobs/retry', ['uuid' => $uuid])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('retried', 1);

        // Job được đưa lại vào bảng jobs, xoá khỏi failed_jobs; job kia giữ nguyên
        $this->assertDatabaseMissing('failed_jobs', ['uuid' => $uuid]);
        $this->assertDatabaseHas('failed_jobs', ['uuid' => $other]);
        $this->assertSame(1, DB::table('jobs')->count());

        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'system.failed_jobs_retry']);
    }

    public function test_retry_all_failed_jobs(): void
    {
        $this->insertFailedJob();
        $this->insertFailedJob();

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/admin/system/failed-jobs/retry')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('retried', 2);

        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->assertSame(2, DB::table('jobs')->count());
    }

    public function test_retry_unknown_uuid_returns_404(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/admin/system/failed-jobs/retry', ['uuid' => 'khong-ton-tai'])
            ->assertStatus(404)
            ->assertJsonPath('ok', false);
    }

    public function test_delete_failed_job_and_audits(): void
    {
        $uuid = $this->insertFailedJob();

        $this->actingAs($this->admin(), 'sanctum')
            ->deleteJson("/api/v1/admin/system/failed-jobs/{$uuid}")
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertDatabaseMissing('failed_jobs', ['uuid' => $uuid]);
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'system.failed_jobs_delete']);

        // Xoá lần 2 → 404
        $this->actingAs($this->admin(), 'sanctum')
            ->deleteJson("/api/v1/admin/system/failed-jobs/{$uuid}")
            ->assertStatus(404);
    }
}
