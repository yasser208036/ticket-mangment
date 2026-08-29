<?php

namespace Database\Seeders;

use App\Enums\StatusBucket;
use App\Models\Status;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class StatusSeeder extends Seeder
{
    public const STATUSES = [
        ['slug' => 'new', 'name' => 'New', 'bucket' => StatusBucket::Open, 'color' => '#3B82F6', 'is_default' => true, 'is_terminal' => false, 'sort_order' => 10],
        ['slug' => 'open', 'name' => 'Open', 'bucket' => StatusBucket::Open, 'color' => '#6366F1', 'is_default' => false, 'is_terminal' => false, 'sort_order' => 20],
        ['slug' => 'in-progress', 'name' => 'In Progress', 'bucket' => StatusBucket::Open, 'color' => '#8B5CF6', 'is_default' => false, 'is_terminal' => false, 'sort_order' => 30],
        ['slug' => 'pending', 'name' => 'Pending', 'bucket' => StatusBucket::Pending, 'color' => '#F59E0B', 'is_default' => false, 'is_terminal' => false, 'sort_order' => 40],
        ['slug' => 'resolved', 'name' => 'Resolved', 'bucket' => StatusBucket::Done, 'color' => '#10B981', 'is_default' => false, 'is_terminal' => true, 'sort_order' => 50],
        ['slug' => 'closed', 'name' => 'Closed', 'bucket' => StatusBucket::Done, 'color' => '#6B7280', 'is_default' => false, 'is_terminal' => true, 'sort_order' => 60],
        ['slug' => 'reopened', 'name' => 'Reopened', 'bucket' => StatusBucket::Open, 'color' => '#EF4444', 'is_default' => false, 'is_terminal' => false, 'sort_order' => 70],
    ];

    public function run(): void
    {
        $defaultSlug = collect(self::STATUSES)->firstWhere('is_default', true)['slug'];

        DB::transaction(function () use ($defaultSlug): void {
            Status::query()->where('is_default', true)->where('slug', '!=', $defaultSlug)->update(['is_default' => false]);
            foreach (self::STATUSES as $status) {
                Status::updateOrCreate(['slug' => $status['slug']], $status);
            }
        });
    }
}
