<?php

namespace Tests\Feature;

use App\Mail\EmailVerificationMail;
use App\Models\EmailVerificationCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    private function registerPayload(array $overrides = []): array
    {
        return array_merge([
            'email'        => 'newbie@example.com',
            'password'     => 'Password1',
            'name'         => 'Newbie',
            'birth_year'   => 2000,
            'gender'       => 'male',
            'height_cm'    => 170,
            'weight_kg'    => 65,
            'calorie_goal' => 2000,
        ], $overrides);
    }

    public function test_register_sends_verification_email_and_user_starts_unverified(): void
    {
        Mail::fake();

        $response = $this->postJson('/api/v1/auth/register', $this->registerPayload());

        $response->assertStatus(201)
            ->assertJsonPath('user.email_verified', false);

        $user = User::where('email', 'newbie@example.com')->first();
        $this->assertNull($user->email_verified_at);
        $this->assertDatabaseHas('email_verification_codes', ['user_id' => $user->id]);

        Mail::assertQueued(EmailVerificationMail::class, fn ($mail) => $mail->hasTo($user->email));
    }

    public function test_verify_with_correct_code_marks_email_verified(): void
    {
        Mail::fake();
        $user = User::factory()->create(['email_verified_at' => null]);
        EmailVerificationCode::create([
            'user_id'    => $user->id,
            'code_hash'  => Hash::make('123456'),
            'attempts'   => 0,
            'expires_at' => now()->addMinutes(15),
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/auth/email/verify', ['code' => '123456']);

        $response->assertOk()->assertJsonPath('user.email_verified', true);
        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertDatabaseMissing('email_verification_codes', ['user_id' => $user->id]);
    }

    public function test_verify_with_wrong_code_increments_attempts_and_fails(): void
    {
        $user = User::factory()->create(['email_verified_at' => null]);
        EmailVerificationCode::create([
            'user_id' => $user->id, 'code_hash' => Hash::make('123456'),
            'attempts' => 0, 'expires_at' => now()->addMinutes(15),
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/auth/email/verify', ['code' => '000000'])
            ->assertStatus(422);

        $this->assertNull($user->fresh()->email_verified_at);
        $this->assertEquals(1, EmailVerificationCode::where('user_id', $user->id)->first()->attempts);
    }

    public function test_verify_locks_after_too_many_wrong_attempts(): void
    {
        $user = User::factory()->create(['email_verified_at' => null]);
        EmailVerificationCode::create([
            'user_id' => $user->id, 'code_hash' => Hash::make('123456'),
            'attempts' => 5, 'expires_at' => now()->addMinutes(15),
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/auth/email/verify', ['code' => '123456'])
            ->assertStatus(410);

        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_verify_rejects_expired_code(): void
    {
        $user = User::factory()->create(['email_verified_at' => null]);
        EmailVerificationCode::create([
            'user_id' => $user->id, 'code_hash' => Hash::make('123456'),
            'attempts' => 0, 'expires_at' => now()->subMinute(),
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/auth/email/verify', ['code' => '123456'])
            ->assertStatus(410);
    }

    public function test_verify_when_already_verified_is_idempotent(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/auth/email/verify', ['code' => '999999'])
            ->assertOk()
            ->assertJsonPath('user.email_verified', true);
    }

    public function test_resend_sends_new_code_and_replaces_old_hash(): void
    {
        Mail::fake();
        $user = User::factory()->create(['email_verified_at' => null]);
        $old = EmailVerificationCode::create([
            'user_id' => $user->id, 'code_hash' => Hash::make('111111'),
            'attempts' => 3, 'expires_at' => now()->addMinutes(15),
        ]);
        // Giả lập mã đã gửi hơn 60s trước để vượt qua cooldown resend — update qua
        // query builder thẳng (bỏ qua auto-touch updated_at của Eloquent save()).
        \Illuminate\Support\Facades\DB::table('email_verification_codes')
            ->where('id', $old->id)
            ->update(['updated_at' => now()->subMinutes(2)->toDateTimeString()]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/auth/email/resend')
            ->assertOk();

        $record = EmailVerificationCode::where('user_id', $user->id)->first();
        $this->assertEquals(0, $record->attempts);
        $this->assertFalse(Hash::check('111111', $record->code_hash));
        Mail::assertQueued(EmailVerificationMail::class);
    }

    public function test_resend_is_rate_limited_within_cooldown(): void
    {
        Mail::fake();
        $user = User::factory()->create(['email_verified_at' => null]);
        EmailVerificationCode::create([
            'user_id' => $user->id, 'code_hash' => Hash::make('123456'),
            'attempts' => 0, 'expires_at' => now()->addMinutes(15),
        ]);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/auth/email/resend');

        $response->assertStatus(429)->assertJsonStructure(['detail', 'retry_after_seconds']);
        Mail::assertNotQueued(EmailVerificationMail::class);
    }

    public function test_resend_when_already_verified_is_rejected(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/auth/email/resend')
            ->assertStatus(422);
    }

    public function test_google_callback_auto_verifies_new_user(): void
    {
        $socialiteUser = new SocialiteUser();
        $socialiteUser->id = 'g123';
        $socialiteUser->name = 'G User';
        $socialiteUser->email = 'g@example.com';
        $socialiteUser->avatar = null;

        Socialite::shouldReceive('driver->stateless->user')->andReturn($socialiteUser);

        $response = $this->get('/api/v1/auth/google/callback');
        $response->assertRedirect();

        $user = User::where('email', 'g@example.com')->first();
        $this->assertNotNull($user);
        $this->assertNotNull($user->email_verified_at);
    }

    public function test_google_callback_verifies_existing_unverified_user_on_link(): void
    {
        $existing = User::factory()->create(['email' => 'linkme@example.com', 'email_verified_at' => null, 'google_id' => null]);

        $socialiteUser = new SocialiteUser();
        $socialiteUser->id = 'g999';
        $socialiteUser->name = $existing->name;
        $socialiteUser->email = 'linkme@example.com';
        $socialiteUser->avatar = null;

        Socialite::shouldReceive('driver->stateless->user')->andReturn($socialiteUser);

        $this->get('/api/v1/auth/google/callback')->assertRedirect();

        $this->assertNotNull($existing->fresh()->email_verified_at);
    }
}
