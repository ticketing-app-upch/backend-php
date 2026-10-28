<?php

use App\Http\Controllers\TicketController;
use Illuminate\Support\Facades\Route;

Route::get('/validar/{code}', [TicketController::class, 'validatePublic']);

Route::get('/', function () {
    return view('welcome');
});
