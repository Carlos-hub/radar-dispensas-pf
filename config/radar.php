<?php

/*
 * Regras do classificador de editais (App\Services\ClassificadorEdital).
 * Termos sem acento e em minúsculas; o classificador normaliza o texto do mesmo jeito antes de comparar.
 * Cada termo casa como palavra ou expressão inteira; a última palavra aceita plural com "s" (sistema → sistemas).
 */
return [

    // Casou qualquer um: status "excluido", com o termo como motivo.
    'exclusao' => [
        'revenda_licenca' => [
            'licenca de uso', 'licencas', 'licenciamento', 'assinatura', 'subscricao', 'subscription',
            'renovacao de licenca', 'cessao de direito', 'aquisicao de software', 'software de prateleira',
        ],
        'fabricante' => [
            'google', 'workspace', 'gemini', 'microsoft', 'office 365', 'm365', 'windows', 'adobe', 'autodesk',
            'autocad', 'corel', 'coreldraw', 'sketchup', 'trimble', 'capcut', 'fireflies', 'canva', 'zoom',
            'kaspersky', 'eset', 'bitdefender', 'sophos', 'antivirus', 'vmware', 'oracle', 'sap', 'totvs',
        ],
        'fora_de_ti' => [
            'traducao', 'juramentada', 'interprete', 'locacao de equipamento', 'aquisicao de equipamento',
            'impressora', 'notebook', 'computador',
        ],
    ],

    // Vence a exclusão: o item não é excluído e ganha o selo "revisar: misto".
    'excecao_exclusao' => ['desenvolvimento', 'customizacao', 'integracao', 'manutencao'],

    // Perfil de serviço próprio: casou ao menos um, status "incluido"; nem inclusão nem exclusão, "neutro".
    'inclusao' => [
        'desenvolvimento', 'sistema', 'software como servico', 'saas', 'plataforma', 'manutencao de sistema',
        'manutencao de software', 'sustentacao', 'integracao', 'api', 'automacao', 'hora tecnica',
        'fabrica de software', 'aplicativo', 'portal', 'chatbot', 'disparo', 'e-mail marketing',
    ],

    // Barreiras de habilitação, procuradas na informação complementar (o PNCP não devolve o aviso nem o TR na listagem).
    'barreiras' => [
        'me_epp' => [
            'regex' => ['/exclusiv\w* .{0,40}(microempresa|me\/epp|pequeno porte)/'],
            'termos' => ['art. 48, i'],
        ],
        'atestado' => ['termos' => ['atestado de capacidade', 'capacidade tecnica', 'aptidao para desempenho']],
        'experiencia_anos' => ['regex' => ['/experiencia minima de (\d+) \(?\w*\)? ?anos?/']],
        'balanco' => ['termos' => ['balanco patrimonial', 'liquidez geral']],
        'pf_aceita' => ['termos' => ['pessoa fisica']],
        // cada grupo exige todos os seus termos
        'registro_profissional' => ['todos' => [['junta comercial', 'tradutor'], ['registro no conselho']]],
    ],

    'perfil' => [
        // incluído, sem nenhum termo de produto pronto e com valor a partir deste
        'construivel_valor_minimo' => 15000,
        // incluído e com pelo menos esta quantidade dos termos abaixo: pede produto já existente
        'produto_pronto_minimo_termos' => 3,
        'produto_pronto_termos' => ['modulo', 'migracao', 'aplicativo movel', 'educacenso', 'erp', 'multicanal', 'omnichannel'],
    ],

    // O radar esconde por padrão contratações abaixo deste valor (as de valor sigiloso continuam visíveis).
    'valor_minimo' => (float) env('RADAR_VALOR_MINIMO', 5000),
];
