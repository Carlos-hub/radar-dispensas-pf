<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Auth\GoogleController;
use App\Http\Controllers\CandidaturaController;
use App\Http\Controllers\DispensaController;
use App\Http\Controllers\PerfilController;
use App\Http\Controllers\RadarController;
use App\Http\Controllers\SitemapController;

// Ids numéricos com até 18 dígitos: acima disso estouraria o bigint do PostgreSQL (erro 500 em vez de 404).
Route::get('/', RadarController::class)->name('radar');
Route::get('/dispensas/{dispensa}', [DispensaController::class, 'show'])->where('dispensa', '[0-9]{1,18}')->name('dispensas.show');

Route::get('/sitemap.xml', [SitemapController::class, 'sitemap']);
Route::get('/robots.txt', [SitemapController::class, 'robots']);

Route::middleware('auth')->group(function () {
    Route::get('/perfil', [PerfilController::class, 'show'])->name('perfil');
    Route::delete('/perfil', [PerfilController::class, 'destroy'])->name('perfil.destroy');

    // Visitante clica em "Salvar": passa pelo login e cai de volta na página da dispensa.
    Route::get('/dispensas/{dispensa}/entrar', fn (\App\Models\Dispensa $dispensa) => redirect()->route('dispensas.show', $dispensa))
        ->where('dispensa', '[0-9]{1,18}')->name('dispensas.entrar');

    Route::get('/candidaturas', [CandidaturaController::class, 'index'])->name('candidaturas.index');

    Route::middleware('throttle:candidaturas')->group(function () {
        Route::post('/dispensas/{dispensa}/candidatura', [CandidaturaController::class, 'store'])
            ->where('dispensa', '[0-9]{1,18}')->middleware('verified')->name('candidaturas.store');
        Route::patch('/candidaturas/{candidatura}', [CandidaturaController::class, 'update'])
            ->where('candidatura', '[0-9]{1,18}')->name('candidaturas.update');
        Route::delete('/candidaturas/{candidatura}', [CandidaturaController::class, 'destroy'])
            ->where('candidatura', '[0-9]{1,18}')->name('candidaturas.destroy');
    });
});

Route::middleware('guest')->group(function () {
    Route::get('/auth/google', [GoogleController::class, 'redirect'])->name('google.redirect');
    Route::get('/auth/google/callback', [GoogleController::class, 'callback'])->name('google.callback');
});
