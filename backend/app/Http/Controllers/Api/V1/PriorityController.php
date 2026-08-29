<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\PriorityResource;
use App\Models\Priority;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PriorityController extends Controller
{
    public function __invoke(): AnonymousResourceCollection
    {
        return PriorityResource::collection(Priority::query()->ordered()->get());
    }
}
