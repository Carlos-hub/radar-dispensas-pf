<?php
/** Classifica cada dispensa pelo que uma pessoa física consegue executar. */

declare(strict_types=1);

const CATEGORIAS = [
    'ti' => ['label' => 'TI e software', 're' => '/\b(software|sistemas?|desenvolviment\w*|aplicativo|app\b|site|website|portal|p[aá]gina web|api\b|banco de dados|hospedagem|programa[cç][aã]o|inform[aá]tica|tecnologia da informa[cç][aã]o|\bti\b|suporte t[eé]cnico|manuten[cç][aã]o de computador|rede l[oó]gica|cabeamento|licen[cç]a|ciberseguran[cç]a|automa[cç][aã]o de processos|chatbot|intelig[eê]ncia artificial)/iu'],
    'ensino' => ['label' => 'Cursos e palestras', 're' => '/\b(treinamento|curso|capacita[cç][aã]o|palestra|oficina|workshop|instrutor|ministr\w+|forma[cç][aã]o continuada|aula|mentoria|semin[aá]rio)/iu'],
    'consultoria' => ['label' => 'Consultoria', 're' => '/\b(consultoria|assessoria|diagn[oó]stico|parecer|mapeamento de processos|plano diretor|planejamento estrat[eé]gico|estudo t[eé]cnico|levantamento)/iu'],
    'conteudo' => ['label' => 'Conteúdo e design', 're' => '/\b(design|identidade visual|fotograf\w*|v[ií]deo|filmagem|edi[cç][aã]o|reda[cç][aã]o|revis[aã]o de texto|tradu[cç][aã]o|int[eé]rprete|libras|comunica[cç][aã]o|m[ií]dias? socia\w*|arte gr[aá]fica|diagrama[cç][aã]o|ilustra[cç][aã]o|transcri[cç][aã]o)/iu'],
    'eventos' => ['label' => 'Eventos e arte', 're' => '/\b(show|apresenta[cç][aã]o art[ií]stica|m[uú]sic\w+|banda|cantor|anima[cç][aã]o|recrea[cç][aã]o|mestre de cerim[oô]nias|cerimonial|artista|teatro|dan[cç]a)/iu'],
];

const RE_BENS = '/\b(aquisi[cç][aã]o|compra de|fornecimento de (materia|equipamento|produto|ite|gênero|genero)|materia(l|is) de (consumo|expediente|limpeza)|g[eê]neros aliment\w+|medicamento|pe[cç]as)/iu';
const RE_SERVICO = '/\b(servi[cç]o|presta[cç][aã]o|contrata[cç][aã]o de (empresa|profissional|pessoa)|execu[cç][aã]o de)/iu';
const RE_EMPRESA = '/\bempresa\b/iu';
const RE_PF_EXPLICITO = '/\b(pessoa f[ií]sica|profissional aut[oô]nomo|profissional liberal)/iu';

function normalize(array $c): array
{
    $objeto = trim(preg_replace('/\s+/u', ' ', (string)($c['objetoCompra'] ?? '')));
    $objeto = preg_replace('/^\[[^\]]+\]\s*-\s*/u', '', $objeto);   // remove prefixo "[Portal de Compras Públicas] -"
    $complemento = (string)($c['informacaoComplementar'] ?? '');
    $texto = $objeto . ' ' . $complemento;
    $amparoNome = (string)($c['amparoLegal']['nome'] ?? '');

    $categorias = [];
    foreach (CATEGORIAS as $key => $cat) {
        if (preg_match($cat['re'], $texto)) $categorias[] = $key;
    }

    $ehEngenharia = str_contains($amparoNome, 'Art. 75, I') && !str_contains($amparoNome, 'Art. 75, II') && !str_contains($amparoNome, 'Art. 75, III');
    $ehBem = preg_match(RE_BENS, $objeto) && !preg_match(RE_SERVICO, $objeto) && !$categorias;
    $citaEmpresa = (bool)preg_match(RE_EMPRESA, $objeto);
    $aceitaPf = (bool)preg_match(RE_PF_EXPLICITO, $texto);

    // Aderência PF
    if ($ehEngenharia) {
        $fit = 'fora';  $motivo = 'Obra ou serviço de engenharia: exige responsável técnico com registro no CREA/CAU.';
    } elseif ($ehBem) {
        $fit = 'fora';  $motivo = 'Compra de bens: depende de emissão de nota de produto e estoque.';
    } elseif ($aceitaPf) {
        $fit = 'alta';  $motivo = 'O texto menciona pessoa física ou profissional autônomo.';
    } elseif ($categorias && !$citaEmpresa) {
        $fit = 'alta';  $motivo = 'Serviço intelectual sem exigência de empresa no objeto.';
    } elseif ($categorias) {
        $fit = 'verificar'; $motivo = 'O objeto cita "empresa". Confira no aviso se a habilitação aceita pessoa física.';
    } else {
        $fit = 'baixa'; $motivo = 'Serviço fora das categorias típicas de PF. Avalie pelo termo de referência.';
    }

    $cnpj = $c['orgaoEntidade']['cnpj'] ?? '';
    return [
        'id' => $c['numeroControlePNCP'] ?? ($cnpj . '-' . ($c['sequencialCompra'] ?? '')),
        'objeto' => $objeto,
        'complemento' => mb_strimwidth(trim($complemento), 0, 400, '…'),
        'orgao' => mb_convert_case(mb_strtolower((string)($c['orgaoEntidade']['razaoSocial'] ?? '')), MB_CASE_TITLE),
        'unidade' => (string)($c['unidadeOrgao']['nomeUnidade'] ?? ''),
        'municipio' => (string)($c['unidadeOrgao']['municipioNome'] ?? ''),
        'uf' => (string)($c['unidadeOrgao']['ufSigla'] ?? ''),
        'esfera' => ['F' => 'Federal', 'E' => 'Estadual', 'M' => 'Municipal', 'D' => 'Distrital', 'N' => 'Não informada'][$c['orgaoEntidade']['esferaId'] ?? 'N'] ?? '',
        'valor' => (float)($c['valorTotalEstimado'] ?? 0),
        'abreEm' => $c['dataAberturaProposta'] ?? null,
        'encerraEm' => $c['dataEncerramentoProposta'] ?? null,
        'amparo' => $amparoNome,
        'disputa' => (string)($c['modoDisputaNome'] ?? ''),
        'linkPncp' => $cnpj ? "https://pncp.gov.br/app/editais/$cnpj/{$c['anoCompra']}/{$c['sequencialCompra']}" : null,
        'linkOrigem' => ($c['linkSistemaOrigem'] ?? '') ?: null,
        'categorias' => $categorias,
        'fit' => $fit,
        'motivo' => $motivo,
    ];
}
