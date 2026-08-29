<?php

namespace Tests\Unit\Enums;

use App\Enums\UserRole;
use Tests\TestCase;

class UserRoleTest extends TestCase
{
    public function test_it_has_exactly_two_cases(): void
    {
        $this->assertCount(2, UserRole::cases());
    }

    public function test_it_uses_documented_values(): void
    {
        $this->assertSame('admin', UserRole::Admin->value);
        $this->assertSame('agent', UserRole::Agent->value);
    }

    public function test_values_preserves_declaration_order(): void
    {
        $this->assertSame(['admin', 'agent'], UserRole::values());
    }

    public function test_unknown_role_is_rejected(): void
    {
        $this->assertNull(UserRole::tryFrom('manager'));
    }
}
