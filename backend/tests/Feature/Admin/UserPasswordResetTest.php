<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * PATCH /admin/users/{user}/password — an admin setting SOMEONE ELSE'S
 * password, re-authenticating themselves first.
 */
class UserPasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private const ADMIN_PASSWORD = 'admin-secret-value';

    public function test_an_admin_can_set_another_users_password(): void
    {
        $target = $this->target();
        $this->reset($this->admin(), $target)->assertNoContent();
        // A real login, not just the 204: asserting the status alone passes
        // against a double-hashed password nobody can ever sign in with.
        Auth::forgetGuards();
        $this->postJson(route('auth.login', absolute: false), ['email' => $target->email, 'password' => 'brand-new-secret'])->assertOk()->assertJsonStructure(['token']);
    }

    public function test_the_old_password_no_longer_works(): void
    {
        $target = $this->target();
        $this->reset($this->admin(), $target)->assertNoContent();
        Auth::forgetGuards();
        $this->postJson(route('auth.login', absolute: false), ['email' => $target->email, 'password' => 'target-secret'])->assertUnprocessable();
    }

    public function test_it_revokes_every_token_the_target_holds(): void
    {
        $target = $this->target();
        $phone = $target->createToken('phone')->plainTextToken;
        $target->createToken('laptop');
        $this->reset($this->admin(), $target)->assertNoContent();
        $this->assertSame(0, $target->tokens()->count());
        Auth::forgetGuards();
        $this->withToken($phone)->getJson(route('auth.me', absolute: false))->assertUnauthorized();
    }

    public function test_it_does_not_revoke_the_acting_admins_token(): void
    {
        $admin = $this->admin();
        $token = $admin->createToken('spa')->plainTextToken;
        $this->withToken($token)->patchJson($this->url($this->target()), $this->payload())->assertNoContent();
        $this->assertSame(1, $admin->tokens()->count());
        Auth::forgetGuards();
        $this->withToken($token)->getJson(route('auth.me', absolute: false))->assertOk();
    }

    public function test_it_requires_the_acting_admins_current_password(): void
    {
        $target = $this->target();
        $hash = $target->password;
        $admin = $this->admin();
        $this->withToken($this->tokenFor($admin))->patchJson($this->url($target), ['password' => 'brand-new-secret'])
            ->assertUnprocessable()->assertJsonValidationErrors('current_password');
        Auth::forgetGuards();
        $this->withToken($this->tokenFor($admin))->patchJson($this->url($target), ['current_password' => 'not-my-password', 'password' => 'brand-new-secret'])
            ->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->assertSame($hash, $target->refresh()->password);
    }

    public function test_it_rejects_a_short_password(): void
    {
        $target = $this->target();
        $hash = $target->password;
        $this->withToken($this->tokenFor($this->admin()))->patchJson($this->url($target), ['current_password' => self::ADMIN_PASSWORD, 'password' => 'short'])
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->assertSame($hash, $target->refresh()->password);
    }

    public function test_an_admin_cannot_reset_their_own_password_here(): void
    {
        $admin = $this->admin();
        $this->withToken($this->tokenFor($admin))->patchJson($this->url($admin), $this->payload())
            ->assertForbidden()->assertJsonPath('message', 'This action is unauthorized.');
        $this->assertTrue(Hash::check(self::ADMIN_PASSWORD, $admin->refresh()->password));
    }

    public function test_an_agent_cannot_reset_anyones_password(): void
    {
        $agent = User::factory()->agent()->create(['password' => self::ADMIN_PASSWORD]);
        $target = $this->target();
        $this->withToken($this->tokenFor($agent))->patchJson($this->url($target), $this->payload())->assertForbidden();
        $this->assertTrue(Hash::check('target-secret', $target->refresh()->password));
    }

    public function test_it_is_rate_limited_at_six_per_minute(): void
    {
        $admin = $this->admin();
        $target = $this->target();
        RateLimiter::clear('password:'.$admin->getAuthIdentifier());
        $token = $this->tokenFor($admin);
        foreach (range(1, 6) as $_) {
            $this->withToken($token)->patchJson($this->url($target), ['current_password' => 'wrong', 'password' => 'brand-new-secret'])->assertUnprocessable();
        }
        // Six a minute, not the write limiter's sixty: guessing an admin's own
        // password through this endpoint has to be expensive.
        $this->withToken($token)->patchJson($this->url($target), ['current_password' => 'wrong', 'password' => 'brand-new-secret'])
            ->assertTooManyRequests()->assertHeader('Retry-After');
    }

    public function test_it_returns_404_for_an_unknown_user(): void
    {
        $this->withToken($this->tokenFor($this->admin()))->patchJson('/api/v1/admin/users/999999/password', $this->payload())->assertNotFound();
    }

    private function admin(): User
    {
        return User::factory()->admin()->create(['password' => self::ADMIN_PASSWORD]);
    }

    private function target(): User
    {
        return User::factory()->agent()->create(['password' => 'target-secret']);
    }

    private function reset(User $admin, User $target)
    {
        return $this->withToken($this->tokenFor($admin))->patchJson($this->url($target), $this->payload());
    }

    /** @return array<string, string> */
    private function payload(): array
    {
        return ['current_password' => self::ADMIN_PASSWORD, 'password' => 'brand-new-secret'];
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken('spa')->plainTextToken;
    }

    private function url(User $user): string
    {
        return route('admin.users.password', $user, absolute: false);
    }
}
