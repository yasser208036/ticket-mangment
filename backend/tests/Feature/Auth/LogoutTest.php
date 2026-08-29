<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class LogoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_204_and_an_empty_body(): void
    {
        $user = User::factory()->create();
        $this->withToken($this->tokenFor($user))->postJson(route('auth.logout', absolute: false))->assertNoContent();
    }

    public function test_it_deletes_the_token_that_made_the_request(): void
    {
        $user = User::factory()->create();
        $token = $this->tokenFor($user);
        $this->assertSame(1, $user->tokens()->count());
        $this->withToken($token)->postJson(route('auth.logout', absolute: false));
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_it_leaves_the_users_other_tokens_alone(): void
    {
        $user = User::factory()->create();
        $firstToken = $this->tokenFor($user);
        $secondToken = $user->createToken('spa');
        $this->withToken($firstToken)->postJson(route('auth.logout', absolute: false));
        $this->assertSame(1, $user->tokens()->count());
        $this->assertSame($secondToken->accessToken->id, $user->tokens()->sole()->id);
    }

    public function test_the_revoked_token_no_longer_authenticates(): void
    {
        $user = User::factory()->create();
        $token = $this->tokenFor($user);
        $this->withToken($token)->postJson(route('auth.logout', absolute: false));
        Auth::forgetGuards();
        $this->withToken($token)->getJson(route('auth.me', absolute: false))->assertUnauthorized()->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_logging_out_twice_returns_401_the_second_time(): void
    {
        $user = User::factory()->create();
        $token = $this->tokenFor($user);
        $this->withToken($token)->postJson(route('auth.logout', absolute: false))->assertNoContent();
        Auth::forgetGuards();
        $this->withToken($token)->postJson(route('auth.logout', absolute: false))->assertUnauthorized();
    }

    public function test_it_rejects_a_request_with_no_token(): void
    {
        $this->postJson(route('auth.logout', absolute: false))->assertUnauthorized();
        $this->assertSame(0, PersonalAccessToken::count());
    }

    public function test_it_rejects_a_garbage_bearer_token(): void
    {
        $this->withToken('99|not-a-real-token')->postJson(route('auth.logout', absolute: false))->assertUnauthorized();
        $this->withToken('nonsense')->postJson(route('auth.logout', absolute: false))->assertUnauthorized();
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken('spa')->plainTextToken;
    }
}
