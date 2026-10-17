<?php

namespace Tests\Feature;

use App\Console\Commands\SincronizarPncp;
use App\Models\Dispensa;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class RadarTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::forever(SincronizarPncp::CHAVE_ULTIMA, now()->toIso8601String());
    }

    private function dispensa(string $objeto, array $extra = []): Dispensa
    {
        return Dispensa::factory()->create(['objeto' => $objeto] + $extra);
    }

    public function test_visitante_ve_as_dispensas_abertas(): void
    {
        $this->dispensa('Palestra aberta');

        $this->get('/')->assertOk()->assertSee('Palestra aberta')->assertSee('Radar de Dispensas PF');
    }

    public function test_esconde_as_encerradas_e_as_que_o_pncp_parou_de_devolver(): void
    {
        $this->dispensa('Dispensa encerrada', ['encerra_em' => now()->subHour()]);
        $this->dispensa('Dispensa retirada', ['sincronizado_em' => now()->subDays(2)]);
        $this->dispensa('Dispensa sem prazo', ['encerra_em' => null]);

        $this->get('/')
            ->assertDontSee('Dispensa encerrada')
            ->assertDontSee('Dispensa retirada')
            ->assertSee('Dispensa sem prazo');
    }

    public function test_filtra_por_uf(): void
    {
        $this->dispensa('Curso em SC', ['uf' => 'SC']);
        $this->dispensa('Curso no PR', ['uf' => 'PR']);

        $this->get('/?uf=PR')->assertSee('Curso no PR')->assertDontSee('Curso em SC');
        $this->get('/?uf=pr')->assertSee('Curso no PR')->assertDontSee('Curso em SC');
    }

    public function test_filtra_por_prazo_com_padrao_de_30_dias(): void
    {
        $this->dispensa('Encerra em 5 dias', ['encerra_em' => now()->addDays(5)]);
        $this->dispensa('Encerra em 20 dias', ['encerra_em' => now()->addDays(20)]);
        $this->dispensa('Encerra em 45 dias', ['encerra_em' => now()->addDays(45)]);

        $this->get('/')->assertSee('Encerra em 20 dias')->assertDontSee('Encerra em 45 dias');
        $this->get('/?dias=7')->assertSee('Encerra em 5 dias')->assertDontSee('Encerra em 20 dias');
        $this->get('/?dias=60')->assertSee('Encerra em 45 dias');
    }

    public function test_filtra_por_valor_maximo_e_mantem_as_de_valor_sigiloso(): void
    {
        $this->dispensa('Servico barato', ['valor' => 5000]);
        $this->dispensa('Servico caro', ['valor' => 50000]);
        $this->dispensa('Servico sigiloso', ['valor' => null]);

        $this->get('/?vmax=10000')
            ->assertSee('Servico barato')
            ->assertSee('Servico sigiloso')
            ->assertDontSee('Servico caro');
    }

    public function test_filtra_por_categoria(): void
    {
        $this->dispensa('Item de TI', ['categorias' => ['ti']]);
        $this->dispensa('Item de ensino', ['categorias' => ['ensino']]);
        $this->dispensa('Item de eventos', ['categorias' => ['eventos', 'conteudo']]);

        $this->get('/?cat[]=ti&cat[]=conteudo')
            ->assertSee('Item de TI')
            ->assertSee('Item de eventos')
            ->assertDontSee('Item de ensino');
    }

    public function test_busca_ignora_acento_e_maiusculas_em_objeto_orgao_e_municipio(): void
    {
        $this->dispensa('Capacitação de servidores');
        $this->dispensa('Outro objeto', ['municipio' => 'São José']);
        $this->dispensa('Terceiro objeto', ['orgao' => 'Câmara Municipal']);
        $this->dispensa('Nada a ver', ['municipio' => 'Lages']);

        $this->get('/?q=capacitacao')->assertSee('Capacitação de servidores')->assertDontSee('Nada a ver');
        $this->get('/?q=SAO+JOSE')->assertSee('Outro objeto')->assertDontSee('Nada a ver');
        $this->get('/?q=camara')->assertSee('Terceiro objeto')->assertDontSee('Nada a ver');
    }

    public function test_mostra_alta_e_verificar_por_padrao_e_as_demais_sob_pedido(): void
    {
        $this->dispensa('Item alta', ['fit' => 'alta']);
        $this->dispensa('Item verificar', ['fit' => 'verificar']);
        $this->dispensa('Item baixa', ['fit' => 'baixa']);
        $this->dispensa('Item fora', ['fit' => 'fora']);

        $this->get('/')->assertSee('Item alta')->assertSee('Item verificar')->assertDontSee('Item baixa')->assertDontSee('Item fora');
        $this->get('/?verificar=0')->assertSee('Item alta')->assertDontSee('Item verificar');
        $this->get('/?baixa=1&fora=1')->assertSee('Item baixa')->assertSee('Item fora');
    }

    public function test_ordena_pelo_prazo_mais_proximo(): void
    {
        $this->dispensa('Encerra depois', ['encerra_em' => now()->addDays(9)]);
        $this->dispensa('Encerra antes', ['encerra_em' => now()->addDays(2)]);
        $this->dispensa('Nunca encerra', ['encerra_em' => null]);

        $this->get('/')->assertSeeInOrder(['Encerra antes', 'Encerra depois', 'Nunca encerra']);
    }

    public function test_pagina_de_30_em_30_e_preserva_os_filtros_nos_links(): void
    {
        Dispensa::factory()->count(31)->create(['uf' => 'PR']);

        $pagina1 = $this->get('/?uf=PR')->assertOk();
        $this->assertSame(30, substr_count($pagina1->getContent(), '<article class="card'));
        $pagina1->assertSee('uf=PR&amp;page=2', false);

        $pagina2 = $this->get('/?uf=PR&page=2')->assertOk();
        $this->assertSame(1, substr_count($pagina2->getContent(), '<article class="card'));
    }

    public function test_resumo_e_calculado_sobre_o_recorte_inteiro_e_nao_so_sobre_a_pagina(): void
    {
        Dispensa::factory()->count(31)->create(['fit' => 'alta', 'valor' => 10000]);
        Dispensa::factory()->count(2)->create(['fit' => 'verificar', 'valor' => 5000]);
        Dispensa::factory()->count(4)->create(['fit' => 'baixa', 'valor' => 99999]);
        Dispensa::factory()->create(['fit' => 'alta', 'valor' => 10000, 'edital_status' => 'excluido']);

        $r = $this->get('/')->assertOk();

        $r->assertViewHas('resumo', [
            'exibidas' => 33,
            'alta' => 32,
            'verificar' => 2,
            'abertas' => 38,
            'descartadas' => 1,
            'estimado' => 320000.0,
        ]);
        $r->assertSee('R$ 320.000,00');
    }

    public function test_sem_resultado_mostra_orientacao(): void
    {
        $this->get('/?q=inexistente')->assertOk()->assertSee('Nenhuma dispensa neste filtro');
    }

    public function test_parametros_hostis_caem_nos_padroes_sem_erro(): void
    {
        $this->dispensa('Item visivel', ['encerra_em' => now()->addDays(20)]);

        foreach ([
            '/?uf=XYZ', '/?uf[]=a', '/?dias=abc', '/?dias=9999', '/?vmax=-5', '/?vmax=abc',
            '/?cat=texto', '/?cat[]=inexistente', '/?cat[x][y]=1', '/?q[]=a', '/?verificar[]=1', '/?page=abc',
        ] as $url) {
            $this->get($url)->assertOk()->assertSee('Item visivel');
        }

        $this->get('/?page=999')->assertOk()->assertSee('Nenhuma dispensa neste filtro');
    }

    public function test_busca_trata_porcentagem_sublinhado_e_aspas_como_texto_comum(): void
    {
        $this->dispensa('Desconto de 50% no servico');
        $this->dispensa('Arquivo relatorio_final');
        $this->dispensa('Texto comum');
        $this->dispensa('Desconto de 50 por cento');
        $this->dispensa('Arquivo relatorioXfinal');

        $this->get('/?q=' . urlencode('50%'))->assertOk()->assertSee('Desconto de 50%')->assertDontSee('Texto comum')->assertDontSee('50 por cento');
        $this->get('/?q=' . urlencode('o_f'))->assertOk()->assertSee('relatorio_final')->assertDontSee('Texto comum')->assertDontSee('relatorioXfinal');
        $this->get('/?q=' . urlencode('%'))->assertOk()->assertSee('Desconto de 50%')->assertDontSee('Texto comum')->assertDontSee('50 por cento')->assertDontSee('relatorioXfinal');
        $this->get('/?q=' . urlencode("'; drop table dispensas; --"))->assertOk()->assertSee('Nenhuma dispensa neste filtro');
        $this->get('/?q=' . urlencode('\\'))->assertOk();
        $this->assertSame(5, Dispensa::count());
    }

    public function test_mostra_a_hora_da_ultima_sincronizacao(): void
    {
        Cache::forever(SincronizarPncp::CHAVE_ULTIMA, now()->subMinutes(10)->toIso8601String());

        $this->get('/')->assertSee('Atualizado')->assertDontSee('podem estar desatualizados');
    }

    public function test_avisa_quando_a_sincronizacao_tem_mais_de_1_hora_ou_nunca_rodou(): void
    {
        Cache::forever(SincronizarPncp::CHAVE_ULTIMA, now()->subHours(2)->toIso8601String());
        $this->get('/')->assertSee('podem estar desatualizados');

        Cache::forget(SincronizarPncp::CHAVE_ULTIMA);
        $this->get('/')->assertSee('podem estar desatualizados');
    }

    // --- B3: a janela de 24 h conta da última sincronização bem-sucedida ---

    public function test_apos_dias_sem_sincronizar_a_lista_e_o_sitemap_continuam_com_o_que_havia(): void
    {
        $ultima = now()->subDays(3);
        Cache::forever(SincronizarPncp::CHAVE_ULTIMA, $ultima->toIso8601String());
        $visivel = $this->dispensa('Palestra da ultima rodada', ['sincronizado_em' => $ultima]);
        $limite = $this->dispensa('Palestra no limite da janela', ['sincronizado_em' => $ultima->copy()->subHours(23)]);
        $fora = $this->dispensa('Palestra fora da janela', ['sincronizado_em' => $ultima->copy()->subHours(25)]);

        $this->get('/')
            ->assertSee('Palestra da ultima rodada')
            ->assertSee('Palestra no limite da janela')
            ->assertDontSee('Palestra fora da janela')
            ->assertSee('podem estar desatualizados');

        $sitemap = $this->get('/sitemap.xml')->getContent();
        $this->assertStringContainsString(route('dispensas.show', $visivel), $sitemap);
        $this->assertStringContainsString(route('dispensas.show', $limite), $sitemap);
        $this->assertStringNotContainsString(route('dispensas.show', $fora), $sitemap);
    }

    public function test_dispensa_encerrada_continua_escondida_mesmo_na_janela_antiga(): void
    {
        $ultima = now()->subDays(3);
        Cache::forever(SincronizarPncp::CHAVE_ULTIMA, $ultima->toIso8601String());
        $this->dispensa('Palestra ja encerrada', ['sincronizado_em' => $ultima, 'encerra_em' => now()->subHour()]);

        $this->get('/')->assertDontSee('Palestra ja encerrada');
    }

    public function test_sem_sincronizacao_registrada_a_janela_conta_do_relogio(): void
    {
        Cache::forget(SincronizarPncp::CHAVE_ULTIMA);
        $this->dispensa('Palestra de agora');
        $this->dispensa('Palestra de anteontem', ['sincronizado_em' => now()->subDays(2)]);

        $this->get('/')->assertSee('Palestra de agora')->assertDontSee('Palestra de anteontem');
    }

    public function test_categorias_sao_checkboxes_focaveis_e_marcados_conforme_o_filtro(): void
    {
        $html = $this->get('/?cat[]=ti')->assertOk()->getContent();

        preg_match_all('/<input[^>]*name="cat\[\]"[^>]*>/', $html, $m);
        $this->assertNotEmpty($m[0]);
        foreach ($m[0] as $input) {
            $this->assertDoesNotMatchRegularExpression('/\shidden(\s|>|=)/', $input, 'checkbox com hidden não recebe foco');
        }
        $this->assertMatchesRegularExpression('/<input[^>]*value="ti"[^>]*checked/', $html);
        $this->assertDoesNotMatchRegularExpression('/<input[^>]*value="ensino"[^>]*checked/', $html);
        $this->assertStringNotContainsString('aria-pressed', $html);
    }

    public function test_descartados_ficam_ocultos_e_voltam_com_o_motivo(): void
    {
        $this->dispensa('Licenca do CapCut', ['edital_status' => 'excluido', 'edital_motivo' => 'licenca de uso / capcut']);
        $this->dispensa('Sistema de protocolo', ['edital_status' => 'incluido']);

        $this->get('/')->assertSee('Sistema de protocolo')->assertDontSee('Licenca do CapCut');
        $this->get('/?descartados=1')
            ->assertSee('Licenca do CapCut')
            ->assertSee('Descartado pelo objeto: licenca de uso / capcut')
            ->assertSee('edital-excluido');
    }

    public function test_neutros_aparecem_esmaecidos(): void
    {
        $this->dispensa('Palestra neutra', ['edital_status' => 'neutro']);

        $this->get('/')->assertSee('Palestra neutra')->assertSee('edital-neutro');
    }

    public function test_esconde_valor_abaixo_do_minimo_e_mantem_os_sigilosos(): void
    {
        $this->dispensa('Servico de 4999', ['valor' => 4999]);
        $this->dispensa('Servico de 5000', ['valor' => 5000]);
        $this->dispensa('Servico sem valor', ['valor' => null]);

        $this->get('/')->assertDontSee('Servico de 4999')->assertSee('Servico de 5000')->assertSee('Servico sem valor');
        $this->get('/?pequenas=1')->assertSee('Servico de 4999');
    }

    public function test_valor_minimo_vem_da_configuracao(): void
    {
        config(['radar.valor_minimo' => 20000]);
        $this->dispensa('Servico de 10000', ['valor' => 10000]);

        $this->get('/')->assertDontSee('Servico de 10000');
    }

    public function test_mostra_selos_de_barreira_e_perfil(): void
    {
        $this->dispensa('Sistema com barreiras', [
            'edital_status' => 'incluido', 'perfil' => 'construivel', 'revisar_misto' => true,
            'barreiras' => ['me_epp' => true, 'experiencia_anos' => 5],
        ]);
        $this->dispensa('Sistema sem texto', ['barreiras' => null]);

        $this->get('/')
            ->assertSee('Exclusivo ME/EPP')
            ->assertSee('Atestado 5 anos')
            ->assertSee('Construível')
            ->assertSee('Revisar: misto')
            ->assertSee('Barreiras não verificadas');
    }

    public function test_filtra_por_quem_pode_participar(): void
    {
        $this->dispensa('Edital aceita PF', ['barreiras' => ['pf_aceita' => true]]);
        $this->dispensa('Edital so ME EPP', ['barreiras' => ['me_epp' => true, 'atestado' => true]]);
        $this->dispensa('Edital sem texto', ['barreiras' => null]);

        $this->get('/?participacao=pf')->assertSee('Edital aceita PF')->assertDontSee('Edital so ME EPP')->assertDontSee('Edital sem texto');
        $this->get('/?participacao=me_epp')->assertSee('Edital so ME EPP')->assertDontSee('Edital aceita PF')->assertDontSee('Edital sem texto');
        $this->get('/?participacao=nao_verificado')->assertSee('Edital sem texto')->assertDontSee('Edital aceita PF');
        $this->get('/?participacao=xyz')->assertSee('Edital aceita PF')->assertSee('Edital sem texto');
    }

    public function test_filtra_por_perfil(): void
    {
        $this->dispensa('Edital construivel', ['edital_status' => 'incluido', 'perfil' => 'construivel']);
        $this->dispensa('Edital produto pronto', ['edital_status' => 'incluido', 'perfil' => 'produto_pronto']);

        $this->get('/?perfil=construivel')->assertSee('Edital construivel')->assertDontSee('Edital produto pronto');
        $this->get('/?perfil=produto_pronto')->assertSee('Edital produto pronto')->assertDontSee('Edital construivel');
    }
}
