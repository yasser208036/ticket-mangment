<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Status;
use App\Models\StatusTransition;
use Illuminate\Database\Seeder;

class StatusTransitionSeeder extends Seeder
{
    public const EDGES = [
        ['new', 'open', null], ['new', 'pending', null], ['open', 'in-progress', null], ['open', 'pending', null],
        ['in-progress', 'resolved', null], ['in-progress', 'pending', null], ['pending', 'open', null], ['pending', 'in-progress', null],
        ['resolved', 'closed', UserRole::Admin], ['resolved', 'reopened', null], ['closed', 'reopened', null],
        ['reopened', 'in-progress', null], ['reopened', 'resolved', null], ['reopened', 'pending', null],
    ];

    public function run(): void
    {
        $statusIds = Status::query()->pluck('id', 'slug');
        $keptIds = collect(self::EDGES)->map(function (array $edge) use ($statusIds): int {
            return StatusTransition::updateOrCreate(['from_status_id' => $statusIds[$edge[0]], 'to_status_id' => $statusIds[$edge[1]]], ['required_role' => $edge[2]])->getKey();
        });
        StatusTransition::query()->whereKeyNot($keptIds)->delete();
    }
}
