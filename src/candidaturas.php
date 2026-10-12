<?php
/** Persistência das dispensas salvas (PostgreSQL). */

declare(strict_types=1);

const STATUS = [
    'salva' => 'Salva',
    'enviada' => 'Proposta enviada',
    'ganhei' => 'Ganhei',
    'perdi' => 'Perdi',
    'descartada' => 'Descartada',
];
const NOTAS_MAX = 5000;

function db(): PDO
{
    static $pdo = null;
    return $pdo ??= new PDO(
        sprintf('pgsql:host=%s;port=%s;dbname=%s', getenv('DB_HOST') ?: 'db', getenv('DB_PORT') ?: '5432', getenv('DB_NAME') ?: 'radar'),
        getenv('DB_USER') ?: 'radar',
        getenv('DB_PASSWORD') ?: 'radar',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
}

function candidaturaRow(array $r): array
{
    return [
        'id' => (int)$r['id'],
        'status' => $r['status'],
        'notas' => $r['notas'],
        'criadoEm' => date('c', strtotime($r['criado_em'])),
        'atualizadoEm' => date('c', strtotime($r['atualizado_em'])),
        'aviso' => json_decode($r['aviso'], true),
    ];
}

function listarCandidaturas(): array
{
    $rows = db()->query('SELECT * FROM candidaturas ORDER BY encerra_em ASC NULLS LAST, id DESC')->fetchAll();
    return array_map('candidaturaRow', $rows);
}

/** Salva a ficha do radar. Se já existir, atualiza só os dados do aviso e preserva status e notas. */
function salvarCandidatura(array $aviso): array
{
    $pncpId = trim((string)($aviso['id'] ?? ''));
    $objeto = trim((string)($aviso['objeto'] ?? ''));
    if ($pncpId === '' || $objeto === '') {
        throw new InvalidArgumentException('Aviso inválido: faltam id e objeto.');
    }
    $encerra = strtotime((string)($aviso['encerraEm'] ?? ''));
    $valor = (float)($aviso['valor'] ?? 0);

    $st = db()->prepare(
        'INSERT INTO candidaturas (pncp_id, objeto, orgao, municipio, uf, valor, encerra_em, aviso)
         VALUES (:pncp_id, :objeto, :orgao, :municipio, :uf, :valor, :encerra_em, :aviso)
         ON CONFLICT (pncp_id) DO UPDATE SET
            objeto = EXCLUDED.objeto, orgao = EXCLUDED.orgao, municipio = EXCLUDED.municipio, uf = EXCLUDED.uf,
            valor = EXCLUDED.valor, encerra_em = EXCLUDED.encerra_em, aviso = EXCLUDED.aviso, atualizado_em = now()
         RETURNING *'
    );
    $st->execute([
        'pncp_id' => $pncpId,
        'objeto' => $objeto,
        'orgao' => (string)($aviso['orgao'] ?? ''),
        'municipio' => (string)($aviso['municipio'] ?? ''),
        'uf' => mb_substr((string)($aviso['uf'] ?? ''), 0, 2),
        'valor' => $valor > 0 ? $valor : null,
        'encerra_em' => $encerra ? date('Y-m-d H:i:s', $encerra) : null,
        'aviso' => json_encode($aviso, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
    return candidaturaRow($st->fetch());
}

/** Altera status e/ou notas. Devolve null se a candidatura não existir. */
function atualizarCandidatura(int $id, array $campos): ?array
{
    $set = [];
    $params = ['id' => $id];
    if (array_key_exists('status', $campos)) {
        if (!is_string($campos['status']) || !isset(STATUS[$campos['status']])) {
            throw new InvalidArgumentException('Status inválido.');
        }
        $set[] = 'status = :status';
        $params['status'] = $campos['status'];
    }
    if (array_key_exists('notas', $campos)) {
        $set[] = 'notas = :notas';
        $params['notas'] = mb_substr(trim((string)$campos['notas']), 0, NOTAS_MAX);
    }
    if (!$set) throw new InvalidArgumentException('Informe status ou notas.');

    $st = db()->prepare('UPDATE candidaturas SET ' . implode(', ', $set) . ', atualizado_em = now() WHERE id = :id RETURNING *');
    $st->execute($params);
    $row = $st->fetch();
    return $row ? candidaturaRow($row) : null;
}

function removerCandidatura(int $id): bool
{
    $st = db()->prepare('DELETE FROM candidaturas WHERE id = :id');
    $st->execute(['id' => $id]);
    return $st->rowCount() > 0;
}
