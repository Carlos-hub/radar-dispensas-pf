<?php

namespace Tests\Feature;

use App\Models\Dispensa;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IndexacaoTest extends TestCase
{
    use RefreshDatabase;

    public function test_radar_sem_filtro_e_por_uf_sao_indexaveis_com_url_canonica(): void
    {
        $this->get('/')
            ->assertDontSee('noindex', false)
            ->assertSee('<link rel="canonical" href="' . url('/') . '">', false);

        $this->get('/?uf=SC')
            ->assertDontSee('noindex', false)
            ->assertSee('<link rel="canonical" href="' . url('/') . '?uf=SC">', false);
    }

    public function test_outros_filtros_paginas_seguintes_e_uf_invalida_recebem_noindex(): void
    {
        foreach (['/?q=curso', '/?uf=SC&dias=7', '/?cat[]=ti', '/?page=2', '/?uf=SC&page=2', '/?uf=XYZ', '/?verificar=0'] as $url) {
            $this->get($url)->assertOk()->assertSee('<meta name="robots" content="noindex', false);
        }
    }

    public function test_pagina_da_dispensa_tem_titulo_descricao_e_canonica_proprios(): void
    {
        $d = Dispensa::factory()->create(['objeto' => 'Oficina de fotografia', 'orgao' => 'Prefeitura De Lages', 'municipio' => 'Lages']);

        $this->get("/dispensas/$d->id?origem=x")
            ->assertSee('<title>Oficina de fotografia · Prefeitura De Lages</title>', false)
            ->assertSee('Dispensa de licitação em Lages/SC', false)
            ->assertSee('<link rel="canonical" href="' . route('dispensas.show', $d) . '">', false)
            ->assertDontSee('noindex', false);
    }

    public function test_sitemap_lista_o_radar_cada_uf_e_as_dispensas_abertas(): void
    {
        $aberta = Dispensa::factory()->create();
        $encerrada = Dispensa::factory()->encerrada()->create();

        $r = $this->get('/sitemap.xml')->assertOk();

        $this->assertStringContainsString('xml', $r->headers->get('Content-Type'));
        $xml = simplexml_load_string($r->getContent());
        $locs = [];
        foreach ($xml->url as $url) {
            $locs[] = (string) $url->loc;
        }

        $this->assertContains(url('/'), $locs);
        $this->assertContains(url('/') . '?uf=SC', $locs);
        $this->assertContains(route('dispensas.show', $aberta), $locs);
        $this->assertNotContains(route('dispensas.show', $encerrada), $locs);
        $this->assertCount(1 + 27 + 1, $locs);
    }

    public function test_robots_aponta_o_sitemap_e_bloqueia_as_rotas_de_conta(): void
    {
        $r = $this->get('/robots.txt')->assertOk();

        $this->assertStringContainsString('text/plain', $r->headers->get('Content-Type'));
        $r->assertSee('Sitemap: ' . url('/sitemap.xml'), false)
            ->assertSee('Disallow: /candidaturas', false)
            ->assertSee('Disallow: /perfil', false)
            ->assertSee('Disallow: /login', false);
    }
}
