<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\DispensaController;
use App\Http\Controllers\PerfilController;
use App\Http\Controllers\RadarController;
use App\Http\Controllers\SitemapController;

Route::get('/', RadarController::class)->name('radar');
Route::get('/dispensas/{dispensa}', [DispensaController::class, 'show'])->whereNumber('dispensa')->name('dispensas.show');

Route::get('/sitemap.xml', [SitemapController::class, 'sitemap']);
Route::get('/robots.txt', [SitemapController::class, 'robots']);

Route::middleware('auth')->group(function () {
    Route::get('/perfil', [PerfilController::class, 'show'])->name('perfil');
    Route::delete('/perfil', [PerfilController::class, 'destroy'])->name('perfil.destroy');
});
