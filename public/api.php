<?php
/**
 * API JSON do radar.
 *   GET    api.php?r=dispensas&uf=SC&dias=30[&fresh=1]
 *   GET    api.php?r=candidaturas
 *   POST   api.php?r=candidaturas          corpo: ficha do aviso
 *   PATCH  api.php?r=candidaturas&id=1     corpo: {status?, notas?}
 *   DELETE api.php?r=candidaturas&id=1
 */

declare(strict_types=1);

require __DIR__ . '/../src/pncp.php';
require __DIR__ . '/../src/classificacao.php';
require __DIR__ . '/../src/candidaturas.php';

function responder(mixed $dados, int $codigo = 200): never
{
    http_response_code($codigo);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function corpoJson(): array
{
    $json = json_decode(file_get_contents('php://input') ?: '', true);
    if (!is_array($json)) throw new InvalidArgumentException('Corpo JSON inválido.');
    return $json;
}

$rota = $_GET['r'] ?? '';
$metodo = $_SERVER['REQUEST_METHOD'];

try {
    if ($rota === 'dispensas' && $metodo === 'GET') {
        $uf = strtoupper(preg_replace('/[^A-Za-z]/', '', $_GET['uf'] ?? 'SC'));
        $uf = $uf === 'BR' ? '' : substr($uf, 0, 2);
        $dias = max(1, min(90, (int)($_GET['dias'] ?? 30)));

        $result = fetchDispensas(pncpBase(), $uf, $dias, isset($_GET['fresh']));
        $items = array_values(array_filter(
            array_map('normalize', $result['rows']),
            fn ($i) => $i['encerraEm'] === null || strtotime($i['encerraEm']) > time()
        ));
        usort($items, fn ($a, $b) => strcmp($a['encerraEm'] ?? '9', $b['encerraEm'] ?? '9'));

        responder([
            'uf' => $uf ?: 'BR',
            'consultadoEm' => date('c', $result['fetchedAt']),
            'total' => $result['total'],
            'truncado' => $result['truncated'],
            'itens' => $items,
        ]);
    }

    if ($rota === 'candidaturas') {
        $id = (int)($_GET['id'] ?? 0);
        switch ($metodo) {
            case 'GET':
                responder(['itens' => listarCandidaturas()]);
            case 'POST':
                responder(salvarCandidatura(corpoJson()), 201);
            case 'PATCH':
                $c = atualizarCandidatura($id, corpoJson());
                $c ? responder($c) : responder(['erro' => 'Candidatura não encontrada.'], 404);
            case 'DELETE':
                removerCandidatura($id) ? responder(['ok' => true]) : responder(['erro' => 'Candidatura não encontrada.'], 404);
        }
        responder(['erro' => 'Método não permitido.'], 405);
    }

    responder(['erro' => 'Rota não encontrada.'], 404);
} catch (InvalidArgumentException $e) {
    responder(['erro' => $e->getMessage()], 422);
} catch (PDOException $e) {
    error_log('radar-pf db: ' . $e->getMessage());
    responder(['erro' => 'Banco de dados indisponível. Confira se o container "db" está de pé.'], 503);
} catch (Throwable $e) {
    responder(['erro' => $e->getMessage()], 502);
}
