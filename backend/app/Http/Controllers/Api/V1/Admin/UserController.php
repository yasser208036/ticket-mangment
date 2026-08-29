<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreUserRequest;
use App\Http\Requests\Api\V1\Admin\UpdateUserRequest;
use App\Http\Resources\V1\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', User::class);
        $filters = $request->validate([
            'search' => ['sometimes', 'string', 'max:255'],
            'status' => ['sometimes', 'in:active,inactive'],
            'role' => ['sometimes', Rule::enum(UserRole::class)],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $users = User::query()
            ->when(filled($filters['search'] ?? null), function ($query) use ($filters): void {
                $term = '%'.addcslashes($filters['search'], '%_\\').'%';
                $query->where(fn ($query) => $query->whereLike('name', $term)->orWhereLike('email', $term));
            })
            ->when(($filters['status'] ?? null) === 'active', fn ($query) => $query->active())
            ->when(($filters['status'] ?? null) === 'inactive', fn ($query) => $query->where('is_active', false))
            ->when(filled($filters['role'] ?? null), fn ($query) => $query->where('role', $filters['role']))
            ->orderBy('name')->orderBy('id')
            ->paginate($filters['per_page'] ?? 15)->withQueryString();

        return UserResource::collection($users);
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $this->authorize('create', User::class);
        $user = new User($request->safe()->only(['name', 'email', 'password', 'is_active']));
        $user->role = $request->enum('role', UserRole::class);
        $user->save();

        return UserResource::make($user)->response()->setStatusCode(201);
    }

    public function show(User $user): JsonResponse
    {
        $this->authorize('view', $user);

        return UserResource::make($user)->response();
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $this->authorize('update', $user);
        $deactivating = $request->has('is_active') && ! $request->boolean('is_active');
        $demoting = $request->enum('role', UserRole::class) === UserRole::Agent && $user->isAdmin();
        DB::transaction(function () use ($request, $user, $deactivating, $demoting): void {
            if ($deactivating || $demoting) {
                $this->guardAgainstLockout($request, $user, $deactivating);
            }
            $user->fill($request->safe()->only(['name', 'email', 'is_active']));
            if ($request->has('role')) {
                $user->role = $request->enum('role', UserRole::class);
            }
            $user->save();
            if ($deactivating) {
                $user->tokens()->delete();
            }
        });

        return UserResource::make($user->refresh())->response();
    }

    private function guardAgainstLockout(Request $request, User $user, bool $deactivating): void
    {
        $field = $deactivating ? 'is_active' : 'role';
        if ($user->is($request->user())) {
            throw ValidationException::withMessages([
                $field => $deactivating ? 'You cannot deactivate your own account.' : 'You cannot change your own role.',
            ]);
        }
        $remaining = User::query()->where('role', UserRole::Admin)->where('is_active', true)
            ->whereKeyNot($user->getKey())->lockForUpdate()->count();
        if ($remaining === 0) {
            throw ValidationException::withMessages([$field => 'This is the last active administrator. Promote someone else first.']);
        }
    }
}
