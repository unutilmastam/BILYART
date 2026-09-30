<?php

use App\Http\Controllers\SpaController;
use Illuminate\Support\Facades\Route;

// The API is JSON-only. The admin and tablet PWAs are static builds copied to public/admin and public/tablet;
// the web server serves their files directly and deep links fall back to their index.html here.
Route::get('/admin/{path?}', [SpaController::class, 'admin'])->where('path', '.*');
