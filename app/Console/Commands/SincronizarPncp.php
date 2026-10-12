<?php

namespace App\Console\Commands;

use App\Models\Dispensa;
use App\Services\ClassificadorPf;
use App\Services\Pncp\PncpClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class SincronizarPncp extends Command
{
    public const CHAVE_ULTIMA = 'pncp.ultima_sincronizacao';

    protected $signature = 'pncp:sync {--dias=90 : Janela de encerramento, em dias}';

    protected $description = 'Busca no PNCP as dispensas com propostas abertas, classifica e grava no banco';

    public function handle(PncpClient $pncp, ClassificadorPf $classificador): int
    {
        $agora = now();
        $total = 0;

        try {
            foreach ($pncp->paginas((int) $this->option('dias')) as $registros) {
                $linhas = [];
                foreach ($registros as $registro) {
                    $linha = $classificador->classificar($registro);
                    if ($linha['pncp_id'] === '') {
                        continue;
                    }
                    $linha['categorias'] = json_encode($linha['categorias']);
                    $linha['sincronizado_em'] = $agora;
                    $linhas[$linha['pncp_id']] = $linha;   // a chave evita o mesmo id duas vezes no mesmo upsert
                }
                if ($linhas) {
                    $colunas = array_diff(array_keys(reset($linhas)), ['pncp_id']);
                    Dispensa::upsert(array_values($linhas), ['pncp_id'], array_values($colunas));
                    $total += count($linhas);
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

        Cache::forever(self::CHAVE_ULTIMA, $agora->toIso8601String());
        $this->info("$total dispensas sincronizadas.");

        return self::SUCCESS;
    }
}
