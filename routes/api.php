<?php

use App\Http\Controllers\Api\V1\EventController;
use App\Http\Controllers\Api\V1\ShipmentController;
use App\Http\Controllers\Api\V1\ShipmentFileController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API für die interne Anwendung (/api/v1)
|--------------------------------------------------------------------------
|
| Jeder Aufruf braucht Bearer-Token, Zeitstempel und HMAC-Signatur
| (siehe docs/api.md). Der Datei-Upload läuft im Modus "stream".
|
*/

Route::middleware('throttle:240,1')->group(function () {
    Route::post('shipments', [ShipmentController::class, 'store'])
        ->middleware('portal.api:shipments:write');

    Route::get('shipments/{shipment}', [ShipmentController::class, 'show'])
        ->middleware('portal.api:shipments:read');

    Route::patch('shipments/{shipment}', [ShipmentController::class, 'update'])
        ->middleware('portal.api:shipments:write');

    Route::delete('shipments/{shipment}', [ShipmentController::class, 'destroy'])
        ->middleware('portal.api:shipments:write');

    Route::post('shipments/{shipment}/files', [ShipmentFileController::class, 'store'])
        ->middleware('portal.api:shipments:write,stream');

    Route::post('shipments/{shipment}/release', [ShipmentController::class, 'release'])
        ->middleware('portal.api:shipments:write');

    Route::get('events', [EventController::class, 'index'])
        ->middleware('portal.api:events:read');
});
