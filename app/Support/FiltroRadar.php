<?php

namespace App\Support;

use App\Models\Dispensa;
use App\Services\ClassificadorPf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/** Filtros do radar lidos da query string. Valor inválido cai no padrão em vez de gerar erro, porque a URL é pública. */
final class FiltroRadar
{
    public const UFS = ['AC', 'AL', 'AM', 'AP', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MG', 'MS', 'MT', 'PA', 'PB', 'PE', 'PI', 'PR', 'RJ', 'RN', 'RO', 'RR', 'RS', 'SC', 'SE', 'SP', 'TO'];
    public const DIAS = [7, 15, 30, 60];
    private const DIAS_PADRAO = 30;

    /**
     * @param  list<string>  $cats
     * @param  list<string>  $fits
     */
    public function __construct(
        public readonly ?string $uf,
        public readonly int $dias,
        public readonly ?float $vmax,
        public readonly string $q,
        public readonly array $cats,
        public readonly array $fits,
    ) {}

    public static function deRequest(Request $request): self
    {
        $texto = function (string $chave) use ($request): string {
            $valor = $request->query($chave);

            return is_string($valor) ? trim($valor) : '';
        };

        $uf = strtoupper($texto('uf'));
        $dias = (int) $texto('dias');
        $vmax = $texto('vmax');
        $cat = $request->query('cat');

        $fits = ['alta'];
        if ($texto('verificar') !== '0') {
            $fits[] = 'verificar';
        }
        if ($texto('baixa') === '1') {
            $fits[] = 'baixa';
        }
        if ($texto('fora') === '1') {
            $fits[] = 'fora';
        }

        return new self(
            uf: in_array($uf, self::UFS, true) ? $uf : null,
            dias: in_array($dias, self::DIAS, true) ? $dias : self::DIAS_PADRAO,
            vmax: is_numeric($vmax) && (float) $vmax > 0 ? (float) $vmax : null,
            q: mb_substr($texto('q'), 0, 100),
            cats: array_values(array_intersect(
                array_keys(ClassificadorPf::CATEGORIAS),
                is_array($cat) ? array_filter($cat, 'is_string') : [],
            )),
            fits: $fits,
        );
    }

    /** Dispensas abertas na UF e no prazo escolhidos, antes dos demais filtros. */
    public function recorte(): Builder
    {
        return Dispensa::query()
            ->abertas()
            ->when($this->uf, fn (Builder $q) => $q->where('uf', $this->uf))
            ->where(fn (Builder $q) => $q->whereNull('encerra_em')->orWhere('encerra_em', '<=', now()->addDays($this->dias)));
    }

    public function aplicar(): Builder
    {
        return $this->recorte()
            ->whereIn('fit', $this->fits)
            ->when($this->vmax, fn (Builder $q) => $q->where(
                fn (Builder $w) => $w->whereNull('valor')->orWhere('valor', '<=', $this->vmax)
            ))
            ->when($this->cats, fn (Builder $q) => $q->where(function (Builder $w) {
                foreach ($this->cats as $cat) {
                    $w->orWhereJsonContains('categorias', $cat);
                }
            }))
            ->when($this->q !== '', fn (Builder $q) => $q->whereRaw(
                "unaccent(objeto || ' ' || orgao || ' ' || municipio || ' ' || complemento) ILIKE unaccent(?)",
                ['%' . addcslashes($this->q, '%_\\') . '%'],
            ));
    }
}
