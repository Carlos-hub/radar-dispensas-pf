<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class ContasTest extends TestCase
{
    use RefreshDatabase;

    private function dadosCadastro(array $extra = []): array
    {
        return $extra + [
            'name' => 'Ana Souza',
            'email' => 'ana@example.com',
            'password' => 'senha-segura-1',
            'password_confirmation' => 'senha-segura-1',
        ];
    }

    public function test_telas_de_conta_abrem(): void
    {
        foreach (['/login', '/register', '/forgot-password'] as $rota) {
            $this->get($rota)->assertOk();
        }
    }

    public function test_cadastro_cria_usuario_nao_confirmado_e_envia_confirmacao(): void
    {
        Notification::fake();

        $this->post('/register', $this->dadosCadastro())->assertRedirect('/');

        $user = User::where('email', 'ana@example.com')->firstOrFail();
        $this->assertNull($user->email_verified_at);
        $this->assertAuthenticatedAs($user);
        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_cadastro_recusa_email_repetido(): void
    {
        User::factory()->create(['email' => 'ana@example.com']);

        $this->post('/register', $this->dadosCadastro())->assertSessionHasErrors('email');
        $this->assertSame(1, User::count());
    }

    public function test_cadastro_tem_limite_de_10_por_hora_por_ip(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->post('/register', $this->dadosCadastro(['email' => "p$i@example.com"]));
            $this->post('/logout');
        }

        $this->post('/register', $this->dadosCadastro(['email' => 'onze@example.com']))
            ->assertSessionHasErrors('email');
        $this->assertDatabaseMissing('users', ['email' => 'onze@example.com']);
    }

    public function test_link_de_confirmacao_confirma_o_email(): void
    {
        $user = User::factory()->unverified()->create();
        $url = URL::temporarySignedRoute('verification.verify', now()->addHour(), [
            'id' => $user->id,
            'hash' => sha1($user->email),
        ]);

        $this->actingAs($user)->get($url)->assertRedirect();

        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_login_com_senha_certa_entra_e_com_senha_errada_nao(): void
    {
        $user = User::factory()->create(['password' => Hash::make('senha-segura-1')]);

        $this->post('/login', ['email' => $user->email, 'password' => 'errada'])->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->post('/login', ['email' => $user->email, 'password' => 'senha-segura-1'])->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
    }

    public function test_login_bloqueia_apos_5_tentativas_por_minuto(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'errada']);
        }

        $this->post('/login', ['email' => $user->email, 'password' => 'errada'])->assertStatus(429);
    }

    public function test_logout_encerra_a_sessao(): void
    {
        $this->actingAs(User::factory()->create())->post('/logout')->assertRedirect('/');
        $this->assertGuest();
    }

    public function test_recuperacao_de_senha_troca_a_senha(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email])->assertSessionHasNoErrors();

        $token = null;
        Notification::assertSentTo($user, ResetPassword::class, function ($n) use (&$token) {
            $token = $n->token;
            return true;
        });

        $this->get("/reset-password/$token?email=" . urlencode($user->email))->assertOk();
        $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'nova-senha-22',
            'password_confirmation' => 'nova-senha-22',
        ])->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('nova-senha-22', $user->fresh()->password));
    }

    public function test_perfil_exige_login(): void
    {
        $this->get('/perfil')->assertRedirect('/login');
    }

    public function test_perfil_altera_nome(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/perfil')->assertOk()->assertSee($user->email);
        $this->actingAs($user)
            ->put('/user/profile-information', ['name' => 'Novo Nome', 'email' => $user->email])
            ->assertSessionHasNoErrors();

        $this->assertSame('Novo Nome', $user->fresh()->name);
    }

    public function test_excluir_conta_exige_digitar_o_email(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->delete('/perfil', ['email_confirmacao' => 'outro@example.com'])
            ->assertSessionHasErrors('email_confirmacao');
        $this->assertDatabaseHas('users', ['id' => $user->id]);

        $this->actingAs($user)->delete('/perfil', ['email_confirmacao' => $user->email])->assertRedirect('/');
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertGuest();
    }
}
