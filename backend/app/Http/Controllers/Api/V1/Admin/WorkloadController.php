<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\AgentWorkload;
use Illuminate\Http\JsonResponse;

class WorkloadController extends Controller
{
    public function __invoke(AgentWorkload $workload): JsonResponse
    {
        return response()->json(['data' => $workload->overview()]);
    }
}
