<?php

use App\Http\Controllers\AdminUserController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\FavoriteController;
use App\Http\Controllers\TicketController;
use App\Http\Controllers\VenueController;
use Illuminate\Support\Facades\Route;

// Rutas de la API (prefijo /api, grupo de middleware "api": sin sesión,
// sin CSRF).

Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/login', [AuthController::class, 'login']);
Route::post('/auth/logout', [AuthController::class, 'logout'])->middleware('auth:api');

Route::prefix('v1/admin')->middleware(['auth:api', 'role:ADMIN'])->group(function () {
    Route::get('/usuarios', [AdminUserController::class, 'index']);
    Route::patch('/usuarios/{id}/estado', [AdminUserController::class, 'updateStatus'])->whereNumber('id');
});

Route::get('/events', [EventController::class, 'index']);
Route::get('/events/{id}', [EventController::class, 'show'])->whereNumber('id');
Route::get('/events/{id}/banner', [EventController::class, 'banner'])->whereNumber('id');
Route::get('/v1/tickets/{code}/validacion', [TicketController::class, 'validatePublic']);

Route::middleware(['auth:api', 'role:ORGANIZER'])->group(function () {
    Route::get('/v1/recintos', [VenueController::class, 'index']);
    Route::get('/v1/eventos/mios', [EventController::class, 'mine']);
    Route::post('/events', [EventController::class, 'store']);
    Route::match(['put', 'patch'], '/events/{id}', [EventController::class, 'update'])->whereNumber('id');
    Route::get('/events/{id}/sales-report', [EventController::class, 'salesReport']);
});

Route::middleware(['auth:api', 'role:CLIENT'])->group(function () {
    Route::get('/v1/favoritos', [FavoriteController::class, 'index']);
    Route::post('/v1/favoritos', [FavoriteController::class, 'store']);
    Route::delete('/v1/favoritos', [FavoriteController::class, 'destroy']);
    Route::get('/v1/tickets', [TicketController::class, 'index']);
    Route::get('/v1/tickets/{id}', [TicketController::class, 'show'])->whereNumber('id');
    Route::post('/events/{id}/tickets', [EventController::class, 'purchaseTickets'])->whereNumber('id');
});
