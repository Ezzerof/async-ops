<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\AnalysisController;
use App\Http\Controllers\ConversionController;
use App\Http\Controllers\EmailController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\CsvImportController;
use App\Http\Controllers\TaskController;
use Illuminate\Support\Facades\Route;

Route::post('/login',  [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::post('/tasks',                [TaskController::class, 'store']);
    Route::get('/tasks/{task}',          [TaskController::class, 'show']);
    Route::get('/tasks/{task}/download', [TaskController::class, 'download']);

    Route::post('/conversions', [ConversionController::class, 'store']);
    Route::post('/analyses',   [AnalysisController::class, 'store']);
    Route::post('/invoices',   [InvoiceController::class, 'store']);

    Route::post('/emails',  [EmailController::class, 'store'])->middleware('throttle:5,1');

    Route::post('/imports',           [CsvImportController::class, 'store'])->middleware('throttle:10,1');
    Route::get('/imports/{import}',            [CsvImportController::class, 'show']);
    Route::delete('/imports/{import}',         [CsvImportController::class, 'destroy']);
    Route::post('/imports/{import}/analyse',   [CsvImportController::class, 'analyse'])->middleware('throttle:10,1');
});
