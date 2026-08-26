<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Admin phải nhìn thấy được và khôi phục được tài khoản đã soft-delete.
 *
 * Bug: Admin\UserController::index dùng User::query() (SoftDeletes global scope lọc mất trashed)
 * và route model binding trên /users/{user}/restore mặc định cũng 404 trashed → chức năng restore
 * đã tồn tại từ trước NHƯNG không admin nào chạm tới được. Sau fix: index có filter
 * `status=deleted` (onlyTrashed), show/restore route dùng ->withTrashed().
 */
class AdminUserRestoreTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'status' => 'active']);
    }

    public function test_index_hides_trashed_users_by_default(): void
    {
        $admin = $this->admin();
        User::factory()->create(['email' => 'active@example.com']);
        $trashed = User::factory()->create(['email' => 'gone@example.com']);
        $trashed->delete();

        $res = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/users');

        $res->assertOk();
        $emails = collect($res->json('data'))->pluck('email');
        $this->assertTrue($emails->contains('active@example.com'));
        $this->assertFalse($emails->contains('gone@example.com'));
    }

    public function test_index_with_status_deleted_returns_only_trashed(): void
    {
        $admin = $this->admin();
        User::factory()->create(['email' => 'active@example.com']);
        $trashed = User::factory()->create(['email' => 'gone@example.com']);
        $trashed->delete();

        $res = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/users?status=deleted');

        $res->assertOk();
        $rows = collect($res->json('data'));
        $this->assertCount(1, $rows);
        $this->assertSame('gone@example.com', $rows->first()['email']);
        // status ảo 'deleted' để FE phân biệt vs suspended
        $this->assertSame('deleted', $rows->first()['status']);
        $this->assertNotNull($rows->first()['deleted_at']);
    }

    public function test_show_returns_trashed_user_detail(): void
    {
        $admin = $this->admin();
        $trashed = User::factory()->create(['email' => 'gone@example.com']);
        $trashed->delete();

        $res = $this->actingAs($admin, 'sanctum')->getJson("/api/v1/admin/users/{$trashed->id}");

        $res->assertOk()
            ->assertJsonPath('email', 'gone@example.com')
            ->assertJsonPath('status', 'deleted');
    }

    public function test_restore_undeletes_soft_deleted_user(): void
    {
        $admin = $this->admin();
        $trashed = User::factory()->create(['email' => 'gone@example.com']);
        $trashed->delete();
        $this->assertNotNull($trashed->fresh()->deleted_at);

        $res = $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/users/{$trashed->id}/restore");

        $res->assertOk()->assertJsonPath('status', 'active');
        // Fetch bằng withTrashed để chắc chắn deleted_at đã bị clear
        $fresh = User::withTrashed()->find($trashed->id);
        $this->assertNull($fresh->deleted_at);
        $this->assertSame('active', $fresh->status);
    }

    /** Trước fix: route mặc định 404 trashed → restore không gọi được. Nay withTrashed nên OK. */
    public function test_restore_route_reaches_trashed_user_not_404(): void
    {
        $admin = $this->admin();
        $trashed = User::factory()->create();
        $trashed->delete();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/users/{$trashed->id}/restore")
            ->assertOk();
    }

    /** Ngược lại: update/suspend/destroy KHÔNG withTrashed — 404 nếu user đã trashed, đúng ý muốn. */
    public function test_update_still_404s_on_trashed_user(): void
    {
        $admin = $this->admin();
        $trashed = User::factory()->create();
        $trashed->delete();

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/v1/admin/users/{$trashed->id}", ['name' => 'Zombie'])
            ->assertNotFound();
    }
}
