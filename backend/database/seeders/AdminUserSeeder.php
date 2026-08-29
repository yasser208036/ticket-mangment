<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = config('seeding.admin.email');
        $password = config('seeding.admin.password');

        if (blank($password)) {
            throw new RuntimeException('ADMIN_PASSWORD is empty. Set it in .env.');
        }

        $admin = User::firstOrNew(['email' => $email]);
        if ($admin->exists) {
            return;
        }

        $admin->fill([
            'name' => config('seeding.admin.name'),
            'password' => $password,
            'is_active' => true,
        ]);
        $admin->role = UserRole::Admin;
        $admin->save();
    }
}
