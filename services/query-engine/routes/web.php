<?php

use App\Http\Controllers\QuerySearchController;
use App\Http\Middleware\FuzzySearch;
use App\Http\Middleware\StoreSearchTerm;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    // Redirect to https://moogle.app
    if (config('app.env') === 'local') {
        return response()->json(['message' => 'Welcome to Moogle!']);
    } else {
        return redirect('https://moogle.app');
    }
});

Route::options('/search', function () {
    return response('', 204)->withHeaders([
        'Access-Control-Allow-Origin' => env('CORS_ALLOWED_ORIGIN', '*'),
        'Access-Control-Allow-Methods' => 'GET, OPTIONS',
        'Access-Control-Allow-Headers' => 'Content-Type, Authorization',
    ]);
});

Route::get('/search', [QuerySearchController::class, 'searx'])
    ->middleware([FuzzySearch::class, StoreSearchTerm::class])
    ->name('search.searx');
