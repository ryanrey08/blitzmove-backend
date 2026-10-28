<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_log_in_and_receives_an_expiring_token(): void
    {
        $user = User::factory()->create(['email' => 'ops@example.com', 'password' => 'Secret123']);

        $response = $this->postJson($this->api('auth/login'), ['email' => 'OPS@example.com', 'password' => 'Secret123'])
            ->assertOk()
            ->assertJsonPath('data.user.email', 'ops@example.com')
            ->assertJsonStructure(['data' => ['token', 'expires_at', 'user' => ['id', 'role', 'permissions']]]);

        $token = PersonalAccessToken::findToken($response->json('data.token'));
        $this->assertTrue($token->tokenable->is($user));
        $this->assertTrue($token->expires_at->between(now()->addHours(11), now()->addHours(13)));
        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_remember_me_issues_a_longer_lived_token(): void
    {
        User::factory()->create(['email' => 'ops@example.com', 'password' => 'Secret123']);

        $response = $this->postJson($this->api('auth/login'), ['email' => 'ops@example.com', 'password' => 'Secret123', 'remember' => true]);

        $token = PersonalAccessToken::findToken($response->json('data.token'));
        $this->assertTrue($token->expires_at->greaterThan(now()->addDays(29)));
    }

    public function test_login_rejects_bad_credentials_and_inactive_users(): void
    {
        User::factory()->create(['email' => 'ops@example.com', 'password' => 'Secret123']);
        User::factory()->create(['email' => 'gone@example.com', 'password' => 'Secret123', 'is_active' => false]);

        $this->postJson($this->api('auth/login'), ['email' => 'ops@example.com', 'password' => 'wrong'])
            ->assertStatus(422)->assertJsonValidationErrors('email');
        $this->postJson($this->api('auth/login'), ['email' => 'gone@example.com', 'password' => 'Secret123'])
            ->assertStatus(422)->assertJsonValidationErrors('email');
        $this->postJson($this->api('auth/login'), [])
            ->assertStatus(422)->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_login_is_rate_limited(): void
    {
        User::factory()->create(['email' => 'ops@example.com']);

        foreach (range(1, 5) as $_) {
            $this->postJson($this->api('auth/login'), ['email' => 'ops@example.com', 'password' => 'wrong'])->assertStatus(422);
        }

        $this->postJson($this->api('auth/login'), ['email' => 'ops@example.com', 'password' => 'wrong'])->assertStatus(429);
    }

    public function test_protected_routes_require_authentication(): void
    {
        foreach (['auth/me', 'dashboard', 'orders', 'vehicles', 'drivers', 'trips', 'fuel-records', 'documents', 'fleet/monitoring'] as $path) {
            $this->getJson($this->api($path))->assertUnauthorized();
        }
    }

    public function test_logout_revokes_the_current_token(): void
    {
        User::factory()->create(['email' => 'ops@example.com', 'password' => 'Secret123']);
        $token = $this->postJson($this->api('auth/login'), ['email' => 'ops@example.com', 'password' => 'Secret123'])->json('data.token');

        $this->withToken($token)->getJson($this->api('auth/me'))->assertOk()->assertJsonPath('data.email', 'ops@example.com');
        $this->withToken($token)->postJson($this->api('auth/logout'))->assertOk();

        $this->assertNull(PersonalAccessToken::findToken($token));
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson($this->api('auth/me'))->assertUnauthorized();
    }

    public function test_expired_tokens_are_rejected(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('web', ['*'], now()->subMinute())->plainTextToken;

        $this->withToken($token)->getJson($this->api('auth/me'))->assertUnauthorized();
    }

    public function test_forgot_password_sends_link_without_revealing_accounts(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'ops@example.com']);

        $this->postJson($this->api('auth/forgot-password'), ['email' => 'ops@example.com'])->assertOk();
        $this->postJson($this->api('auth/forgot-password'), ['email' => 'nobody@example.com'])->assertOk();

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
            $url = $notification->toMail($user)->actionUrl;

            return str_contains($url, '/reset-password?token=') && str_contains($url, urlencode('ops@example.com'));
        });
    }

    public function test_password_can_be_reset_with_a_valid_token(): void
    {
        $user = User::factory()->create(['email' => 'ops@example.com']);
        $token = app('auth.password.broker')->createToken($user);

        $this->postJson($this->api('auth/reset-password'), [
            'token' => $token, 'email' => 'ops@example.com',
            'password' => 'NewSecret9', 'password_confirmation' => 'NewSecret9',
        ])->assertOk();

        $this->postJson($this->api('auth/login'), ['email' => 'ops@example.com', 'password' => 'NewSecret9'])->assertOk();
    }

    public function test_user_can_update_profile_and_password(): void
    {
        $user = User::factory()->create(['password' => 'Secret123']);
        $token = $user->createToken('web')->plainTextToken;

        $this->withToken($token)->putJson($this->api('auth/profile'), ['name' => 'Juan Dela Cruz', 'email' => $user->email, 'job_title' => 'Operations Manager'])
            ->assertOk()->assertJsonPath('data.name', 'Juan Dela Cruz');

        $this->withToken($token)->putJson($this->api('auth/password'), ['current_password' => 'wrong', 'password' => 'Another123', 'password_confirmation' => 'Another123'])
            ->assertStatus(422)->assertJsonValidationErrors('current_password');

        $this->withToken($token)->putJson($this->api('auth/password'), ['current_password' => 'Secret123', 'password' => 'Another123', 'password_confirmation' => 'Another123'])
            ->assertOk();
    }
}
