<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class ActiveAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_token_stops_working_when_the_account_is_deactivated(): void
    {
        $user = User::factory()->create();
        $token = $this->tokenFor($user);
        $this->withToken($token)->getJson(route('auth.me', absolute: false))->assertOk();
        $user->update(['is_active' => false]);
        Auth::forgetGuards();
        $this->withToken($token)->getJson(route('auth.me', absolute: false))->assertUnauthorized()->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_deactivation_revokes_every_token_on_the_next_request(): void
    {
        $user = User::factory()->create();
        $token = $this->tokenFor($user);
        $this->tokenFor($user);
        $user->update(['is_active' => false]);
        $this->withToken($token)->getJson(route('auth.me', absolute: false))->assertUnauthorized();
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_a_deactivated_user_cannot_log_out(): void
    {
        $user = User::factory()->create();
        $token = $this->tokenFor($user);
        $user->update(['is_active' => false]);
        $this->withToken($token)->postJson(route('auth.logout', absolute: false))->assertUnauthorized();
    }

    public function test_an_active_user_is_unaffected(): void
    {
        $admin = User::factory()->admin()->create();
        $agent = User::factory()->agent()->create();
        $this->withToken($this->tokenFor($admin))->getJson(route('auth.me', absolute: false))->assertOk();
        $this->withToken($this->tokenFor($agent))->getJson(route('auth.me', absolute: false))->assertOk();
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken('spa')->plainTextToken;
    }
}
