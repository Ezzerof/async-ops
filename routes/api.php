<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\TaskController;
use Illuminate\Support\Facades\Route;

Route::post('/login',  [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::post('/tasks',                [TaskController::class, 'store']);
    Route::get('/tasks/{task}',          [TaskController::class, 'show']);
    Route::get('/tasks/{task}/download', [TaskController::class, 'download']);
});
