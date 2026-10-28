<?php

use Illuminate\Support\Facades\Route;

// The backend is API-only; the UI lives in blitzmove-frontend.
Route::get('/', fn () => response()->json([
    'name' => config('app.name'),
    'api' => url('/api/v1'),
    'health' => url('/up'),
]));
