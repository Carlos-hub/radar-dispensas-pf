<?php

namespace Tests\Feature;

use App\Console\Commands\SincronizarPncp;
use App\Models\Dispensa;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SincronizarPncpTest extends TestCase
{
    use RefreshDatabase;

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

    /** Simula o PNCP: $paginas[n] é a resposta da página n (array de registros, int de status ou string de corpo cru). */
    private function simularPncp(array $paginas): void
    {
        Http::fake(function (Request $request) use ($paginas) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $resposta = $paginas[(int) ($query['pagina'] ?? 1)] ?? 204;

            return match (true) {
                is_int($resposta) => Http::response('', $resposta),
                is_string($resposta) => Http::response($resposta, 200),
                default => Http::response(['data' => $resposta, 'totalPaginas' => count($paginas), 'totalRegistros' => 0], 200),
            };
        });
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
}
