<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    public const CATEGORIES = [
        ['slug' => 'hardware', 'name' => 'Hardware', 'color' => '#EF4444', 'sort_order' => 10, 'description' => 'Laptops, desktops, phones, printers and peripherals.'],
        ['slug' => 'software', 'name' => 'Software', 'color' => '#3B82F6', 'sort_order' => 20, 'description' => 'Installed applications, licences and updates.'],
        ['slug' => 'network', 'name' => 'Network', 'color' => '#8B5CF6', 'sort_order' => 30, 'description' => 'Connectivity, VPN, Wi-Fi and shared drives.'],
        ['slug' => 'account-access', 'name' => 'Account & Access', 'color' => '#F59E0B', 'sort_order' => 40, 'description' => 'Passwords, permissions and account lifecycle.'],
        ['slug' => 'billing', 'name' => 'Billing', 'color' => '#10B981', 'sort_order' => 50, 'description' => 'Invoices, subscriptions and purchase requests.'],
        ['slug' => 'other', 'name' => 'Other', 'color' => '#6B7280', 'sort_order' => 60, 'description' => 'Anything that does not fit the categories above.'],
    ];

    public function run(): void
    {
        foreach (self::CATEGORIES as $category) {
            Category::withTrashed()->firstOrCreate(['slug' => $category['slug']], $category);
        }
    }
}
