<?php

use App\Http\Controllers\Device\DeviceApiController;
use Illuminate\Support\Facades\Route;

/*
| ESP32 API, prefix /device/v1 (DEVICE_PROTOCOL.md). No sessions/cookies.
| Device endpoints keep working when the client's subscription is inactive:
| a running session must still be able to stop and sync (SECURITY.md §4).
| Idempotency: /ack requires an Idempotency-Key; /poll is a heartbeat whose
| re-delivered commands the device de-duplicates by commandId; /register is
| keyed by the hardware id.
*/

Route::post('register', [DeviceApiController::class, 'register'])->middleware('throttle:device-register');
Route::get('pairing-status', [DeviceApiController::class, 'pairingStatus'])->middleware('throttle:device');

Route::middleware(['auth.device', 'throttle:device'])->group(function (): void {
    Route::post('poll', [DeviceApiController::class, 'poll']);
    Route::post('ack', [DeviceApiController::class, 'ack'])->middleware('idempotency:required');
    Route::get('state', [DeviceApiController::class, 'state']);
    Route::get('firmware/{version}', [DeviceApiController::class, 'firmware'])->where('version', '\d+\.\d+\.\d+(-[0-9A-Za-z.-]+)?');
});
