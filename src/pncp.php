<?php
/** Cliente da API de consulta do PNCP, com cache em arquivo. */

declare(strict_types=1);

const PNCP_DEFAULT_BASE = 'https://pncp.gov.br/api/consulta/v1';
const PAGE_SIZE = 50;
const MAX_PAGES = 30;          // teto de segurança: 1.500 registros por consulta
const CACHE_TTL = 900;         // 15 minutos

function pncpBase(): string
{
    return rtrim(getenv('PNCP_BASE') ?: PNCP_DEFAULT_BASE, '/');
}

function fetchDispensas(string $base, string $uf, int $dias, bool $fresh): array
{
    $cacheFile = sys_get_temp_dir() . '/radar-pf-' . md5("$base|$uf|$dias") . '.json';
    if (!$fresh && is_file($cacheFile) && time() - filemtime($cacheFile) < CACHE_TTL) {
        return json_decode(file_get_contents($cacheFile), true);
    }

    $query = fn (int $p) => $base . '/contratacoes/proposta?' . http_build_query(array_filter([
        'dataFinal' => date('Ymd', strtotime("+$dias days")),
        'codigoModalidadeContratacao' => 8,
        'uf' => $uf ?: null,
        'pagina' => $p,
        'tamanhoPagina' => PAGE_SIZE,
    ]));

    $first = httpGetJson($query(1));
    $rows = $first['data'] ?? [];
    $totalPages = (int)($first['totalPaginas'] ?? 1);
    $pages = min($totalPages, MAX_PAGES);

    if ($pages > 1) {
        foreach (httpGetJsonMany(array_map($query, range(2, $pages))) as $page) {
            array_push($rows, ...($page['data'] ?? []));
        }
    }

    $result = [
        'rows' => $rows,
        'total' => (int)($first['totalRegistros'] ?? count($rows)),
        'truncated' => $totalPages > MAX_PAGES,
        'fetchedAt' => time(),
    ];
    @file_put_contents($cacheFile, json_encode($result, JSON_UNESCAPED_UNICODE));
    return $result;
}

function httpGetJson(string $url): array
{
    return httpGetJsonMany([$url])[0];
}

/** Busca várias URLs em paralelo (curl_multi), com fallback sequencial. */
function httpGetJsonMany(array $urls): array
{
    if (!function_exists('curl_multi_init')) {
        return array_map(function ($u) {
            $ctx = stream_context_create(['http' => ['timeout' => 30, 'header' => "Accept: application/json\r\nUser-Agent: radar-pf/1.0\r\n"]]);
            $body = @file_get_contents($u, false, $ctx);
            if ($body === false) throw new RuntimeException('Falha ao consultar o PNCP. Tente de novo em alguns segundos.');
            return decode($body);
        }, $urls);
    }

    $out = [];
    foreach (array_chunk($urls, 6, true) as $chunk) {   // 6 conexões simultâneas para não estressar a API
        $mh = curl_multi_init();
        $handles = [];
        foreach ($chunk as $i => $u) {
            $ch = curl_init($u);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_HTTPHEADER => ['Accept: application/json'],
                CURLOPT_USERAGENT => 'radar-pf/1.0',
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[$i] = $ch;
        }
        do {
            $status = curl_multi_exec($mh, $running);
            if ($running) curl_multi_select($mh, 1.0);
        } while ($running && $status === CURLM_OK);

        foreach ($handles as $i => $ch) {
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $body = curl_multi_getcontent($ch);
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
            if ($code === 204 || $body === '') { $out[$i] = ['data' => []]; continue; }
            if ($code !== 200) throw new RuntimeException("PNCP respondeu HTTP $code. A API costuma oscilar; tente atualizar em instantes.");
            $out[$i] = decode($body);
        }
        curl_multi_close($mh);
    }
    ksort($out);
    return array_values($out);
}

function decode(string $body): array
{
    $json = json_decode($body, true);
    if (!is_array($json)) throw new RuntimeException('Resposta inválida do PNCP.');
    return $json;
}
