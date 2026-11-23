<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CollecteController;
use App\Http\Controllers\Api\GarantyController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\RamasseurController;
use Illuminate\Support\Facades\Route;

Route::get('/login', [AuthController::class, 'notlogin'])->name('login');

Route::prefix('auth')->group(function () {
    Route::post('/register/user', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);
    });
});

Route::get('/ramasseurs/proches', [CollecteController::class, 'proches']);

Route::middleware('auth:sanctum')->group(function () {
    Route::put('/user/profil', [ProfileController::class, 'update']);

    Route::get('/menage/profil', [ProfileController::class, 'show']);
    Route::get('/menage/collectes', [CollecteController::class, 'index']);

    Route::get('/ramasseur/garanty', [GarantyController::class, 'index']);
    Route::post('/ramasseur/garanty/pay', [GarantyController::class, 'initiate']);
    Route::post('/ramasseur/garanty/callback', [GarantyController::class, 'callback'])->name('api.garanty.callback');
    Route::get('/ramasseur/garanty/check', [GarantyController::class, 'check'])->name('api.garanty.check');
    Route::get('/ramasseur/profil', [RamasseurController::class, 'profil']);
    Route::post('/ramasseur/disponibilite', [RamasseurController::class, 'disponibilite']);
    Route::post('/ramasseur/position', [RamasseurController::class, 'position']);
    Route::get('/ramasseur/collectes/disponibles', [CollecteController::class, 'disponibles']);

    Route::post('/collectes', [CollecteController::class, 'store']);
    Route::get('/collectes/options', [CollecteController::class, 'options']);
    Route::get('/collectes/{collecte}', [CollecteController::class, 'show']);
    Route::post('/collectes/{collecte}/annuler', [CollecteController::class, 'annuler']);
    Route::post('/collectes/{collecte}/accepter', [CollecteController::class, 'accepter']);
    Route::post('/collectes/{collecte}/refuser', [CollecteController::class, 'refuser']);
    Route::post('/collectes/{collecte}/{statut}', [CollecteController::class, 'transition'])->whereIn('statut', ['en-route', 'arrive', 'demarrer', 'terminer']);
});
