<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    if (file_exists(public_path('index.html'))) {
        return response(file_get_contents(public_path('index.html')), 200, ['Content-Type' => 'text/html']);
    }
    return view('welcome');
});

Route::get('/login', function () {
    if (request()->expectsJson()) {
        return response()->json(['message' => 'Unauthenticated.'], 401);
    }
    if (file_exists(public_path('index.html'))) {
        return response(file_get_contents(public_path('index.html')), 200, ['Content-Type' => 'text/html']);
    }
    return response()->json(['message' => 'Unauthenticated.'], 401);
})->name('login');

Route::fallback(function () {
    if (file_exists(public_path('index.html'))) {
        return response(file_get_contents(public_path('index.html')), 200, ['Content-Type' => 'text/html']);
    }
    return response()->json(['message' => 'Resource not found.'], 404);
});
