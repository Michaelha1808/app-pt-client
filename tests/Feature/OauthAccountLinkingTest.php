<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Tests\TestCase;

/**
 * Callback OAuth phải LINK vào tài khoản đã có cùng email thay vì INSERT trùng.
 *
 * Bug thật ở production: user đăng ký Google với "fboyquangninh@gmail.com" rồi login
 * Facebook cùng email → INSERT nổ unique violation trên cột email vì query lookup
 * `where(providerId)->orWhere('email', $email)` không chống được case-insensitive email
 * mismatch giữa 2 provider, và không có try/catch phía dưới.
 */
class OauthAccountLinkingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Default `oauth.google_enabled`/`oauth.facebook_enabled` dựa vào có client_id ở config
     * hay không (SettingsService::defaults()). Test env không set 2 key này nên OAuth mặc
     * định bị coi là disabled → callback redirect thẳng về `?error=oauth_disabled`, che
     * mất assertion của test. Bơm client_id giả để controller đi tiếp vào nhánh logic thật.
     */
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.google.client_id'   => 'test-google-client',
            'services.facebook.client_id' => 'test-facebook-client',
        ]);
    }

    private function fakeSocialite(string $id, string $email, string $name = 'Test User'): void
    {
        $u = new SocialiteUser();
        $u->id     = $id;
        $u->email  = $email;
        $u->name   = $name;
        $u->avatar = null;
        Socialite::shouldReceive('driver->stateless->user')->andReturn($u);
    }

    public function test_facebook_login_links_to_existing_google_account_same_email(): void
    {
        $existing = User::factory()->create([
            'email'       => 'fboyquangninh@gmail.com',
            'google_id'   => 'g-original',
            'facebook_id' => null,
            'provider'    => 'google',
        ]);

        $this->fakeSocialite('fb-4456451171281733', 'fboyquangninh@gmail.com', 'Triều Dương');

        $this->get('/api/v1/auth/facebook/callback')->assertRedirect();

        // KHÔNG được tạo user mới → chỉ có 1 record với email này
        $this->assertSame(1, User::where('email', 'fboyquangninh@gmail.com')->count());

        $linked = $existing->fresh();
        $this->assertSame('fb-4456451171281733', $linked->facebook_id);
        $this->assertSame('g-original', $linked->google_id, 'Không được ghi đè google_id đã có');
    }

    /** Postgres so sánh text phân biệt hoa/thường: Facebook trả email case khác Google vẫn phải link được. */
    public function test_facebook_login_links_even_when_email_case_differs(): void
    {
        User::factory()->create([
            'email'       => 'fboyquangninh@gmail.com',   // lowercase (Google)
            'google_id'   => 'g-original',
            'facebook_id' => null,
        ]);

        $this->fakeSocialite('fb-999', 'FBoyQuangNinh@Gmail.com', 'Triều Dương');

        $this->get('/api/v1/auth/facebook/callback')->assertRedirect();

        $this->assertSame(1, User::whereRaw('LOWER(email) = ?', ['fboyquangninh@gmail.com'])->count());
        $this->assertSame('fb-999', User::whereRaw('LOWER(email) = ?', ['fboyquangninh@gmail.com'])->first()->facebook_id);
    }

    public function test_google_login_links_to_existing_facebook_account_same_email(): void
    {
        $existing = User::factory()->create([
            'email'       => 'shared@example.com',
            'facebook_id' => 'fb-original',
            'google_id'   => null,
            'provider'    => 'facebook',
        ]);

        $this->fakeSocialite('g-new-123', 'shared@example.com');

        $this->get('/api/v1/auth/google/callback')->assertRedirect();

        $this->assertSame(1, User::where('email', 'shared@example.com')->count());
        $this->assertSame('g-new-123', $existing->fresh()->google_id);
    }

    /**
     * Bản chất bug production: user cùng email đã bị admin soft-delete → lookup bị SoftDeletes
     * global scope lọc mất, còn unique index Postgres trên email thì không loại trừ row trashed
     * → INSERT nổ UniqueConstraintViolation. Phải withTrashed() để phát hiện và redirect thay vì
     * crash / restore ngầm.
     */
    public function test_facebook_login_with_soft_deleted_account_redirects_instead_of_crashing(): void
    {
        $trashed = User::factory()->create([
            'email'       => 'fboyquangninh@gmail.com',
            'google_id'   => 'g-original',
            'facebook_id' => null,
            'provider'    => 'google',
        ]);
        $trashed->delete(); // soft delete (admin ban/remove)

        $this->fakeSocialite('fb-4456451171281733', 'fboyquangninh@gmail.com', 'Triều Dương');

        $this->get('/api/v1/auth/facebook/callback')
            ->assertRedirect()
            ->assertRedirectContains('error=account_deleted');

        // KHÔNG tạo user mới, và KHÔNG restore hay link vào bản ghi trashed
        $this->assertSame(0, User::where('email', 'fboyquangninh@gmail.com')->count());
        $this->assertSame(1, User::withTrashed()->where('email', 'fboyquangninh@gmail.com')->count());
        $this->assertNull($trashed->fresh()->facebook_id);
    }

    public function test_facebook_callback_without_email_redirects_with_error(): void
    {
        $u = new SocialiteUser();
        $u->id     = 'fb-no-email';
        $u->email  = null;                // Facebook cho phép user từ chối chia sẻ email
        $u->name   = 'No Email';
        $u->avatar = null;
        Socialite::shouldReceive('driver->stateless->user')->andReturn($u);

        $this->get('/api/v1/auth/facebook/callback')
            ->assertRedirect()
            ->assertRedirectContains('error=facebook_no_email');

        $this->assertSame(0, User::where('facebook_id', 'fb-no-email')->count());
    }
}
