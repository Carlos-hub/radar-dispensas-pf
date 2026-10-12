<?php

namespace Tests\Feature;

use App\Console\Commands\SincronizarPncp;
use App\Models\Dispensa;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class SincronizarPncpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
    }

    /** Resposta de erro com cabeçalho Retry-After, para uso em sequências. */
    private function erro(int $status, ?string $retryAfter = null): array
    {
        return ['__erro' => $status, 'retry' => $retryAfter];
    }

    private function registro(string $id, string $objeto = 'Palestra sobre gestão'): array
    {
        return [
            'numeroControlePNCP' => $id,
            'objetoCompra' => $objeto,
            'orgaoEntidade' => ['cnpj' => '12345678000199', 'razaoSocial' => 'MUNICIPIO X', 'esferaId' => 'M'],
            'unidadeOrgao' => ['municipioNome' => 'Chapecó', 'ufSigla' => 'SC'],
            'valorTotalEstimado' => 5000,
            'dataEncerramentoProposta' => now()->addDays(3)->format('Y-m-d\TH:i:s'),
            'amparoLegal' => ['nome' => 'Lei 14.133/2021, Art. 75, II'],
            'anoCompra' => 2026,
            'sequencialCompra' => 1,
        ];
    }

    /**
     * Simula o PNCP: $paginas[n] é a resposta da página n (array de registros, int de status ou string de corpo cru).
     * Para respostas diferentes a cada chamada, use ['__seq' => [resposta1, resposta2, ...]]; a última se repete.
     */
    private function simularPncp(array $paginas): void
    {
        $chamadas = [];
        Http::fake(function (Request $request) use ($paginas, &$chamadas) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $n = (int) ($query['pagina'] ?? 1);
            $resposta = $paginas[$n] ?? 204;
            if (is_array($resposta) && isset($resposta['__seq'])) {
                $i = $chamadas[$n] = ($chamadas[$n] ?? -1) + 1;
                $resposta = $resposta['__seq'][min($i, count($resposta['__seq']) - 1)];
            }

            return match (true) {
                is_int($resposta) => Http::response('', $resposta),
                is_string($resposta) => Http::response($resposta, 200),
                isset($resposta['__erro']) => Http::response('', $resposta['__erro'], $resposta['retry'] !== null ? ['Retry-After' => $resposta['retry']] : []),
                default => Http::response(['data' => $resposta, 'totalPaginas' => count($paginas), 'totalRegistros' => 0], 200),
            };
        });
    }

    private function chamadasDaPagina(int $pagina): int
    {
        return Http::recorded(function (Request $request) use ($pagina) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);

            return (int) $q['pagina'] === $pagina;
        })->count();
    }

    public function test_grava_os_registros_de_todas_as_paginas_ja_classificados(): void
    {
        $this->simularPncp([1 => [$this->registro('a-1')], 2 => [$this->registro('a-2')], 3 => [$this->registro('a-3')]]);

        $this->artisan('pncp:sync')->assertSuccessful();

        $this->assertSame(3, Dispensa::count());
        $d = Dispensa::where('pncp_id', 'a-1')->firstOrFail();
        $this->assertSame('alta', $d->fit->value);
        $this->assertSame(['ensino'], $d->categorias);
        $this->assertSame('SC', $d->uf);
        $this->assertNotNull($d->sincronizado_em);
    }

    public function test_consulta_dispensas_do_brasil_todo_com_janela_de_90_dias(): void
    {
        $this->simularPncp([1 => [$this->registro('a-1')]]);

        $this->artisan('pncp:sync')->assertSuccessful();

        Http::assertSent(function (Request $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);

            return str_contains($request->url(), '/contratacoes/proposta')
                && $q['codigoModalidadeContratacao'] === '8'
                && $q['tamanhoPagina'] === '50'
                && $q['dataFinal'] === now()->addDays(90)->format('Ymd')
                && ! isset($q['uf']);
        });
    }

    public function test_atualiza_o_registro_existente_em_vez_de_duplicar(): void
    {
        Dispensa::factory()->create(['pncp_id' => 'a-1', 'objeto' => 'Texto antigo', 'sincronizado_em' => now()->subDays(3)]);
        $this->simularPncp([1 => [$this->registro('a-1', 'Curso de capacitação')]]);

        $this->artisan('pncp:sync')->assertSuccessful();

        $this->assertSame(1, Dispensa::count());
        $d = Dispensa::firstOrFail();
        $this->assertSame('Curso de capacitação', $d->objeto);
        $this->assertTrue($d->sincronizado_em->isToday());
    }

    public function test_mesmo_id_repetido_na_pagina_grava_uma_linha_so(): void
    {
        $this->simularPncp([1 => [$this->registro('a-1', 'Primeiro'), $this->registro('a-1', 'Segundo')]]);

        $this->artisan('pncp:sync')->assertSuccessful();

        $this->assertSame(1, Dispensa::count());
        $this->assertSame('Segundo', Dispensa::firstOrFail()->objeto);
    }

    public function test_ignora_registro_sem_identificador(): void
    {
        $this->simularPncp([1 => [['objetoCompra' => 'Sem id'], $this->registro('a-1')]]);

        $this->artisan('pncp:sync')->assertSuccessful();

        $this->assertSame(1, Dispensa::count());
    }

    public function test_resposta_204_significa_nenhuma_dispensa(): void
    {
        $this->simularPncp([1 => 204]);

        $this->artisan('pncp:sync')->assertSuccessful();

        $this->assertSame(0, Dispensa::count());
        $this->assertNotNull(Cache::get(SincronizarPncp::CHAVE_ULTIMA));
    }

    public function test_falha_no_meio_preserva_o_que_ja_foi_gravado_e_termina_com_erro(): void
    {
        $this->simularPncp([1 => [$this->registro('a-1')], 2 => 500, 3 => [$this->registro('a-3')]]);

        $this->artisan('pncp:sync')->assertFailed();

        $this->assertDatabaseHas('dispensas', ['pncp_id' => 'a-1']);
        $this->assertNull(Cache::get(SincronizarPncp::CHAVE_ULTIMA));
    }

    public function test_corpo_que_nao_e_json_termina_com_erro(): void
    {
        $this->simularPncp([1 => '<html>manutenção</html>']);

        $this->artisan('pncp:sync')->assertFailed();
    }

    public function test_sucesso_registra_a_data_da_ultima_sincronizacao(): void
    {
        $this->simularPncp([1 => [$this->registro('a-1')]]);

        $this->artisan('pncp:sync')->assertSuccessful();

        $this->assertTrue(now()->parse(Cache::get(SincronizarPncp::CHAVE_ULTIMA))->isToday());
    }

    public function test_pagina_com_429_e_tentada_de_novo_apos_5_segundos(): void
    {
        $this->simularPncp([1 => [$this->registro('a-1')], 2 => ['__seq' => [$this->erro(429), [$this->registro('a-2')]]]]);

        $this->artisan('pncp:sync')->assertSuccessful();

        $this->assertSame(2, Dispensa::count());
        Sleep::assertSequence([Sleep::for(5)->seconds()]);
    }

    public function test_retry_after_define_a_espera(): void
    {
        $this->simularPncp([1 => [$this->registro('a-1')], 2 => ['__seq' => [$this->erro(429, '2'), [$this->registro('a-2')]]]]);

        $this->artisan('pncp:sync')->assertSuccessful();

        Sleep::assertSequence([Sleep::for(2)->seconds()]);
    }

    public function test_retry_after_e_limitado_a_60_segundos(): void
    {
        $this->simularPncp([1 => [$this->registro('a-1')], 2 => ['__seq' => [$this->erro(429, '600'), [$this->registro('a-2')]]]]);

        $this->artisan('pncp:sync')->assertSuccessful();

        Sleep::assertSequence([Sleep::for(60)->seconds()]);
    }

    public function test_erro_500_em_todas_as_4_tentativas_falha_e_preserva_o_anterior(): void
    {
        $this->simularPncp([1 => [$this->registro('a-1')], 2 => 500]);

        $this->artisan('pncp:sync')->assertFailed();

        $this->assertSame(4, $this->chamadasDaPagina(2));
        $this->assertDatabaseHas('dispensas', ['pncp_id' => 'a-1']);
        $this->assertNull(Cache::get(SincronizarPncp::CHAVE_ULTIMA));
        Sleep::assertSequence([Sleep::for(5)->seconds(), Sleep::for(15)->seconds(), Sleep::for(30)->seconds()]);
    }

    public function test_primeira_pagina_tambem_e_tentada_de_novo(): void
    {
        $this->simularPncp([1 => ['__seq' => [$this->erro(503), [$this->registro('a-1')]]]]);

        $this->artisan('pncp:sync')->assertSuccessful();

        $this->assertSame(1, Dispensa::count());
        $this->assertSame(2, $this->chamadasDaPagina(1));
    }

    public function test_cinco_paginas_sao_pedidas_uma_vez_cada_com_pausa_entre_lotes(): void
    {
        $this->simularPncp([
            1 => [$this->registro('a-1')], 2 => [$this->registro('a-2')], 3 => [$this->registro('a-3')],
            4 => [$this->registro('a-4')], 5 => [$this->registro('a-5')],
        ]);

        $this->artisan('pncp:sync')->assertSuccessful();

        $this->assertSame(5, Dispensa::count());
        foreach (range(1, 5) as $pagina) {
            $this->assertSame(1, $this->chamadasDaPagina($pagina));
        }
        Sleep::assertSequence([Sleep::for(300)->milliseconds()]);
    }

    public function test_so_a_pagina_que_falhou_no_lote_e_repetida(): void
    {
        $this->simularPncp([1 => [$this->registro('a-1')], 2 => ['__seq' => [500, [$this->registro('a-2')]]], 3 => [$this->registro('a-3')]]);

        $this->artisan('pncp:sync')->assertSuccessful();

        $this->assertSame(3, Dispensa::count());
        $this->assertSame(2, $this->chamadasDaPagina(2));
        $this->assertSame(1, $this->chamadasDaPagina(3));
    }

    // --- B1: exclusão mútua dentro do próprio comando ---

    public function test_com_o_lock_ocupado_nao_chama_o_pncp_e_termina_sem_erro(): void
    {
        Http::fake();
        $lock = Cache::lock(SincronizarPncp::CHAVE_LOCK, 60);
        $this->assertTrue($lock->get());

        $this->artisan('pncp:sync')->assertSuccessful()->expectsOutputToContain('já está em andamento');

        Http::assertNothingSent();
        $this->assertNull(Cache::get(SincronizarPncp::CHAVE_ULTIMA));
        $this->assertTrue($lock->isOwnedByCurrentProcess(), 'o comando não pode soltar o lock de outro processo');
    }

    public function test_lock_e_liberado_apos_execucao_normal(): void
    {
        $this->simularPncp([1 => [$this->registro('a-1')]]);

        $this->artisan('pncp:sync')->assertSuccessful();

        $this->assertLockLivre();
        $this->artisan('pncp:sync')->assertSuccessful();
    }

    public function test_lock_e_liberado_apos_execucao_com_falha(): void
    {
        // 4 tentativas falham na primeira execução; a segunda recebe uma página boa
        $this->simularPncp([1 => ['__seq' => [500, 500, 500, 500, [$this->registro('a-1')]]]]);

        $this->artisan('pncp:sync')->assertFailed();

        $this->assertLockLivre();
        $this->artisan('pncp:sync')->assertSuccessful();
        $this->assertSame(1, Dispensa::count());
    }

    public function test_agendamento_expira_o_mutex_em_30_minutos(): void
    {
        $evento = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->first(fn ($e) => str_contains($e->command, 'pncp:sync'));

        $this->assertNotNull($evento);
        $this->assertTrue($evento->withoutOverlapping);
        $this->assertSame(30, $evento->expiresAt);
    }

    private function assertLockLivre(): void
    {
        $lock = Cache::lock(SincronizarPncp::CHAVE_LOCK, 10);
        $this->assertTrue($lock->get(), 'o lock deveria estar livre');
        $lock->release();
    }

    // --- B2: um registro ou uma página ruim não derruba a sincronização ---

    public function test_registro_envenenado_e_pulado_e_os_bons_sao_gravados(): void
    {
        $this->simularPncp([1 => [
            $this->registro('bom-1'),
            ['numeroControlePNCP' => 'ruim-1', 'objetoCompra' => ['nao', 'e', 'texto']] + $this->registro('ruim-1'),
            $this->registro('bom-2'),
        ]]);
        Log::spy();

        $this->artisan('pncp:sync')->assertSuccessful();

        $this->assertEqualsCanonicalizing(['bom-1', 'bom-2'], Dispensa::pluck('pncp_id')->all());
        $this->assertNotNull(Cache::get(SincronizarPncp::CHAVE_ULTIMA));
        Log::shouldHaveReceived('warning')->withArgs(fn ($msg, $ctx = []) => ($ctx['pncp_id'] ?? null) === 'ruim-1')->once();
    }

    public function test_data_invalida_vira_nula(): void
    {
        $this->simularPncp([1 => [
            ['dataAberturaProposta' => 'ontem de manhã', 'dataEncerramentoProposta' => '2026-13-45T99:00:00'] + $this->registro('a-1'),
            ['dataAberturaProposta' => ['x'], 'dataEncerramentoProposta' => '2026-10-20T08:00:00'] + $this->registro('a-2'),
        ]]);

        $this->artisan('pncp:sync')->assertSuccessful();

        $a1 = Dispensa::where('pncp_id', 'a-1')->firstOrFail();
        $this->assertNull($a1->abre_em);
        $this->assertNull($a1->encerra_em);
        $a2 = Dispensa::where('pncp_id', 'a-2')->firstOrFail();
        $this->assertNull($a2->abre_em);
        $this->assertSame('2026-10-20 08:00', $a2->encerra_em->format('Y-m-d H:i'));
    }

    public function test_fuso_fora_da_faixa_vira_data_nula_e_nao_derruba_a_pagina(): void
    {
        $this->simularPncp([1 => [
            ['dataEncerramentoProposta' => '2026-10-20T08:00:00+16:00'] + $this->registro('a-1'),
            ['dataEncerramentoProposta' => '2026-10-20T08:00:00-03:99'] + $this->registro('a-2'),
            ['dataEncerramentoProposta' => '2026-10-20T08:00:00-03:00'] + $this->registro('a-3'),
        ]]);

        $this->artisan('pncp:sync')->assertSuccessful();

        $this->assertNull(Dispensa::where('pncp_id', 'a-1')->firstOrFail()->encerra_em);
        $this->assertNull(Dispensa::where('pncp_id', 'a-2')->firstOrFail()->encerra_em);
        $this->assertNotNull(Dispensa::where('pncp_id', 'a-3')->firstOrFail()->encerra_em);
        $this->assertNotNull(Cache::get(SincronizarPncp::CHAVE_ULTIMA));
    }

    public function test_valor_fora_da_faixa_vira_nulo(): void
    {
        $this->simularPncp([1 => [
            ['valorTotalEstimado' => 1e14] + $this->registro('a-1'),
            ['valorTotalEstimado' => 9999999999999.99] + $this->registro('a-2'),
            ['valorTotalEstimado' => 'abc'] + $this->registro('a-3'),
        ]]);

        $this->artisan('pncp:sync')->assertSuccessful();

        $this->assertNull(Dispensa::where('pncp_id', 'a-1')->firstOrFail()->valor);
        $this->assertSame(9999999999999.99, Dispensa::where('pncp_id', 'a-2')->firstOrFail()->valor);
        $this->assertNull(Dispensa::where('pncp_id', 'a-3')->firstOrFail()->valor);
    }

    public function test_esfera_nao_escalar_nao_derruba_a_sincronizacao(): void
    {
        $registro = $this->registro('a-1');
        $registro['orgaoEntidade']['esferaId'] = ['M'];
        $this->simularPncp([1 => [$registro, $this->registro('a-2')]]);

        $this->artisan('pncp:sync')->assertSuccessful();

        $this->assertSame(2, Dispensa::count());
        $this->assertSame('Não informada', Dispensa::where('pncp_id', 'a-1')->firstOrFail()->esfera);
    }

    public function test_link_de_origem_nao_textual_vira_nulo(): void
    {
        $this->simularPncp([1 => [['linkSistemaOrigem' => ['http://x']] + $this->registro('a-1')]]);

        $this->artisan('pncp:sync')->assertSuccessful();

        $this->assertNull(Dispensa::firstOrFail()->link_origem);
    }

    public function test_pagina_que_o_banco_recusa_nao_impede_as_seguintes_mas_a_execucao_falha(): void
    {
        // restrição só deste teste (o DDL do PostgreSQL é transacional e some no rollback): o upsert da página 2 falha
        DB::statement("alter table dispensas add constraint sem_veneno check (objeto <> 'VENENO')");
        $this->simularPncp([
            1 => [$this->registro('p1-1')],
            2 => [$this->registro('p2-1', 'VENENO')],
            3 => [$this->registro('p3-1')],
        ]);

        $this->artisan('pncp:sync')->assertFailed();

        $this->assertEqualsCanonicalizing(['p1-1', 'p3-1'], Dispensa::pluck('pncp_id')->all());
        $this->assertNull(Cache::get(SincronizarPncp::CHAVE_ULTIMA));
        $this->assertLockLivre();
    }
}
