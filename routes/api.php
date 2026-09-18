<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\EventController;
use Illuminate\Support\Facades\Route;

// Rutas de la API (prefijo /api, grupo de middleware "api": sin sesión,
// sin CSRF).

Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/login', [AuthController::class, 'login']);

Route::get('/events', [EventController::class, 'index']);

Route::middleware(['auth:api', 'role:ORGANIZER'])->group(function () {
    Route::post('/events', [EventController::class, 'store']);
});
