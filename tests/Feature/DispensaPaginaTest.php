<?php

namespace Tests\Feature;

use App\Models\Dispensa;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DispensaPaginaTest extends TestCase
{
    use RefreshDatabase;

    public function test_visitante_ve_a_pagina_da_dispensa_com_os_links_oficiais(): void
    {
        $d = Dispensa::factory()->create([
            'objeto' => 'Desenvolvimento de portal',
            'complemento' => 'Detalhes do termo de referência',
            'orgao' => 'Prefeitura De Xanxerê',
        ]);

        $this->get("/dispensas/$d->id")
            ->assertOk()
            ->assertSee('Desenvolvimento de portal')
            ->assertSee('Prefeitura De Xanxerê')
            ->assertSee('Detalhes do termo de referência')
            ->assertSee($d->motivo)
            ->assertSee('classificação é automática')
            ->assertSee($d->link_pncp, false)
            ->assertSee($d->link_origem, false);
    }

    public function test_dispensa_encerrada_continua_visivel_e_marcada(): void
    {
        $d = Dispensa::factory()->encerrada()->create(['objeto' => 'Dispensa antiga']);

        $this->get("/dispensas/$d->id")->assertOk()->assertSee('Dispensa antiga')->assertSee('Encerrada');
    }

    public function test_dispensa_inexistente_responde_404(): void
    {
        $this->get('/dispensas/999999')->assertNotFound();
        $this->get('/dispensas/abc')->assertNotFound();
    }

    public function test_link_que_nao_e_http_nao_e_renderizado(): void
    {
        $d = Dispensa::factory()->create(['link_origem' => 'javascript:alert(1)', 'link_pncp' => 'data:text/html,x']);

        $this->get("/dispensas/$d->id")->assertOk()->assertDontSee('javascript:alert', false)->assertDontSee('data:text/html', false);
        $this->get('/')->assertOk()->assertDontSee('javascript:alert', false);
    }

    public function test_texto_do_pncp_e_escapado(): void
    {
        $d = Dispensa::factory()->create(['objeto' => 'Serviço <script>alert(1)</script>']);

        $this->get("/dispensas/$d->id")->assertOk()->assertDontSee('<script>alert(1)</script>', false);
    }
}
