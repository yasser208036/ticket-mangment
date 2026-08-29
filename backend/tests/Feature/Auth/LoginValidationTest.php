<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class LoginValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_requires_an_email_and_a_password(): void
    {
        $this->postJson(route('auth.login', absolute: false), [])->assertStatus(422)->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_it_rejects_a_malformed_email(): void
    {
        $this->postJson(route('auth.login', absolute: false), ['email' => 'not-an-email', 'password' => 'x'])->assertStatus(422)->assertJsonValidationErrors(['email']);
    }

    public function test_it_rejects_an_unknown_email_with_the_generic_message(): void
    {
        $this->postJson(route('auth.login', absolute: false), ['email' => 'nobody@example.test', 'password' => 'wrong'])->assertStatus(422)->assertJsonPath('errors.email.0', 'These credentials do not match our records.');
    }

    public function test_it_rejects_a_wrong_password_with_the_same_message(): void
    {
        $user = User::factory()->create(['password' => 'correct-horse']);
        $unknown = $this->postJson(route('auth.login', absolute: false), ['email' => 'nobody@example.test', 'password' => 'wrong']);
        $wrong = $this->postJson(route('auth.login', absolute: false), ['email' => $user->email, 'password' => 'wrong']);
        $this->assertSame($unknown->json(), $wrong->json());
    }

    public function test_a_deactivated_user_cannot_log_in_with_a_correct_password(): void
    {
        $user = User::factory()->inactive()->create(['password' => 'correct-horse']);
        $this->postJson(route('auth.login', absolute: false), ['email' => $user->email, 'password' => 'correct-horse'])->assertStatus(422);
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_a_failed_login_issues_no_token(): void
    {
        $user = User::factory()->create(['password' => 'correct-horse']);
        $this->postJson(route('auth.login', absolute: false), ['email' => 'nobody@example.test', 'password' => 'wrong']);
        $this->postJson(route('auth.login', absolute: false), ['email' => $user->email, 'password' => 'wrong']);
        $this->assertSame(0, PersonalAccessToken::count());
    }

    public function test_it_never_reveals_whether_an_email_exists(): void
    {
        $user = User::factory()->create(['password' => 'correct-horse']);
        $unknown = $this->postJson(route('auth.login', absolute: false), ['email' => 'nobody@example.test', 'password' => 'wrong']);
        $wrong = $this->postJson(route('auth.login', absolute: false), ['email' => $user->email, 'password' => 'wrong']);
        $this->assertSame($unknown->status(), $wrong->status());
        $this->assertSame($unknown->getContent(), $wrong->getContent());
    }
}
