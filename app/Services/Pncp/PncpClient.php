<?php

namespace App\Services\Pncp;

use Generator;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use RuntimeException;
use Throwable;

/** Cliente da API de consulta do PNCP: dispensas com propostas abertas. */
class PncpClient
{
    public const POR_PAGINA = 50;
    public const TETO_PAGINAS = 400;      // teto de segurança: 20.000 registros
    private const PARALELAS = 2;          // conexões simultâneas: com mais, o PNCP responde 429
    private const PAUSA_ENTRE_LOTES_MS = 300;
    private const TENTATIVAS = 4;         // 1 tentativa + 3 repetições
    private const ESPERAS_S = [5, 15, 30]; // espera antes de cada repetição, sem Retry-After
    private const ESPERA_MAXIMA_S = 60;   // teto para o Retry-After
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

        $buscar = fn (array $paginas) => $this->lote($paginas, $url, $query);

        $primeira = $buscar([1])[1];
        yield $primeira['data'] ?? [];

        $total = (int) ($primeira['totalPaginas'] ?? 1);
        $this->truncado = $total > self::TETO_PAGINAS;
        $ultima = min($total, self::TETO_PAGINAS);
        if ($ultima < 2) {
            return;
        }

        foreach (array_chunk(range(2, $ultima), self::PARALELAS) as $i => $paginas) {
            if ($i > 0) {
                Sleep::for(self::PAUSA_ENTRE_LOTES_MS)->milliseconds();
            }
            foreach ($buscar($paginas) as $json) {
                yield $json['data'] ?? [];
            }
        }
    }

    /**
     * Busca as páginas em paralelo, repetindo só as que falharam (429, 5xx ou conexão).
     *
     * @param  list<int>  $paginas
     * @return array<int, array> o JSON decodificado de cada página, na ordem pedida
     */
    private function lote(array $paginas, string $url, callable $query): array
    {
        $prontas = [];
        $pendentes = $paginas;

        for ($tentativa = 1; ; $tentativa++) {
            $respostas = Http::pool(fn (Pool $pool) => array_map(
                fn (int $pagina) => $pool->acceptJson()->withUserAgent('radar-pf/1.0')->timeout(30)->connectTimeout(10)->get($url, $query($pagina)),
                $pendentes
            ));

            $falhas = [];
            foreach ($pendentes as $i => $pagina) {
                $resposta = $respostas[$i];
                if ($this->repetivel($resposta)) {
                    $falhas[$pagina] = $resposta;
                } else {
                    $prontas[$pagina] = $this->decodificar($resposta);
                }
            }

            if (! $falhas) {
                break;
            }
            if ($tentativa === self::TENTATIVAS) {
                $this->decodificar(reset($falhas));   // lança a exceção da falha
            }
            Sleep::for($this->espera($falhas, $tentativa))->seconds();
            $pendentes = array_keys($falhas);
        }

        return array_replace(array_flip($paginas), $prontas);
    }

    private function repetivel(Response|Throwable $resposta): bool
    {
        return $resposta instanceof Throwable || $resposta->status() === 429 || $resposta->status() >= 500;
    }

    /** Maior espera pedida pelas falhas: Retry-After (positivo, limitado) ou a escala fixa. */
    private function espera(array $falhas, int $tentativa): int
    {
        $espera = self::ESPERAS_S[$tentativa - 1];
        $pedidos = [];
        foreach ($falhas as $resposta) {
            $h = $resposta instanceof Response ? $resposta->header('Retry-After') : '';
            if (ctype_digit($h) && (int) $h > 0) {
                $pedidos[] = min((int) $h, self::ESPERA_MAXIMA_S);
            } else {
                $pedidos[] = $espera;
            }
        }

        return max($pedidos);
    }

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
