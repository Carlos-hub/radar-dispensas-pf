<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirect;
use Throwable;

class GoogleController extends Controller
{
    public function redirect(): SymfonyRedirect
    {
        abort_unless(config('services.google.client_id'), 404);

        return Socialite::driver('google')->redirect();
    }

    public function callback(Request $request): RedirectResponse
    {
        abort_unless(config('services.google.client_id'), 404);

        try {
            $perfil = Socialite::driver('google')->user();
        } catch (Throwable $e) {
            Log::info('Login Google não concluído', ['erro' => $e->getMessage()]);

            return $this->falha();
        }

        $googleId = (string) $perfil->getId();
        $email = Str::lower(trim((string) $perfil->getEmail()));
        if ($googleId === '' || $email === '') {
            return $this->falha();
        }

        $user = User::where('google_id', $googleId)->first() ?? User::where('email', $email)->first();

        if ($user === null) {
            $user = new User;
            $user->forceFill([
                'name' => trim((string) $perfil->getName()) ?: Str::before($email, '@'),
                'email' => $email,
                'google_id' => $googleId,
                'email_verified_at' => now(),
                'password' => null,
            ])->save();
        } elseif ($user->google_id === null) {
            // Conta por e-mail nunca confirmada pode ter sido criada por outra pessoa:
            // quem prova ser dono do e-mail pelo Google fica com ela, e a senha e as sessões antigas deixam de valer.
            if (! $user->hasVerifiedEmail()) {
                $user->password = null;
                DB::table('sessions')->where('user_id', $user->id)->delete();
            }
            $user->forceFill(['google_id' => $googleId, 'email_verified_at' => $user->email_verified_at ?? now()])->save();
        }

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        return redirect()->intended('/');
    }

    private function falha(): RedirectResponse
    {
        return redirect()->route('login')->withErrors(['email' => 'Não foi possível entrar com o Google. Tente de novo ou use e-mail e senha.']);
    }
}
