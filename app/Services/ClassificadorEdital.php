<?php

namespace App\Services;

use Normalizer;

/**
 * Decide se um edital é serviço próprio (incluir), revenda ou fora do escopo (excluir) ou nenhum dos dois (neutro),
 * aponta as barreiras de habilitação e o perfil. Todos os termos e limites vêm de config/radar.php.
 */
class ClassificadorEdital
{
    public const INCLUIDO = 'incluido';
    public const EXCLUIDO = 'excluido';
    public const NEUTRO = 'neutro';

    public const CONSTRUIVEL = 'construivel';
    public const PRODUTO_PRONTO = 'produto_pronto';

    /** Sem a extensão intl (caso do container e da CI), os diacríticos saem por esta tabela. */
    private const SEM_ACENTO = [
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c', 'ñ' => 'n',
    ];

    private array $config;

    public function __construct(?array $config = null)
    {
        $this->config = $config ?? (app()->bound('config') ? config('radar') : require dirname(__DIR__, 2) . '/config/radar.php');
    }

    /**
     * @return array{edital_status: string, edital_motivo: string, revisar_misto: bool, barreiras: ?array<string, true|int>, perfil: ?string}
     *         barreiras null quando não há texto além do objeto (não verificado)
     */
    public function classificar(string $objeto, string $complemento = '', ?float $valor = null): array
    {
        $obj = self::normalizar($objeto);

        $excluidos = $this->casados($obj, array_merge(...array_values($this->config['exclusao'])));
        $misto = $excluidos && $this->casados($obj, $this->config['excecao_exclusao']);
        $incluidos = $this->casados($obj, $this->config['inclusao']);

        [$status, $motivo] = match (true) {
            $excluidos && ! $misto => [self::EXCLUIDO, implode(' / ', $excluidos)],
            (bool) $incluidos => [self::INCLUIDO, implode(' / ', $incluidos)],
            default => [self::NEUTRO, ''],
        };

        return [
            'edital_status' => $status,
            'edital_motivo' => $motivo,
            'revisar_misto' => $misto,
            'barreiras' => trim($complemento) === '' ? null : $this->barreiras(self::normalizar("$objeto $complemento")),
            'perfil' => $status === self::INCLUIDO ? $this->perfil($obj, $valor) : null,
        ];
    }

    /** Minúsculas, sem acentos e com espaços colapsados. */
    public static function normalizar(string $texto): string
    {
        $texto = mb_strtolower($texto, 'UTF-8');
        if (class_exists(Normalizer::class)) {
            $texto = (string) preg_replace('/\p{Mn}+/u', '', (string) Normalizer::normalize($texto, Normalizer::FORM_D));
        } else {
            $texto = strtr($texto, self::SEM_ACENTO);
        }

        return trim((string) preg_replace('/\s+/u', ' ', $texto));
    }

    /** @return array<string, true|int> */
    private function barreiras(string $texto): array
    {
        $achadas = [];
        foreach ($this->config['barreiras'] as $chave => $regra) {
            foreach ($regra['regex'] ?? [] as $re) {
                if (preg_match($re, $texto, $m)) {
                    $achadas[$chave] = isset($m[1]) && ctype_digit($m[1]) ? (int) $m[1] : true;
                    continue 2;
                }
            }
            if ($this->casados($texto, $regra['termos'] ?? [])) {
                $achadas[$chave] = true;
                continue;
            }
            foreach ($regra['todos'] ?? [] as $grupo) {
                if (count($this->casados($texto, $grupo)) === count($grupo)) {
                    $achadas[$chave] = true;
                    break;
                }
            }
        }
        // "Aceita PF" só vale se a contratação não for exclusiva de ME/EPP
        if (isset($achadas['me_epp'])) {
            unset($achadas['pf_aceita']);
        }

        return $achadas;
    }

    private function perfil(string $obj, ?float $valor): ?string
    {
        $regras = $this->config['perfil'];
        $termos = count($this->casados($obj, $regras['produto_pronto_termos']));

        return match (true) {
            $termos >= $regras['produto_pronto_minimo_termos'] => self::PRODUTO_PRONTO,
            $termos === 0 && $valor !== null && $valor >= $regras['construivel_valor_minimo'] => self::CONSTRUIVEL,
            default => null,
        };
    }

    /**
     * Termos que aparecem como palavra ou expressão inteira, na ordem da lista.
     *
     * @param  list<string>  $termos
     * @return list<string>
     */
    private function casados(string $texto, array $termos): array
    {
        return array_values(array_filter($termos, fn (string $t) => (bool) preg_match(
            '/(?<![a-z0-9])' . preg_quote(self::normalizar($t), '/') . 's?(?![a-z0-9])/u',
            $texto,
        )));
    }
}
