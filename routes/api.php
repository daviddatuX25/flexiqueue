<?php

use App\Http\Controllers\Api\Edge\AssignmentController;
use App\Http\Controllers\Api\Edge\HeartbeatController;
use App\Http\Controllers\Api\Edge\PairController;
use Illuminate\Support\Facades\Route;

// Simple health check for EdgeModeService::isOnline() detection — no auth required
Route::get('/ping', fn () => response()->json(['status' => 'ok']))->name('api.ping');

// Edge device pairing — no device auth (pre-pairing), rate limited 5/IP/15min per §14.7
Route::post('/edge/pair', PairController::class)
    ->name('api.edge.pair')
    ->middleware('throttle:5,15');

// Edge device authenticated endpoints
Route::middleware(['auth.edge_device'])->group(function () {
    Route::get('/edge/assignment', AssignmentController::class)->name('api.edge.assignment');
    Route::post('/edge/heartbeat', HeartbeatController::class)->name('api.edge.heartbeat');
});
