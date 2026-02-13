<?php

use App\Http\Controllers\GameController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return redirect()->route('games.index');
})->name('home');

Route::get('dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

// Rules page
Route::get('rules', function () {
    return Inertia::render('Rules');
})->name('rules');

// Game routes (authenticated)
Route::middleware('auth')->group(function () {
    Route::get('games', [GameController::class, 'index'])->name('games.index');
    Route::get('games/create', [GameController::class, 'create'])->name('games.create');
    Route::post('games', [GameController::class, 'store'])->name('games.store');
    Route::get('games/{game}', [GameController::class, 'show'])->name('games.show');
    Route::post('games/{game}/start', [GameController::class, 'start'])->name('games.start');
    Route::get('api/games/{game}/state', [GameController::class, 'state'])->name('games.state');
});

require __DIR__.'/settings.php';
