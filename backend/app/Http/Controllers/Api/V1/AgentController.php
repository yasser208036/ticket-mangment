<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class AgentController extends Controller
{
    /**
     * Active agents, id and name only. Deliberately NOT UserResource: this is
     * the one staff list every authenticated role can read, so it carries no
     * email, no role and no is_active. Admins wanting the full record still
     * use GET /admin/users.
     */
    public function __invoke(): JsonResponse
    {
        return response()->json(['data' => User::query()->active()
            ->where('role', UserRole::Agent)
            ->orderBy('name')->orderBy('id')
            ->get(['id', 'name'])]);
    }
}
