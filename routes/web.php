<?php

use App\Http\Controllers\Account\AccountController;
use App\Http\Controllers\Account\LoginController;
use App\Http\Controllers\Account\SetupController;
use App\Http\Controllers\Account\UploadController as AccountUploadController;
use App\Http\Controllers\DownloadController;
use App\Http\Controllers\UploadController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'home')->name('home');

Route::middleware('throttle:60,1')->group(function () {
    Route::get('/d/{token}', [DownloadController::class, 'show'])->name('download.show');
    Route::post('/d/{token}/unlock', [DownloadController::class, 'unlock'])->name('download.unlock');
    Route::post('/d/{token}/files/{file}', [DownloadController::class, 'download'])->name('download.file');
    Route::post('/d/{token}/zip', [DownloadController::class, 'downloadZip'])->name('download.zip');
    Route::post('/d/{token}/konto', [DownloadController::class, 'startAccount'])->name('download.account');

    // Rückkanal: Upload über den Link einer Anforderung
    Route::get('/u/{token}', [UploadController::class, 'show'])->name('upload.show');
    Route::post('/u/{token}/unlock', [UploadController::class, 'unlock'])->name('upload.unlock');
    Route::post('/u/{token}', [UploadController::class, 'store'])->name('upload.store');
});

/*
|--------------------------------------------------------------------------
| Empfängerkonten
|--------------------------------------------------------------------------
*/

Route::prefix('konto')->name('account.')->middleware('throttle:60,1')->group(function () {

    // Einrichtung (nur aus einem gültigen Link heraus, siehe download.account)
    Route::get('einrichten', [SetupController::class, 'show'])->name('setup');
    Route::post('einrichten/code', [SetupController::class, 'verifyCode'])->name('setup.code');
    Route::post('einrichten/code/neu', [SetupController::class, 'resend'])->name('setup.resend');
    Route::post('einrichten/passkey/optionen', [SetupController::class, 'passkeyOptions'])->name('setup.passkey.options');
    Route::post('einrichten/passkey', [SetupController::class, 'passkeyStore'])->name('setup.passkey');
    Route::post('einrichten/passwort', [SetupController::class, 'passwordStore'])->name('setup.password');

    // Anmeldung
    Route::middleware('guest')->group(function () {
        Route::get('anmelden', [LoginController::class, 'show'])->name('login');
        Route::post('anmelden/passkey/optionen', [LoginController::class, 'passkeyOptions'])->name('login.passkey.options');
        Route::post('anmelden/passkey', [LoginController::class, 'passkey'])->name('login.passkey');
        Route::post('anmelden', [LoginController::class, 'password'])->name('login.password');
        Route::get('wiederherstellen', [LoginController::class, 'showRecovery'])->name('recovery');
        Route::post('wiederherstellen', [LoginController::class, 'recovery'])->name('recovery.login');
    });

    // Bereich für angemeldete Empfänger
    Route::middleware('auth')->group(function () {
        Route::get('/', [AccountController::class, 'index'])->name('index');
        Route::get('sendungen/{recipient}', [AccountController::class, 'shipment'])->name('shipment');
        Route::post('sendungen/{recipient}/dateien/{file}', [AccountController::class, 'download'])->name('file');
        Route::post('sendungen/{recipient}/zip', [AccountController::class, 'zip'])->name('zip');

        Route::get('hochladen', [AccountUploadController::class, 'create'])->name('upload');
        Route::post('hochladen', [AccountUploadController::class, 'store'])->name('upload.store');
        Route::get('anforderungen/{uuid}', [AccountUploadController::class, 'showRequest'])->name('request');
        Route::post('anforderungen/{uuid}', [AccountUploadController::class, 'storeRequest'])->name('request.store');

        Route::get('sicherheit', [AccountController::class, 'security'])->name('security');
        Route::post('sicherheit/passkey/optionen', [AccountController::class, 'passkeyOptions'])->name('passkey.options');
        Route::post('sicherheit/passkey', [AccountController::class, 'passkeyStore'])->name('passkey.store');
        Route::delete('sicherheit/passkey/{passkey}', [AccountController::class, 'passkeyDestroy'])->whereNumber('passkey')->name('passkey.destroy');
        Route::post('sicherheit/wiederherstellungscodes', [AccountController::class, 'regenerateRecoveryCodes'])->name('recovery-codes.regenerate');
        Route::get('wiederherstellungscodes', [AccountController::class, 'recoveryCodes'])->name('recovery-codes');
        Route::post('benachrichtigung', [AccountController::class, 'notifications'])->name('notifications');
        Route::post('loeschen', [AccountController::class, 'destroy'])->name('destroy');
        Route::post('abmelden', [LoginController::class, 'logout'])->name('logout');
    });
});
