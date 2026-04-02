<?php

use Illuminate\Support\Facades\Route;

// Simple health check for EdgeModeService::isOnline() detection — no auth required
Route::get('/ping', fn () => response()->json(['status' => 'ok']))->name('api.ping');
