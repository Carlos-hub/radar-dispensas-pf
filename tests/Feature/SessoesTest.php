<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Cada "navegador" é uma sessão de verdade: driver database, cookie próprio e store zerado a cada troca.
 */
class SessoesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['session.driver' => 'database']);
    }

    /** Passa a falar como o navegador dono do cookie $id (ou como um navegador novo, sem cookie). */
    private function navegador(?string $id = null): void
    {
        // o guard guarda o store em 'session.store' (singleton): sem zerar os três, o "novo navegador" herda o login anterior
        $this->app['auth']->forgetGuards();
        $this->app['session']->forgetDrivers();
        $this->app->forgetInstance('session.store');
        $this->defaultCookies = $id === null ? [] : [config('session.cookie') => $id];
    }

    /** Faz login num navegador novo e devolve o id da sessão criada. */
    private function entrar(User $user, string $senha): string
    {
        $this->navegador();
        $resposta = $this->post('/login', ['email' => $user->email, 'password' => $senha])->assertRedirect('/');

        return $resposta->getCookie(config('session.cookie'))->getValue();
    }

    private function estaLogado(string $sessao): bool
    {
        $this->navegador($sessao);
        $resposta = $this->get('/candidaturas');

        return $resposta->getStatusCode() === 200;
    }

    public function test_dois_navegadores_logados_sao_sessoes_distintas(): void
    {
        $user = User::factory()->create(['password' => Hash::make('senha-segura-1')]);

        $a = $this->entrar($user, 'senha-segura-1');
        $b = $this->entrar($user, 'senha-segura-1');

        $this->assertNotSame($a, $b);
        $this->assertTrue($this->estaLogado($a));
        $this->assertTrue($this->estaLogado($b));
        $this->navegador();
        $this->get('/candidaturas')->assertRedirect('/login');
    }

    public function test_redefinir_a_senha_derruba_a_sessao_aberta_antes(): void
    {
        Notification::fake();
        $user = User::factory()->create(['password' => Hash::make('senha-segura-1')]);
        $invasor = $this->entrar($user, 'senha-segura-1');
        $this->assertTrue($this->estaLogado($invasor));

        $this->navegador();
        $this->post('/forgot-password', ['email' => $user->email]);
        $token = null;
        Notification::assertSentTo($user, ResetPassword::class, function ($n) use (&$token) {
            $token = $n->token;

            return true;
        });
        $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'nova-senha-22',
            'password_confirmation' => 'nova-senha-22',
        ])->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('nova-senha-22', $user->fresh()->password));

        $this->navegador($invasor);
        $this->get('/candidaturas')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_trocar_a_senha_derruba_as_outras_sessoes_e_mantem_a_de_quem_trocou(): void
    {
        $user = User::factory()->create(['password' => Hash::make('senha-segura-1')]);
        $outra = $this->entrar($user, 'senha-segura-1');
        $minha = $this->entrar($user, 'senha-segura-1');

        $this->navegador($minha);
        $this->put('/user/password', [
            'current_password' => 'senha-segura-1',
            'password' => 'nova-senha-22',
            'password_confirmation' => 'nova-senha-22',
        ])->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('nova-senha-22', $user->fresh()->password));

        $this->assertTrue($this->estaLogado($minha), 'quem trocou a senha deve continuar logado');
        $this->assertFalse($this->estaLogado($outra), 'a outra sessão deve ter caído');
    }

    public function test_conta_sem_senha_do_google_continua_acessando(): void
    {
        $user = User::factory()->create(['password' => null]);

        $this->navegador();
        $this->actingAs($user)->get('/candidaturas')->assertOk();
        $this->get('/candidaturas')->assertOk();
    }
}
