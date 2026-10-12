<?php

namespace App\Services;

use App\Enums\Fit;

/** Classifica cada dispensa pelo que uma pessoa física consegue executar. */
class ClassificadorPf
{
    public const CATEGORIAS = [
        'ti' => ['label' => 'TI e software', 're' => '/\b(software|sistemas?|desenvolviment\w*|aplicativo|app\b|site|website|portal|p[aá]gina web|api\b|banco de dados|hospedagem|programa[cç][aã]o|inform[aá]tica|tecnologia da informa[cç][aã]o|\bti\b|suporte t[eé]cnico|manuten[cç][aã]o de computador|rede l[oó]gica|cabeamento|licen[cç]a|ciberseguran[cç]a|automa[cç][aã]o de processos|chatbot|intelig[eê]ncia artificial)/iu'],
        'ensino' => ['label' => 'Cursos e palestras', 're' => '/\b(treinamento|curso|capacita[cç][aã]o|palestra|oficina|workshop|instrutor|ministr\w+|forma[cç][aã]o continuada|aula|mentoria|semin[aá]rio)/iu'],
        'consultoria' => ['label' => 'Consultoria', 're' => '/\b(consultoria|assessoria|diagn[oó]stico|parecer|mapeamento de processos|plano diretor|planejamento estrat[eé]gico|estudo t[eé]cnico|levantamento)/iu'],
        'conteudo' => ['label' => 'Conteúdo e design', 're' => '/\b(design|identidade visual|fotograf\w*|v[ií]deo|filmagem|edi[cç][aã]o|reda[cç][aã]o|revis[aã]o de texto|tradu[cç][aã]o|int[eé]rprete|libras|comunica[cç][aã]o|m[ií]dias? socia\w*|arte gr[aá]fica|diagrama[cç][aã]o|ilustra[cç][aã]o|transcri[cç][aã]o)/iu'],
        'eventos' => ['label' => 'Eventos e arte', 're' => '/\b(show|apresenta[cç][aã]o art[ií]stica|m[uú]sic\w+|banda|cantor|anima[cç][aã]o|recrea[cç][aã]o|mestre de cerim[oô]nias|cerimonial|artista|teatro|dan[cç]a)/iu'],
    ];

    private const RE_BENS = '/\b(aquisi[cç][aã]o|compra de|fornecimento de (materia|equipamento|produto|ite|gênero|genero)|materia(l|is) de (consumo|expediente|limpeza)|g[eê]neros aliment\w+|medicamento|pe[cç]as)/iu';
    private const RE_SERVICO = '/\b(servi[cç]o|presta[cç][aã]o|contrata[cç][aã]o de (empresa|profissional|pessoa)|execu[cç][aã]o de)/iu';
    private const RE_EMPRESA = '/\bempresa\b/iu';
    private const RE_PF_EXPLICITO = '/\b(pessoa f[ií]sica|profissional aut[oô]nomo|profissional liberal)/iu';

    private const ESFERAS = ['F' => 'Federal', 'E' => 'Estadual', 'M' => 'Municipal', 'D' => 'Distrital', 'N' => 'Não informada'];

    /** Recebe um registro bruto do PNCP e devolve uma linha da tabela dispensas. */
    public function classificar(array $c): array
    {
        $objeto = trim((string) preg_replace('/\s+/u', ' ', (string) ($c['objetoCompra'] ?? '')));
        $objeto = (string) preg_replace('/^\[[^\]]+\]\s*-\s*/u', '', $objeto);   // remove prefixo "[Portal de Compras Públicas] -"
        $complemento = (string) ($c['informacaoComplementar'] ?? '');
        $texto = $objeto . ' ' . $complemento;
        $amparo = (string) ($c['amparoLegal']['nome'] ?? '');

        $categorias = [];
        foreach (self::CATEGORIAS as $chave => $cat) {
            if (preg_match($cat['re'], $texto)) {
                $categorias[] = $chave;
            }
        }

        $ehEngenharia = str_contains($amparo, 'Art. 75, I') && ! str_contains($amparo, 'Art. 75, II') && ! str_contains($amparo, 'Art. 75, III');
        $ehBem = preg_match(self::RE_BENS, $objeto) && ! preg_match(self::RE_SERVICO, $objeto) && ! $categorias;
        $citaEmpresa = (bool) preg_match(self::RE_EMPRESA, $objeto);
        $aceitaPf = (bool) preg_match(self::RE_PF_EXPLICITO, $texto);

        [$fit, $motivo] = match (true) {
            $ehEngenharia => [Fit::Fora, 'Obra ou serviço de engenharia: exige responsável técnico com registro no CREA/CAU.'],
            $ehBem => [Fit::Fora, 'Compra de bens: depende de emissão de nota de produto e estoque.'],
            $aceitaPf => [Fit::Alta, 'O texto menciona pessoa física ou profissional autônomo.'],
            $categorias && ! $citaEmpresa => [Fit::Alta, 'Serviço intelectual sem exigência de empresa no objeto.'],
            (bool) $categorias => [Fit::Verificar, 'O objeto cita "empresa". Confira no aviso se a habilitação aceita pessoa física.'],
            default => [Fit::Baixa, 'Serviço fora das categorias típicas de PF. Avalie pelo termo de referência.'],
        };

        $cnpj = (string) ($c['orgaoEntidade']['cnpj'] ?? '');
        $sequencial = (string) ($c['sequencialCompra'] ?? '');
        $valor = self::valor($c['valorTotalEstimado'] ?? null);
        $esferaId = $c['orgaoEntidade']['esferaId'] ?? 'N';

        return [
            'pncp_id' => (string) ($c['numeroControlePNCP'] ?? ($cnpj !== '' ? "$cnpj-$sequencial" : '')),
            'objeto' => $objeto,
            'complemento' => mb_strimwidth(trim($complemento), 0, 400, '…'),
            'orgao' => mb_convert_case(mb_strtolower((string) ($c['orgaoEntidade']['razaoSocial'] ?? '')), MB_CASE_TITLE),
            'unidade' => (string) ($c['unidadeOrgao']['nomeUnidade'] ?? ''),
            'municipio' => (string) ($c['unidadeOrgao']['municipioNome'] ?? ''),
            'uf' => mb_substr((string) ($c['unidadeOrgao']['ufSigla'] ?? ''), 0, 2),
            'esfera' => self::ESFERAS[is_string($esferaId) ? $esferaId : 'N'] ?? 'Não informada',
            'valor' => $valor,
            'abre_em' => self::data($c['dataAberturaProposta'] ?? null),
            'encerra_em' => self::data($c['dataEncerramentoProposta'] ?? null),
            'amparo' => $amparo,
            'disputa' => (string) ($c['modoDisputaNome'] ?? ''),
            'link_pncp' => $cnpj !== '' ? "https://pncp.gov.br/app/editais/$cnpj/" . ($c['anoCompra'] ?? '') . "/$sequencial" : null,
            'link_origem' => self::linkOrigem($c['linkSistemaOrigem'] ?? null),
            'categorias' => $categorias,
            'fit' => $fit->value,
            'motivo' => $motivo,
        ];
    }

    /** Maior valor que cabe em numeric(15,2). */
    private const VALOR_MAXIMO = 9_999_999_999_999.99;

    /** Valor estimado válido (positivo, finito e que cabe na coluna) ou null, que a tela mostra como "valor sigiloso". */
    private static function valor(mixed $bruto): ?float
    {
        $valor = is_numeric($bruto) ? (float) $bruto : 0.0;

        return is_finite($valor) && $valor > 0 && $valor <= self::VALOR_MAXIMO ? $valor : null;
    }

    /** Data ISO 8601 (com ou sem hora e fuso) que existe no calendário; qualquer outra coisa vira null em vez de ir crua ao banco. */
    private static function data(mixed $bruto): ?string
    {
        if (! is_string($bruto)) {
            return null;
        }
        $texto = trim($bruto);
        $ok = preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2}):(\d{2})(?::(\d{2})(?:\.\d+)?)?(?:Z|[+-]\d{2}(?::?\d{2})?)?)?$/', $texto, $m);

        return $ok && checkdate((int) $m[2], (int) $m[3], (int) $m[1]) && (int) ($m[4] ?? 0) <= 23 && (int) ($m[5] ?? 0) <= 59 && (int) ($m[6] ?? 0) <= 59
            ? $texto
            : null;
    }

    /** Link de origem como texto; endereço sem esquema (contratos.example.gov.br/x) ganha https://. O resto segue como veio. */
    private static function linkOrigem(mixed $bruto): ?string
    {
        if (! is_string($bruto) || trim($bruto) === '') {
            return null;
        }
        $link = trim($bruto);
        if (! preg_match('#^[a-z][a-z0-9+.-]*://#i', $link) && preg_match('#^[a-z0-9.-]+\.[a-z]{2,}(/|$)#i', $link)) {
            return 'https://' . $link;
        }

        return $link;
    }
}
