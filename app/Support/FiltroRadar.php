<?php

namespace App\Support;

use App\Models\Dispensa;
use App\Services\ClassificadorEdital;
use App\Services\ClassificadorPf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/** Filtros do radar lidos da query string. Valor inválido cai no padrão em vez de gerar erro, porque a URL é pública. */
final class FiltroRadar
{
    public const UFS = ['AC', 'AL', 'AM', 'AP', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MG', 'MS', 'MT', 'PA', 'PB', 'PE', 'PI', 'PR', 'RJ', 'RN', 'RO', 'RR', 'RS', 'SC', 'SE', 'SP', 'TO'];
    public const DIAS = [7, 15, 30, 60];
    public const PARTICIPACOES = ['pf' => 'Aceita PF', 'me_epp' => 'Só empresa (ME/EPP)', 'nao_verificado' => 'Barreiras não verificadas'];
    public const PERFIS = [ClassificadorEdital::CONSTRUIVEL => 'Construível', ClassificadorEdital::PRODUTO_PRONTO => 'Pede produto pronto'];
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
        public readonly bool $descartados = false,
        public readonly bool $pequenas = false,
        public readonly ?string $participacao = null,
        public readonly ?string $perfil = null,
    ) {}

    /** Abaixo deste valor a contratação fica oculta, salvo com "pequenas=1". */
    public static function valorMinimo(): float
    {
        return (float) config('radar.valor_minimo');
    }

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
            descartados: $texto('descartados') === '1',
            pequenas: $texto('pequenas') === '1',
            participacao: array_key_exists($texto('participacao'), self::PARTICIPACOES) ? $texto('participacao') : null,
            perfil: array_key_exists($texto('perfil'), self::PERFIS) ? $texto('perfil') : null,
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
            // descartados pelo classificador de editais não somem do banco: voltam com "descartados=1", com o motivo
            ->when(! $this->descartados, fn (Builder $q) => $q->where('edital_status', '!=', ClassificadorEdital::EXCLUIDO))
            ->when(! $this->pequenas, fn (Builder $q) => $q->where(
                fn (Builder $w) => $w->whereNull('valor')->orWhere('valor', '>=', self::valorMinimo())
            ))
            ->when($this->participacao, fn (Builder $q) => match ($this->participacao) {
                'pf' => $q->whereJsonContainsKey('barreiras->pf_aceita'),
                'me_epp' => $q->whereJsonContainsKey('barreiras->me_epp'),
                'nao_verificado' => $q->whereNull('barreiras'),
            })
            ->when($this->perfil, fn (Builder $q) => $q->where('perfil', $this->perfil))
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
