<?php

namespace Database\Seeders;

use App\Models\Priority;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PrioritySeeder extends Seeder
{
    public const PRIORITIES = [
        ['slug' => 'low', 'name' => 'Low', 'level' => 1, 'color' => '#10B981', 'is_default' => false],
        ['slug' => 'medium', 'name' => 'Medium', 'level' => 2, 'color' => '#F59E0B', 'is_default' => true],
        ['slug' => 'high', 'name' => 'High', 'level' => 3, 'color' => '#F97316', 'is_default' => false],
        ['slug' => 'urgent', 'name' => 'Urgent', 'level' => 4, 'color' => '#EF4444', 'is_default' => false],
    ];

    public function run(): void
    {
        $defaultSlug = collect(self::PRIORITIES)->firstWhere('is_default', true)['slug'];

        DB::transaction(function () use ($defaultSlug): void {
            Priority::query()->where('is_default', true)->where('slug', '!=', $defaultSlug)->update(['is_default' => false]);
            foreach (self::PRIORITIES as $priority) {
                Priority::updateOrCreate(['slug' => $priority['slug']], $priority);
            }
        });
    }
}
