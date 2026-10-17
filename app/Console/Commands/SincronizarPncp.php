<?php

namespace App\Console\Commands;

use App\Models\Dispensa;
use App\Services\ClassificadorPf;
use App\Services\Pncp\PncpClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class SincronizarPncp extends Command
{
    public const CHAVE_ULTIMA = Dispensa::CHAVE_ULTIMA_SINCRONIZACAO;

    public const CHAVE_LOCK = 'pncp:sync';

    /** Validade do lock: uma execução travada libera a vez em 30 minutos, não em 24 horas. */
    public const LOCK_MINUTOS = 30;
    public const LOCK_SEGUNDOS = self::LOCK_MINUTOS * 60;

    protected $signature = 'pncp:sync {--dias=90 : Janela de encerramento, em dias}';

    protected $description = 'Busca no PNCP as dispensas com propostas abertas, classifica e grava no banco';

    public function handle(PncpClient $pncp, ClassificadorPf $classificador): int
    {
        // O lock vive no comando, não só no agendador: vale também para a execução na subida do container e para as manuais.
        $lock = Cache::lock(self::CHAVE_LOCK, self::LOCK_SEGUNDOS);
        if (! $lock->get()) {
            $this->warn('Outra sincronização já está em andamento; nada a fazer.');

            return self::SUCCESS;
        }

        try {
            return $this->sincronizar($pncp, $classificador);
        } finally {
            $lock->release();
        }
    }

    private function sincronizar(PncpClient $pncp, ClassificadorPf $classificador): int
    {
        $agora = now();
        $total = 0;
        $paginasComErro = 0;

        try {
            foreach ($pncp->paginas((int) $this->option('dias')) as $registros) {
                $linhas = [];
                foreach ($registros as $registro) {
                    try {
                        $linha = $classificador->classificar($registro);
                    } catch (Throwable $e) {
                        // um registro malformado não pode travar todas as execuções: pula e segue
                        Log::warning('pncp:sync ignorou um registro malformado', [
                            'pncp_id' => is_string($registro['numeroControlePNCP'] ?? null) ? $registro['numeroControlePNCP'] : null,
                            'erro' => $e->getMessage(),
                        ]);

                        continue;
                    }
                    if ($linha['pncp_id'] === '') {
                        continue;
                    }
                    // string decimal: o float vira notação científica (1.0E+13) na conversão e estoura numeric(15,2)
                    $linha['valor'] = $linha['valor'] === null ? null : number_format($linha['valor'], 2, '.', '');
                    $linha['categorias'] = json_encode($linha['categorias']);
                    $linha['barreiras'] = $linha['barreiras'] === null ? null : json_encode((object) $linha['barreiras']);
                    $linha['sincronizado_em'] = $agora;
                    $linhas[$linha['pncp_id']] = $linha;   // a chave evita o mesmo id duas vezes no mesmo upsert
                }
                if ($linhas) {
                    $colunas = array_diff(array_keys(reset($linhas)), ['pncp_id']);
                    try {
                        // a transação (savepoint, se já houver uma) mantém a conexão utilizável depois de um erro do PostgreSQL
                        DB::transaction(fn () => Dispensa::upsert(array_values($linhas), ['pncp_id'], array_values($colunas)));
                        $total += count($linhas);
                    } catch (Throwable $e) {
                        $paginasComErro++;
                        Log::error('pncp:sync não gravou uma página', ['erro' => $e->getMessage(), 'registros' => count($linhas)]);
                    }
                }
            }
        } catch (Throwable $e) {
            Log::error('pncp:sync falhou', ['erro' => $e->getMessage(), 'gravadas_antes_da_falha' => $total]);
            $this->error('Sincronização interrompida: ' . $e->getMessage());

            return self::FAILURE;
        }

        if ($pncp->truncado) {
            Log::warning('pncp:sync atingiu o teto de páginas; há dispensas não sincronizadas', ['teto' => PncpClient::TETO_PAGINAS]);
        }

        if ($paginasComErro > 0) {
            $this->error("$total dispensas sincronizadas; $paginasComErro página(s) não puderam ser gravadas.");

            return self::FAILURE;   // sem atualizar CHAVE_ULTIMA: o radar mostra o aviso de dados desatualizados
        }

        Cache::forever(self::CHAVE_ULTIMA, $agora->toIso8601String());
        $this->info("$total dispensas sincronizadas.");

        return self::SUCCESS;
    }
}
