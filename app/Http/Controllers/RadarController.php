<?php

namespace App\Http\Controllers;

use App\Console\Commands\SincronizarPncp;
use App\Services\ClassificadorPf;
use App\Support\FiltroRadar;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

class RadarController extends Controller
{
    private const POR_PAGINA = 30;

    public function __invoke(Request $request): View
    {
        $filtro = FiltroRadar::deRequest($request);
        $recorte = $filtro->recorte();
        $filtrado = $filtro->aplicar();

        $resumo = [
            'exibidas' => (clone $filtrado)->count(),
            'alta' => (clone $recorte)->where('fit', 'alta')->count(),
            'verificar' => (clone $recorte)->where('fit', 'verificar')->count(),
            'abertas' => (clone $recorte)->count(),
            'estimado' => (float) (clone $filtrado)->sum('valor'),
        ];

        $dispensas = $filtrado
            ->orderByRaw('encerra_em asc nulls last')
            ->orderBy('id')
            ->paginate(self::POR_PAGINA)
            ->withQueryString();

        $ultima = Cache::get(SincronizarPncp::CHAVE_ULTIMA);
        $ultimaSync = $ultima ? Carbon::parse($ultima) : null;

        return view('radar', [
            'filtro' => $filtro,
            'dispensas' => $dispensas,
            'resumo' => $resumo,
            'categorias' => array_map(fn (array $c) => $c['label'], ClassificadorPf::CATEGORIAS),
            'ultimaSync' => $ultimaSync,
            // Só o radar sem filtro e o radar por UF entram no índice; o resto seria conteúdo duplicado.
            'indexavel' => array_diff(array_keys($request->query()), ['uf']) === []
                && (! $request->has('uf') || $filtro->uf !== null),
            'canonica' => route('radar') . ($filtro->uf ? '?uf=' . $filtro->uf : ''),
            'desatualizado' => $ultimaSync === null || $ultimaSync->lt(now()->subHour()),
        ]);
    }
}
