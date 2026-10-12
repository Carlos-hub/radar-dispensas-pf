<?php

namespace Tests\Feature;

use App\Models\Candidatura;
use App\Models\Dispensa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CandidaturasTest extends TestCase
{
    use RefreshDatabase;

    public function test_visitante_que_tenta_salvar_vai_para_o_login(): void
    {
        $d = Dispensa::factory()->create();

        $this->post("/dispensas/$d->id/candidatura")->assertRedirect('/login');
        $this->get('/candidaturas')->assertRedirect('/login');
        $this->assertSame(0, Candidatura::count());
    }

    public function test_visitante_que_clica_em_salvar_volta_para_a_dispensa_depois_do_login(): void
    {
        $d = Dispensa::factory()->create();
        $user = User::factory()->create();

        $this->get("/dispensas/$d->id/entrar")->assertRedirect('/login');
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect("/dispensas/$d->id/entrar");
        $this->get("/dispensas/$d->id/entrar")->assertRedirect("/dispensas/$d->id");
    }

    public function test_usuario_sem_email_confirmado_nao_salva(): void
    {
        $d = Dispensa::factory()->create();

        $this->actingAs(User::factory()->unverified()->create())
            ->post("/dispensas/$d->id/candidatura")
            ->assertRedirect('/email/verify');
        $this->assertSame(0, Candidatura::count());
    }

    public function test_usuario_confirmado_salva_com_status_inicial_salva(): void
    {
        $d = Dispensa::factory()->create();
        $user = User::factory()->create();

        $this->actingAs($user)->from("/dispensas/$d->id")->post("/dispensas/$d->id/candidatura")
            ->assertRedirect("/dispensas/$d->id");

        $c = Candidatura::firstOrFail();
        $this->assertSame($user->id, $c->user_id);
        $this->assertSame($d->id, $c->dispensa_id);
        $this->assertSame('salva', $c->status->value);
        $this->assertSame('', $c->notas);
    }

    public function test_salvar_duas_vezes_nao_duplica_nem_apaga_status_e_notas(): void
    {
        $d = Dispensa::factory()->create();
        $user = User::factory()->create();
        Candidatura::factory()->for($user)->for($d)->create(['status' => 'enviada', 'notas' => 'já enviei']);

        $this->actingAs($user)->post("/dispensas/$d->id/candidatura")->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(1, Candidatura::count());
        $c = Candidatura::firstOrFail();
        $this->assertSame('enviada', $c->status->value);
        $this->assertSame('já enviei', $c->notas);
    }

    public function test_dois_usuarios_salvam_a_mesma_dispensa(): void
    {
        $d = Dispensa::factory()->create();

        $this->actingAs(User::factory()->create())->post("/dispensas/$d->id/candidatura");
        $this->actingAs(User::factory()->create())->post("/dispensas/$d->id/candidatura");

        $this->assertSame(2, Candidatura::count());
    }

    public function test_dispensa_encerrada_nao_pode_ser_salva(): void
    {
        $d = Dispensa::factory()->encerrada()->create();

        $this->actingAs(User::factory()->create())->from("/dispensas/$d->id")
            ->post("/dispensas/$d->id/candidatura")
            ->assertRedirect("/dispensas/$d->id")
            ->assertSessionHasErrors('candidatura');
        $this->assertSame(0, Candidatura::count());
    }

    public function test_recusa_de_dispensa_encerrada_aparece_no_radar(): void
    {
        $d = Dispensa::factory()->encerrada()->create();

        $this->actingAs(User::factory()->create())->from('/')
            ->post("/dispensas/$d->id/candidatura")
            ->assertRedirect('/');
        $this->get('/')->assertSee('Esta dispensa já encerrou e não pode mais ser salva.');
        $this->assertSame(0, Candidatura::count());
    }

    public function test_recusa_de_dispensa_encerrada_aparece_uma_vez_na_pagina_da_dispensa(): void
    {
        $d = Dispensa::factory()->encerrada()->create();

        $this->actingAs(User::factory()->create())->from("/dispensas/$d->id")
            ->post("/dispensas/$d->id/candidatura")
            ->assertRedirect("/dispensas/$d->id");
        $html = $this->get("/dispensas/$d->id")->getContent();

        $this->assertSame(1, substr_count($html, 'Esta dispensa já encerrou e não pode mais ser salva.'));
        $this->assertSame(0, Candidatura::count());
    }

    public function test_lista_so_as_candidaturas_do_proprio_usuario_e_filtra_por_status(): void
    {
        $user = User::factory()->create();
        Candidatura::factory()->for($user)->for(Dispensa::factory()->create(['objeto' => 'Minha salva']))->create();
        Candidatura::factory()->for($user)->for(Dispensa::factory()->create(['objeto' => 'Minha enviada']))->create(['status' => 'enviada']);
        Candidatura::factory()->for(Dispensa::factory()->create(['objeto' => 'De outra pessoa']))->create();

        $this->actingAs($user)->get('/candidaturas')
            ->assertOk()->assertSee('Minha salva')->assertSee('Minha enviada')->assertDontSee('De outra pessoa');
        $this->actingAs($user)->get('/candidaturas?status=enviada')
            ->assertSee('Minha enviada')->assertDontSee('Minha salva');
        $this->actingAs($user)->get('/candidaturas?status=invalido')
            ->assertOk()->assertSee('Minha salva')->assertSee('Minha enviada');
    }

    public function test_candidatura_de_dispensa_encerrada_continua_na_lista(): void
    {
        $user = User::factory()->create();
        Candidatura::factory()->for($user)->for(Dispensa::factory()->encerrada()->create(['objeto' => 'Ja encerrou']))->create();

        $this->actingAs($user)->get('/candidaturas')->assertSee('Ja encerrou');
    }

    public function test_dono_altera_status_e_notas(): void
    {
        $c = Candidatura::factory()->create();

        $this->actingAs($c->user)->patch("/candidaturas/$c->id", ['status' => 'ganhei'])->assertRedirect();
        $this->assertSame('ganhei', $c->fresh()->status->value);

        $this->actingAs($c->user)->patchJson("/candidaturas/$c->id", ['notas' => 'Falta a certidão'])
            ->assertOk()->assertJson(['status' => 'ganhei', 'notas' => 'Falta a certidão']);
        $this->assertSame('Falta a certidão', $c->fresh()->notas);
        $this->assertSame('ganhei', $c->fresh()->status->value);
    }

    public function test_notas_vazias_sao_aceitas_e_gravadas_como_texto_vazio(): void
    {
        $c = Candidatura::factory()->create(['notas' => 'algo']);

        $this->actingAs($c->user)->patchJson("/candidaturas/$c->id", ['notas' => ''])->assertOk();

        $this->assertSame('', $c->fresh()->notas);
    }

    public function test_recusa_status_invalido_e_notas_acima_de_5000_caracteres(): void
    {
        $c = Candidatura::factory()->create();

        $this->actingAs($c->user)->patchJson("/candidaturas/$c->id", ['status' => 'inventado'])->assertStatus(422);
        $this->actingAs($c->user)->patchJson("/candidaturas/$c->id", ['notas' => str_repeat('a', 5001)])->assertStatus(422);
        $this->actingAs($c->user)->patchJson("/candidaturas/$c->id", ['notas' => str_repeat('ã', 5000)])->assertOk();
        $this->assertSame('salva', $c->fresh()->status->value);
    }

    public function test_dono_remove(): void
    {
        $c = Candidatura::factory()->create();

        $this->actingAs($c->user)->delete("/candidaturas/$c->id")->assertRedirect();

        $this->assertSame(0, Candidatura::count());
        $this->assertDatabaseHas('dispensas', ['id' => $c->dispensa_id]);
    }

    public function test_candidatura_de_outro_usuario_responde_404(): void
    {
        $c = Candidatura::factory()->create(['notas' => 'original']);
        $intruso = User::factory()->create();

        $this->actingAs($intruso)->patch("/candidaturas/$c->id", ['status' => 'perdi'])->assertNotFound();
        $this->actingAs($intruso)->patchJson("/candidaturas/$c->id", ['notas' => 'x'])->assertNotFound();
        $this->actingAs($intruso)->delete("/candidaturas/$c->id")->assertNotFound();

        $this->assertSame('original', $c->fresh()->notas);
        $this->assertSame('salva', $c->fresh()->status->value);
    }

    public function test_excluir_a_conta_apaga_as_candidaturas_e_mantem_a_dispensa(): void
    {
        $c = Candidatura::factory()->create();

        $this->actingAs($c->user)->delete('/perfil', ['email_confirmacao' => $c->user->email]);

        $this->assertSame(0, Candidatura::count());
        $this->assertDatabaseHas('dispensas', ['id' => $c->dispensa_id]);
    }

    public function test_radar_e_pagina_mostram_o_estado_de_salva_para_quem_esta_logado(): void
    {
        $user = User::factory()->create();
        $salva = Dispensa::factory()->create();
        $outra = Dispensa::factory()->create();
        Candidatura::factory()->for($user)->for($salva)->create();

        $this->actingAs($user)->get('/')->assertSee('✓ Salva')->assertSee('Salvar');
        $this->actingAs($user)->get("/dispensas/$salva->id")->assertSee('✓ Salva');
        $this->actingAs($user)->get("/dispensas/$outra->id")->assertDontSee('✓ Salva')->assertSee('Salvar');
    }

    public function test_pagina_de_dispensa_encerrada_nao_oferece_salvar(): void
    {
        $d = Dispensa::factory()->encerrada()->create();

        $this->actingAs(User::factory()->create())->get("/dispensas/$d->id")
            ->assertDontSee(route('candidaturas.store', $d), false);
    }

    public function test_gravacoes_tem_limite_de_60_por_minuto_por_usuario(): void
    {
        $c = Candidatura::factory()->create();

        for ($i = 0; $i < 60; $i++) {
            $this->actingAs($c->user)->patchJson("/candidaturas/$c->id", ['notas' => "n$i"])->assertOk();
        }

        $this->actingAs($c->user)->patchJson("/candidaturas/$c->id", ['notas' => 'demais'])->assertStatus(429);
    }

    public function test_ids_gigantes_respondem_404_em_vez_de_estourar_o_banco(): void
    {
        $gigante = '99999999999999999999999';
        $user = User::factory()->create();

        $this->get("/dispensas/$gigante")->assertNotFound();
        $this->actingAs($user)->get("/dispensas/$gigante/entrar")->assertNotFound();
        $this->actingAs($user)->post("/dispensas/$gigante/candidatura")->assertNotFound();
        $this->actingAs($user)->patch("/candidaturas/$gigante", ['status' => 'enviada'])->assertNotFound();
        $this->actingAs($user)->delete("/candidaturas/$gigante")->assertNotFound();
    }

    public function test_card_de_dispensa_encerrada_nao_oferece_enviar_proposta(): void
    {
        $user = User::factory()->create();
        $aberta = Dispensa::factory()->create(['link_origem' => 'https://aberta.example.gov.br/1']);
        $encerrada = Dispensa::factory()->encerrada()->create(['link_origem' => 'https://encerrada.example.gov.br/2']);
        Candidatura::factory()->for($user)->for($aberta)->create();
        Candidatura::factory()->for($user)->for($encerrada)->create();

        $this->actingAs($user)->get('/candidaturas')
            ->assertOk()
            ->assertSee('https://aberta.example.gov.br/1', false)
            ->assertDontSee('https://encerrada.example.gov.br/2', false);
    }

    public function test_filtro_ativo_da_lista_usa_aria_current_e_nao_aria_pressed(): void
    {
        $user = User::factory()->create();

        $html = $this->actingAs($user)->get('/candidaturas?status=enviada')->assertOk()->getContent();

        $this->assertStringNotContainsString('aria-pressed', $html);
        $this->assertSame(1, substr_count($html, 'aria-current="true"'));
    }
}
