<?php

namespace App\Services\Pncp;

use Generator;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/** Cliente da API de consulta do PNCP: dispensas com propostas abertas. */
class PncpClient
{
    public const POR_PAGINA = 50;
    public const TETO_PAGINAS = 400;      // teto de segurança: 20.000 registros
    private const PARALELAS = 6;          // conexões simultâneas, para não estressar a API
    private const MODALIDADE_DISPENSA = 8;

    /** Verdadeiro quando a última consulta tinha mais páginas que o teto. */
    public bool $truncado = false;

    /**
     * Percorre as páginas de dispensas do Brasil todo que encerram em até $dias dias.
     *
     * @return Generator<int, list<array>> a lista de registros brutos de cada página
     */
    public function paginas(int $dias = 90): Generator
    {
        $this->truncado = false;
        $url = config('services.pncp.base') . '/contratacoes/proposta';
        $query = fn (int $pagina) => [
            'dataFinal' => now()->addDays($dias)->format('Ymd'),
            'codigoModalidadeContratacao' => self::MODALIDADE_DISPENSA,
            'pagina' => $pagina,
            'tamanhoPagina' => self::POR_PAGINA,
        ];

        $primeira = $this->decodificar($this->tentar(fn () => $this->requisicao()->get($url, $query(1))));
        yield $primeira['data'] ?? [];

        $total = (int) ($primeira['totalPaginas'] ?? 1);
        $this->truncado = $total > self::TETO_PAGINAS;
        $ultima = min($total, self::TETO_PAGINAS);
        if ($ultima < 2) {
            return;
        }

        foreach (array_chunk(range(2, $ultima), self::PARALELAS) as $lote) {
            $respostas = Http::pool(fn (Pool $pool) => array_map(
                fn (int $pagina) => $pool->acceptJson()->withUserAgent('radar-pf/1.0')->timeout(30)->connectTimeout(10)->get($url, $query($pagina)),
                $lote
            ));
            foreach ($respostas as $resposta) {
                yield $this->decodificar($resposta)['data'] ?? [];
            }
        }
    }

    private function requisicao()
    {
        return Http::acceptJson()->withUserAgent('radar-pf/1.0')->timeout(30)->connectTimeout(10);
    }

    private function tentar(callable $fn): Response|Throwable
    {
        try {
            return $fn();
        } catch (Throwable $e) {
            return $e;
        }
    }

    /** No pool, falha de conexão chega como exceção no lugar da resposta. */
    private function decodificar(Response|Throwable $resposta): array
    {
        if ($resposta instanceof Throwable) {
            throw new RuntimeException('Falha ao consultar o PNCP: ' . $resposta->getMessage(), 0, $resposta);
        }
        if ($resposta->status() === 204) {
            return ['data' => []];
        }
        if ($resposta->status() !== 200) {
            throw new RuntimeException('PNCP respondeu HTTP ' . $resposta->status() . '.');
        }
        if ($resposta->body() === '') {
            return ['data' => []];
        }
        $json = $resposta->json();
        if (! is_array($json)) {
            throw new RuntimeException('Resposta inválida do PNCP.');
        }

        return $json;
    }
}
