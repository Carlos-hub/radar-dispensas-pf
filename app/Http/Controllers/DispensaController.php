<?php

namespace App\Http\Controllers;

use App\Models\Dispensa;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DispensaController extends Controller
{
    public function show(Request $request, Dispensa $dispensa): View
    {
        return view('dispensas.show', [
            'd' => $dispensa,
            'salva' => (bool) $request->user()?->candidaturas()->where('dispensa_id', $dispensa->id)->exists(),
        ]);
    }
}
