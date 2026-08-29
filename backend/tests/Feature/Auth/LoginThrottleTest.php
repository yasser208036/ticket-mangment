<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginThrottleTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_allows_five_attempts_per_minute(): void
    {
        $user = User::factory()->create(['password' => 'correct-horse']);
        foreach (range(1, 5) as $_) {
            $this->postJson(route('auth.login', absolute: false), ['email' => $user->email, 'password' => 'wrong'])->assertStatus(422);
        }
    }

    public function test_it_blocks_the_sixth_attempt(): void
    {
        $user = User::factory()->create(['password' => 'correct-horse']);
        foreach (range(1, 5) as $_) {
            $this->postJson(route('auth.login', absolute: false), ['email' => $user->email, 'password' => 'wrong']);
        }
        $this->postJson(route('auth.login', absolute: false), ['email' => $user->email, 'password' => 'wrong'])->assertStatus(429)->assertJsonPath('message', 'Too Many Attempts.')->assertHeader('Retry-After');
    }

    public function test_the_limit_applies_to_successful_logins_too(): void
    {
        $user = User::factory()->create(['password' => 'correct-horse']);
        foreach (range(1, 5) as $_) {
            $this->postJson(route('auth.login', absolute: false), ['email' => $user->email, 'password' => 'correct-horse'])->assertOk();
        }
        $this->postJson(route('auth.login', absolute: false), ['email' => $user->email, 'password' => 'correct-horse'])->assertStatus(429);
    }
}
