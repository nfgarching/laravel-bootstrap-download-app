<?php

use App\Http\Controllers\DownloadController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'home')->name('home');

Route::middleware('throttle:60,1')->group(function () {
    Route::get('/d/{token}', [DownloadController::class, 'show'])->name('download.show');
    Route::post('/d/{token}/unlock', [DownloadController::class, 'unlock'])->name('download.unlock');
    Route::post('/d/{token}/files/{file}', [DownloadController::class, 'download'])->name('download.file');
});
