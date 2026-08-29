<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class PasswordThrottleTest extends TestCase
{
    use RefreshDatabase;

    public function test_seventh_attempt_is_blocked_per_user(): void
    {
        $first = User::factory()->create(['password' => 'current-secret']);
        $second = User::factory()->create(['password' => 'current-secret']);
        // The limiter's real key is "password:{id}" (colon, from
        // ThrottleRequests::handleRequestUsingNamedLimiter), not "password|{id}".
        RateLimiter::clear('password:'.$first->getAuthIdentifier());
        RateLimiter::clear('password:'.$second->getAuthIdentifier());
        foreach (range(1, 6) as $_) {
            $this->attempt($first)->assertUnprocessable();
        }
        $this->attempt($first)->assertTooManyRequests()->assertHeader('Retry-After');
        // The sanctum guard caches the resolved user on the container-bound
        // guard instance, so a second withToken() call in the same test would
        // otherwise still authenticate as $first and inherit its throttle key.
        Auth::forgetGuards();
        $this->attempt($second)->assertUnprocessable();
    }

    private function attempt(User $user)
    {
        return $this->withToken($user->createToken('spa')->plainTextToken)->patchJson(route('auth.password', absolute: false), ['current_password' => 'wrong', 'password' => 'new-secret-value', 'password_confirmation' => 'new-secret-value']);
    }
}
