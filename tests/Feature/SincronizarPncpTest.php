<?php

namespace Tests\Feature;

use App\Console\Commands\SincronizarPncp;
use App\Models\Dispensa;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
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
}
