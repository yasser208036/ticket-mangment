<?php

use App\Http\Controllers\Api\V1\Admin\AssignmentRequestController as AdminAssignmentRequestController;
use App\Http\Controllers\Api\V1\Admin\UserController;
use App\Http\Controllers\Api\V1\Admin\UserPasswordController;
use App\Http\Controllers\Api\V1\Admin\WorkloadController;
use App\Http\Controllers\Api\V1\AgentController;
use App\Http\Controllers\Api\V1\AssignmentRequestController;
use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\LogoutController;
use App\Http\Controllers\Api\V1\Auth\MeController;
use App\Http\Controllers\Api\V1\Auth\PasswordController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\PriorityController;
use App\Http\Controllers\Api\V1\StatusController;
use App\Http\Controllers\Api\V1\TicketActivityController;
use App\Http\Controllers\Api\V1\TicketController;
use App\Http\Controllers\Api\V1\TicketNoteController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API routes — /api/v1
|--------------------------------------------------------------------------
|
| The prefix is set in bootstrap/app.php, so paths here are relative to
| /api/v1. Authentication is a Sanctum bearer token; there is no session.
|
*/

// Liveness probe. Deliberately unauthenticated and dependency-checking, so a
// deploy can tell "the app booted" apart from "the app cannot reach MySQL".
Route::get('/health', HealthController::class)->name('health');
Route::post('/auth/login', LoginController::class)
    ->middleware('throttle:login')
    ->name('auth.login');

Route::middleware(['auth:sanctum', 'active'])->group(function (): void {
    Route::post('/auth/logout', LogoutController::class)->middleware('throttle:write')->name('auth.logout');
    Route::get('/auth/me', MeController::class)->name('auth.me');
    Route::patch('/auth/password', PasswordController::class)
        ->middleware('throttle:password')->name('auth.password');
    Route::get('/categories', [CategoryController::class, 'index'])->name('categories.index');
    Route::post('/categories', [CategoryController::class, 'store'])->middleware('throttle:write')->name('categories.store');
    Route::get('/categories/{category}', [CategoryController::class, 'show'])->name('categories.show');
    Route::patch('/categories/{category}', [CategoryController::class, 'update'])->middleware('throttle:write')->name('categories.update');
    Route::delete('/categories/{category}', [CategoryController::class, 'destroy'])->middleware('throttle:write')->name('categories.destroy');
    Route::get('/priorities', PriorityController::class)->name('priorities.index');
    Route::get('/statuses', StatusController::class)->name('statuses.index');
    Route::get('/agents', AgentController::class)->name('agents.index');
    Route::get('/tickets', [TicketController::class, 'index'])->name('tickets.index');
    Route::get('/tickets/stats', [TicketController::class, 'stats'])->name('tickets.stats');
    Route::post('/tickets', [TicketController::class, 'store'])->middleware('throttle:write')->name('tickets.store');
    Route::get('/tickets/{ticket}', [TicketController::class, 'show'])->name('tickets.show');
    Route::get('/tickets/{ticket}/activities', TicketActivityController::class)->name('tickets.activities');
    Route::post('/tickets/{ticket}/notes', TicketNoteController::class)->middleware('throttle:write')->name('tickets.notes');
    Route::post('/tickets/{ticket}/assign', [TicketController::class, 'assign'])->middleware('throttle:write')->name('tickets.assign');
    Route::post('/tickets/{ticket}/assignment-requests', [AssignmentRequestController::class, 'store'])->middleware('throttle:write')->name('tickets.assignment-requests.store');
    Route::post('/tickets/{ticket}/escalate', [TicketController::class, 'escalate'])->middleware('throttle:write')->name('tickets.escalate');
    Route::post('/tickets/{ticket}/status', [TicketController::class, 'changeStatus'])->middleware('throttle:write')->name('tickets.status');
    Route::patch('/tickets/{ticket}', [TicketController::class, 'update'])->middleware('throttle:write')->name('tickets.update');
    Route::delete('/tickets/{ticket}', [TicketController::class, 'destroy'])->middleware('throttle:write')->name('tickets.destroy');
    Route::middleware('admin')->prefix('admin')->name('admin.')->group(function (): void {
        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        Route::post('/users', [UserController::class, 'store'])->middleware('throttle:write')->name('users.store');
        Route::get('/users/{user}', [UserController::class, 'show'])->name('users.show');
        Route::patch('/users/{user}', [UserController::class, 'update'])->middleware('throttle:write')->name('users.update');
        Route::delete('/users/{user}', [UserController::class, 'destroy'])->middleware('throttle:write')->name('users.destroy');
        // The tighter limiter, not `write`: setting someone else's password is
        // 6/min keyed by the acting admin, the same one /auth/password uses.
        Route::patch('/users/{user}/password', UserPasswordController::class)->middleware('throttle:password')->name('users.password');
        Route::get('/workload', WorkloadController::class)->name('workload');
        Route::get('/assignment-requests', [AdminAssignmentRequestController::class, 'index'])->name('assignment-requests.index');
        Route::post('/assignment-requests/{assignmentRequest}/approve', [AdminAssignmentRequestController::class, 'approve'])->middleware('throttle:write')->name('assignment-requests.approve');
        Route::post('/assignment-requests/{assignmentRequest}/decline', [AdminAssignmentRequestController::class, 'decline'])->middleware('throttle:write')->name('assignment-requests.decline');
    });
});
