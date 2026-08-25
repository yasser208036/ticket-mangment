<?php

use App\Http\Controllers\Api\V1\HealthController;
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
