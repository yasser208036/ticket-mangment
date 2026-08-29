<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TicketActivityEvent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\DestroyCategoryRequest;
use App\Http\Requests\Api\V1\StoreCategoryRequest;
use App\Http\Requests\Api\V1\UpdateCategoryRequest;
use App\Http\Resources\V1\CategoryResource;
use App\Models\Category;
use App\Models\Ticket;
use App\Services\ActivityRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CategoryController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Category::class);
        $filters = $request->validate(['status' => ['sometimes', 'in:active,inactive']]);
        $categories = Category::query()->when(($filters['status'] ?? null) === 'active', fn ($query) => $query->active())->when(($filters['status'] ?? null) === 'inactive', fn ($query) => $query->where('is_active', false))->ordered()->get();

        return CategoryResource::collection($categories);
    }

    public function store(StoreCategoryRequest $request): JsonResponse
    {
        $this->authorize('create', Category::class);
        $category = new Category($request->safe()->only(['name', 'description', 'color', 'is_active', 'sort_order']));
        $category->slug = Str::slug($request->string('name')->value());
        $category->save();

        return CategoryResource::make($category)->response()->setStatusCode(201);
    }

    public function show(Category $category): JsonResponse
    {
        $this->authorize('view', $category);

        return CategoryResource::make($category)->response();
    }

    public function update(UpdateCategoryRequest $request, Category $category): JsonResponse
    {
        $this->authorize('update', $category);
        $category->fill($request->safe()->only(['name', 'description', 'color', 'is_active', 'sort_order']));
        $category->save();

        return CategoryResource::make($category)->response();
    }

    public function destroy(DestroyCategoryRequest $request, Category $category, ActivityRecorder $recorder): Response|JsonResponse
    {
        $this->authorize('delete', $category);
        $targetId = $request->has('reassign_to') ? $request->integer('reassign_to') : null;

        return DB::transaction(function () use ($request, $category, $recorder, $targetId): Response|JsonResponse {
            Category::query()->whereKey($category->getKey())->lockForUpdate()->first();
            $ticketCount = $category->tickets()->withTrashed()->count();
            if ($ticketCount > 0 && $targetId === null) {
                return $this->reassignmentRequired($category, $ticketCount);
            }
            if ($ticketCount > 0) {
                $this->reassignTickets($category, $targetId, $request->user()->getKey(), $recorder);
            }
            $category->delete();

            return response()->noContent();
        });
    }

    private function reassignmentRequired(Category $category, int $ticketCount): JsonResponse
    {
        $options = Category::query()->active()->whereKeyNot($category->getKey())->ordered()->get();
        $message = $options->isEmpty() ? "This category still has {$ticketCount} tickets, and there is no other active category to move them to. Create one first." : "This category still has {$ticketCount} tickets. Choose another category to move them to, then delete again.";

        return response()->json(['message' => $message, 'errors' => ['reassign_to' => [$message]], 'ticket_count' => $ticketCount, 'reassign_to_options' => CategoryResource::collection($options)->resolve()], 422);
    }

    private function reassignTickets(Category $category, int $targetId, int $actorId, ActivityRecorder $recorder): void
    {
        $target = Category::query()->whereKey($targetId)->lockForUpdate()->firstOrFail();
        $ticketIds = $category->tickets()->withTrashed()->pluck('id')->all();
        Ticket::withTrashed()->whereIn('id', $ticketIds)->update(['category_id' => $target->getKey()]);
        $recorder->recordMany($ticketIds, TicketActivityEvent::CategoryChanged, [
            'user_id' => $actorId, 'field' => 'category_id',
            'old_value' => (string) $category->getKey(), 'new_value' => (string) $target->getKey(),
            'meta' => ['reason' => 'category_deleted', 'from_name' => $category->name, 'to_name' => $target->name],
        ]);

    }
}
