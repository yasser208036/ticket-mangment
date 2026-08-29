<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\StatusResource;
use App\Models\Status;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class StatusController extends Controller
{
    public function __invoke(): AnonymousResourceCollection
    {
        return StatusResource::collection(Status::query()->ordered()->get());
    }
}
