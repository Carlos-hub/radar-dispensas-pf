<?php
/**
 * Radar de Dispensas PF
 * Consulta dispensas com propostas abertas no PNCP, classifica o que uma pessoa física consegue executar
 * e guarda as escolhidas para acompanhar a candidatura.
 *
 * Esta página só entrega o HTML. Dados: api.php. Interface: assets/app.js e assets/app.css.
 * Rodar: docker compose up -d   →   http://localhost:8080
 */

declare(strict_types=1);

require __DIR__ . '/../src/classificacao.php';
require __DIR__ . '/../src/candidaturas.php';

$boot = json_encode([
    'categorias' => array_map(fn ($c) => $c['label'], CATEGORIAS),
    'status' => STATUS,
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
$asset = fn (string $f) => "assets/$f?v=" . filemtime(__DIR__ . "/assets/$f");
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Radar de Dispensas PF</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,600&family=Public+Sans:wght@400;500;600&family=JetBrains+Mono:wght@500&display=swap">
<link rel="stylesheet" href="<?= $asset('app.css') ?>">
</head>
<body>
<div class="wrap">
  <header class="top">
    <div class="brand">
      <svg class="logo" viewBox="0 0 40 40" aria-hidden="true">
        <circle cx="20" cy="20" r="17" fill="none" stroke="currentColor" stroke-width="2" opacity=".35"/>
        <circle cx="20" cy="20" r="10" fill="none" stroke="currentColor" stroke-width="2" opacity=".6"/>
        <path d="M20 20 L32 8" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"/>
        <circle cx="20" cy="20" r="3" fill="currentColor"/>
      </svg>
      <div>
        <h1>Radar de Dispensas PF</h1>
        <p>Dispensas de licitação com propostas abertas no PNCP, filtradas pelo que uma pessoa física consegue executar sem CNPJ.</p>
      </div>
    </div>
    <nav class="tabs" role="tablist" aria-label="Seções">
      <button type="button" role="tab" data-view="radar" aria-selected="true">Radar</button>
      <button type="button" role="tab" data-view="salvas" aria-selected="false">Minhas candidaturas <span class="count" id="countSalvas">0</span></button>
    </nav>
  </header>

  <section id="viewRadar">
    <div class="bar">
      <div class="row">
        <label class="f">UF
          <select id="uf"></select>
        </label>
        <label class="f">Encerra em até
          <select id="dias"><option value="7">7 dias</option><option value="15">15 dias</option><option value="30" selected>30 dias</option><option value="60">60 dias</option></select>
        </label>
        <label class="f">Valor máximo (R$)
          <input id="vmax" type="number" min="0" step="1000" placeholder="sem limite">
        </label>
        <label class="f grow">Buscar no objeto
          <input id="q" type="search" placeholder="ex.: Laravel, palestra, Chapecó">
        </label>
        <button id="reload" class="btn primary" type="button">Atualizar</button>
      </div>
      <div class="row" id="cats"></div>
      <div class="row">
        <label class="toggle"><input id="showVerificar" type="checkbox" checked> Incluir "verificar"</label>
        <label class="toggle"><input id="showBaixa" type="checkbox"> Incluir baixa aderência</label>
        <label class="toggle"><input id="showFora" type="checkbox"> Incluir bens e engenharia</label>
      </div>
    </div>
    <div class="summary" id="summary"></div>
    <div class="list" id="list"></div>
  </section>

  <section id="viewSalvas" hidden>
    <div class="bar">
      <div class="row" id="statusFilter"></div>
    </div>
    <div class="summary" id="summarySalvas"></div>
    <div class="list" id="listSalvas"></div>
  </section>
</div>

<div class="toast" id="toast" role="status" aria-live="polite"></div>

<script id="boot" type="application/json"><?= $boot ?></script>
<script src="<?= $asset('app.js') ?>" defer></script>
</body>
</html>
