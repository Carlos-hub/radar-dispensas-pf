<?php

namespace App\Http\Controllers;

use App\Models\Dispensa;
use Illuminate\View\View;

class DispensaController extends Controller
{
    public function show(Dispensa $dispensa): View
    {
        return view('dispensas.show', ['d' => $dispensa]);
    }
}
