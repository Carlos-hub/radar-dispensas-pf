<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as GoogleUser;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class LoginGoogleTest extends TestCase
{
    use RefreshDatabase;

    private ?GoogleUser $perfilDoGoogle = null;

    private bool $providerMockado = false;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.google.client_id' => 'id-de-teste', 'services.google.client_secret' => 'segredo']);
    }

    private function googleDevolve(string $id, string $email, ?string $nome = 'Ana Souza', bool $emailVerificado = true): void
    {
        // Como o GoogleProvider instalado: o dado bruto traz email_verified (e o legado verified_email).
        $perfil = (new GoogleUser)
            ->setRaw(['sub' => $id, 'email_verified' => $emailVerificado, 'verified_email' => $emailVerificado])
            ->map(['id' => $id, 'name' => $nome, 'email' => $email]);
        $this->perfilDoGoogle = $perfil;
        if ($this->providerMockado) {
            return; // o mock já devolve $this->perfilDoGoogle: permite trocar o perfil no meio do teste
        }
        $this->providerMockado = true;
        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('user')->andReturnUsing(fn () => $this->perfilDoGoogle);
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
    }

    public function test_primeiro_login_cria_conta_confirmada_e_sem_senha(): void
    {
        $this->googleDevolve('g-1', 'ana@example.com');

        $this->get('/auth/google/callback')->assertRedirect('/');

        $user = User::where('email', 'ana@example.com')->firstOrFail();
        $this->assertSame('g-1', $user->google_id);
        $this->assertSame('Ana Souza', $user->name);
        $this->assertNotNull($user->email_verified_at);
        $this->assertNull($user->password);
        $this->assertAuthenticatedAs($user);
    }

    public function test_perfil_sem_nome_usa_a_parte_local_do_email(): void
    {
        $this->googleDevolve('g-1', 'ana.souza@example.com', null);

        $this->get('/auth/google/callback');

        $this->assertSame('ana.souza', User::firstOrFail()->name);
    }

    public function test_login_seguinte_entra_na_mesma_conta_mesmo_se_o_email_mudou_no_google(): void
    {
        $user = User::factory()->create(['google_id' => 'g-1', 'email' => 'antigo@example.com']);
        $this->googleDevolve('g-1', 'novo@example.com');

        $this->get('/auth/google/callback');

        $this->assertSame(1, User::count());
        $this->assertAuthenticatedAs($user);
        $this->assertSame('antigo@example.com', $user->fresh()->email);
    }

    public function test_vincula_a_conta_confirmada_com_o_mesmo_email_e_mantem_a_senha(): void
    {
        $user = User::factory()->create(['email' => 'ana@example.com', 'password' => Hash::make('senha-dela-1')]);
        $this->googleDevolve('g-1', 'ana@example.com');

        $this->get('/auth/google/callback');

        $this->assertSame(1, User::count());
        $this->assertSame('g-1', $user->fresh()->google_id);
        $this->assertTrue(Hash::check('senha-dela-1', $user->fresh()->password));
        $this->assertAuthenticatedAs($user);
    }

    public function test_vincular_a_conta_nao_confirmada_anula_a_senha_e_encerra_as_sessoes(): void
    {
        // Um terceiro cadastrou o e-mail da Ana com uma senha que ele conhece e nunca confirmou.
        $user = User::factory()->unverified()->create(['email' => 'ana@example.com', 'password' => Hash::make('senha-do-terceiro'), 'remember_token' => 'token-do-terceiro']);
        DB::table('sessions')->insert([
            'id' => 'sessao-do-terceiro', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time(),
        ]);
        $this->googleDevolve('g-1', 'ana@example.com');

        $this->get('/auth/google/callback');

        $user->refresh();
        $this->assertNull($user->password);
        $this->assertNotSame('token-do-terceiro', $user->remember_token);
        $this->assertNotNull($user->email_verified_at);
        $this->assertSame('g-1', $user->google_id);
        $this->assertDatabaseMissing('sessions', ['id' => 'sessao-do-terceiro']);

        $this->post('/logout');
        $this->post('/login', ['email' => 'ana@example.com', 'password' => 'senha-do-terceiro']);
        $this->assertGuest();
    }

    public function test_falha_no_google_volta_para_o_login_com_mensagem(): void
    {
        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('user')->andThrow(new RuntimeException('access_denied'));
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        $this->get('/auth/google/callback')->assertRedirect('/login')->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_google_sem_email_nao_cria_conta(): void
    {
        $this->googleDevolve('g-1', '');

        $this->get('/auth/google/callback')->assertRedirect('/login')->assertSessionHasErrors('email');
        $this->assertSame(0, User::count());
    }

    public function test_volta_para_a_pagina_que_pediu_o_login(): void
    {
        $this->googleDevolve('g-1', 'ana@example.com');

        $this->withSession(['url.intended' => url('/dispensas/7/entrar')])
            ->get('/auth/google/callback')
            ->assertRedirect('/dispensas/7/entrar');
    }

    public function test_sem_credenciais_o_botao_some_e_as_rotas_respondem_404(): void
    {
        config(['services.google.client_id' => null]);

        $this->get('/login')->assertOk()->assertDontSee('Entrar com Google');
        $this->get('/auth/google')->assertNotFound();
        $this->get('/auth/google/callback')->assertNotFound();
    }

    public function test_com_credenciais_o_botao_aparece_no_login_e_no_cadastro(): void
    {
        $this->get('/login')->assertSee('Entrar com Google');
        $this->get('/register')->assertSee('Entrar com Google');
    }

    public function test_usuario_logado_nao_acessa_o_fluxo_do_google(): void
    {
        $this->actingAs(User::factory()->create())->get('/auth/google/callback')->assertRedirect('/');
    }

    public function test_email_nao_verificado_no_google_nao_cria_conta(): void
    {
        $this->googleDevolve('g-1', 'ana@example.com', 'Ana', false);

        $this->get('/auth/google/callback')->assertRedirect('/login')->assertSessionHasErrors('email');
        $this->assertSame(0, User::count());
        $this->assertGuest();
    }

    public function test_email_nao_verificado_no_google_nao_vincula_conta_existente(): void
    {
        $user = User::factory()->create(['email' => 'ana@example.com', 'password' => Hash::make('senha-dela-1')]);
        $this->googleDevolve('g-1', 'ana@example.com', 'Ana', false);

        $this->get('/auth/google/callback')->assertRedirect('/login');

        $this->assertNull($user->fresh()->google_id);
        $this->assertTrue(Hash::check('senha-dela-1', $user->fresh()->password));
        $this->assertGuest();
    }

    public function test_email_nao_verificado_mas_google_id_ja_vinculado_entra_na_conta(): void
    {
        $user = User::factory()->create(['google_id' => 'g-1', 'email' => 'ana@example.com']);
        $this->googleDevolve('g-1', 'ana@example.com', 'Ana', false);

        $this->get('/auth/google/callback')->assertRedirect('/');

        $this->assertAuthenticatedAs($user);
    }

    public function test_email_de_conta_confirmada_com_outro_google_id_e_recusado(): void
    {
        $user = User::factory()->create([
            'email' => 'ana@example.com', 'google_id' => 'g-atacante',
            'password' => Hash::make('senha-do-atacante'), 'remember_token' => 'token-fixo',
        ]);
        $antes = (array) DB::table('users')->where('id', $user->id)->first();
        $this->googleDevolve('g-vitima', 'ana@example.com');

        $this->get('/auth/google/callback')->assertRedirect('/login')->assertSessionHasErrors('email');

        $this->assertSame($antes, (array) DB::table('users')->where('id', $user->id)->first());
        $this->assertGuest();
    }

    public function test_email_de_conta_nao_confirmada_com_outro_google_id_e_assumido_por_completo(): void
    {
        // Atacante com conta ligada ao Google dele trocou o e-mail do perfil para o da vitima (fica nao confirmado).
        $user = User::factory()->unverified()->create([
            'email' => 'ana@example.com', 'google_id' => 'g-atacante',
            'password' => Hash::make('senha-do-atacante'), 'remember_token' => 'token-do-atacante',
        ]);
        DB::table('sessions')->insert([
            'id' => 'sessao-do-atacante', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time(),
        ]);
        $this->googleDevolve('g-vitima', 'ana@example.com');

        $this->get('/auth/google/callback')->assertRedirect('/');

        $this->assertAuthenticatedAs($user);
        $user->refresh();
        $this->assertSame('g-vitima', $user->google_id);
        $this->assertNull($user->password);
        $this->assertNotSame('token-do-atacante', $user->remember_token);
        $this->assertNotNull($user->email_verified_at);
        $this->assertDatabaseMissing('sessions', ['id' => 'sessao-do-atacante']);

        // A identidade antiga fica de fora.
        $this->post('/logout');
        $this->assertGuest();
        $this->googleDevolve('g-atacante', 'outro@example.com');
        $this->get('/auth/google/callback');
        $this->assertNotSame($user->id, auth()->id());
        $this->post('/logout');
        $this->post('/login', ['email' => 'ana@example.com', 'password' => 'senha-do-atacante']);
        $this->assertGuest();
    }

    public function test_email_com_maiusculas_vincula_a_conta_minuscula(): void
    {
        $user = User::factory()->create(['email' => 'ana@example.com']);
        $this->googleDevolve('g-1', 'Ana@Example.com');

        $this->get('/auth/google/callback');

        $this->assertSame(1, User::count());
        $this->assertSame('g-1', $user->fresh()->google_id);
        $this->assertAuthenticatedAs($user);
    }

    public function test_nome_muito_longo_e_cortado_em_255_caracteres(): void
    {
        $this->googleDevolve('g-1', 'ana@example.com', str_repeat('a', 300));

        $this->get('/auth/google/callback')->assertRedirect('/');

        $this->assertSame(255, mb_strlen(User::firstOrFail()->name));
    }
}
