<?php

use App\Http\Controllers\Admin\AuthController;
use Illuminate\Support\Facades\Route;

Route::post('/api/admin/v1/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

Route::get('/', function () {
    return view('welcome');
});
