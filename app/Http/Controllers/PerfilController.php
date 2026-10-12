<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PerfilController extends Controller
{
    public function show(Request $request): View
    {
        return view('perfil', ['user' => $request->user()]);
    }

    /** Exclui a conta. A confirmação é digitar o e-mail, porque contas criadas pelo Google não têm senha. */
    public function destroy(Request $request): RedirectResponse
    {
        $user = $request->user();
        $request->validate(
            ['email_confirmacao' => ['required', Rule::in([$user->email])]],
            ['email_confirmacao.*' => 'Digite o e-mail da conta para confirmar a exclusão.'],
        );

        Auth::logout();
        $user->delete();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/')->with('status', 'Conta excluída.');
    }
}
