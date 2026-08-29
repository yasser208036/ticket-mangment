<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PasswordTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_changes_password_revokes_other_tokens_and_keeps_current_token(): void
    {
        $user = User::factory()->create(['password' => 'current-secret']);
        $currentToken = $user->createToken('current');
        $user->createToken('other');
        $this->withToken($currentToken->plainTextToken)->patchJson(route('auth.password', absolute: false), $this->validPayload())->assertNoContent();
        $user->refresh();
        $this->assertTrue(Hash::check('new-secret-value', $user->password));
        $this->assertSame($currentToken->accessToken->id, $user->tokens()->sole()->id);
        Auth::forgetGuards();
        $this->withToken($currentToken->plainTextToken)->getJson(route('auth.me', absolute: false))->assertOk();
    }

    public function test_wrong_current_password_changes_nothing(): void
    {
        $user = User::factory()->create(['password' => 'current-secret']);
        $token = $this->tokenFor($user);
        $originalHash = $user->password;
        $this->withToken($token)->patchJson(route('auth.password', absolute: false), [...$this->validPayload(), 'current_password' => 'wrong-secret'])->assertUnprocessable()->assertJsonPath('errors.current_password.0', 'The password is incorrect.');
        $this->assertSame($originalHash, $user->refresh()->password);
        $this->assertSame(1, $user->tokens()->count());
    }

    public function test_it_validates_confirmation_length_and_difference(): void
    {
        $user = User::factory()->create(['password' => 'current-secret']);
        $token = $this->tokenFor($user);
        $this->withToken($token)->patchJson(route('auth.password', absolute: false), ['current_password' => 'current-secret', 'password' => 'short'])->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->withToken($token)->patchJson(route('auth.password', absolute: false), ['current_password' => 'current-secret', 'password' => 'new-secret-value'])->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->withToken($token)->patchJson(route('auth.password', absolute: false), ['current_password' => 'current-secret', 'password' => 'current-secret', 'password_confirmation' => 'current-secret'])->assertUnprocessable()->assertJsonValidationErrors('password');
    }

    public function test_route_requires_authentication_and_patch_method(): void
    {
        $this->patchJson(route('auth.password', absolute: false), $this->validPayload())->assertUnauthorized();
        $this->postJson('/api/v1/auth/password', $this->validPayload())->assertMethodNotAllowed();
        $this->putJson('/api/v1/auth/password', $this->validPayload())->assertMethodNotAllowed();
        $this->assertSame('/api/v1/auth/password', route('auth.password', absolute: false));
    }

    private function validPayload(): array
    {
        return ['current_password' => 'current-secret', 'password' => 'new-secret-value', 'password_confirmation' => 'new-secret-value'];
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken('spa')->plainTextToken;
    }
}
