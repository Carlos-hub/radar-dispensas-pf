<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\User as SocialiteUser;
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
        if ($googleId === '') {
            return $this->falha();
        }

        // Quem já tem o google_id vinculado entra sem depender do e-mail.
        $user = User::where('google_id', $googleId)->first();

        if ($user === null) {
            // A partir daqui o e-mail passa a ser confiado: o Google precisa tê-lo verificado.
            $email = Str::lower(trim((string) $perfil->getEmail()));
            if ($email === '' || ! $this->emailVerificado($perfil)) {
                return $this->falha();
            }

            try {
                $user = DB::transaction(fn () => $this->contaPeloEmail($perfil, $googleId, $email));
            } catch (UniqueConstraintViolationException) {
                return $this->falha();
            }

            if ($user === null) {
                return $this->falha();
            }
        }

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        return redirect()->intended('/');
    }

    /** Lê a afirmação email_verified do perfil bruto (o GoogleProvider a expõe com essa chave). */
    private function emailVerificado(SocialiteUser $perfil): bool
    {
        $valor = $perfil->user['email_verified'] ?? null;

        return $valor === true || $valor === 'true';
    }

    /** Cria, vincula ou toma a conta do e-mail. Devolve null quando a conta pertence a outro Google já confirmado. */
    private function contaPeloEmail(SocialiteUser $perfil, string $googleId, string $email): ?User
    {
        $user = User::where('email', $email)->lockForUpdate()->first();

        if ($user === null) {
            $user = new User;
            $user->forceFill([
                'name' => Str::limit(trim((string) $perfil->getName()) ?: Str::before($email, '@'), 255, ''),
                'email' => $email,
                'google_id' => $googleId,
                'email_verified_at' => now(),
                'password' => null,
            ])->save();

            return $user;
        }

        // Conta com outro Google e e-mail confirmado é de outra pessoa: nada muda.
        if ($user->google_id !== null && $user->hasVerifiedEmail()) {
            return null;
        }

        // E-mail nunca confirmado pode ter sido posto por quem não é o dono: quem prova ser dono pelo Google
        // fica com a conta, e a senha, as sessões e o "lembrar de mim" antigos deixam de valer.
        if (! $user->hasVerifiedEmail()) {
            $user->password = null;
            $user->remember_token = Str::random(60);
            DB::table('sessions')->where('user_id', $user->id)->delete();
        }
        $user->forceFill(['google_id' => $googleId, 'email_verified_at' => $user->email_verified_at ?? now()])->save();

        return $user;
    }

    private function falha(): RedirectResponse
    {
        return redirect()->route('login')->withErrors(['email' => 'Não foi possível entrar com o Google. Tente de novo ou use e-mail e senha.']);
    }
}
