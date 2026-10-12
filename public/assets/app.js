/* Radar de Dispensas PF: interface. Os dados vêm de api.php. */

const { categorias: CATS, status: STATUS } = JSON.parse(document.getElementById('boot').textContent);
const UFS = ['BR','AC','AL','AM','AP','BA','CE','DF','ES','GO','MA','MG','MS','MT','PA','PB','PE','PI','PR','RJ','RN','RO','RR','RS','SC','SE','SP','TO'];
const FIT_LABEL = { alta: 'Fit PF', verificar: 'Verificar', baixa: 'Baixa', fora: 'Fora do perfil' };

const $ = id => document.getElementById(id);
const brl = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' });
const store = { get(k, d) { try { return JSON.parse(localStorage.getItem('radarpf:' + k)) ?? d; } catch { return d; } },
                set(k, v) { try { localStorage.setItem('radarpf:' + k, JSON.stringify(v)); } catch {} } };

/* ------------------------------------------------------------------ API */

async function request(params, method = 'GET', body) {
  const r = await fetch('api.php?' + new URLSearchParams(params), {
    method,
    headers: body ? { 'Content-Type': 'application/json' } : undefined,
    body: body ? JSON.stringify(body) : undefined,
  });
  const j = await r.json().catch(() => null);
  if (!r.ok) throw new Error(j?.erro || `Erro ${r.status} ao falar com o servidor.`);
  return j;
}

const api = {
  dispensas: (uf, dias, fresh) => request({ r: 'dispensas', uf, dias, ...(fresh ? { fresh: 1 } : {}) }),
  candidaturas: () => request({ r: 'candidaturas' }),
  salvar: aviso => request({ r: 'candidaturas' }, 'POST', aviso),
  atualizar: (id, campos) => request({ r: 'candidaturas', id }, 'PATCH', campos),
  remover: id => request({ r: 'candidaturas', id }, 'DELETE'),
};

/* --------------------------------------------------------------- estado */

let radar = { itens: [] };
let salvas = [];                       // candidaturas: { id, status, notas, aviso }
let view = store.get('view', 'radar');
let statusAtivo = '';                  // filtro da aba de candidaturas ('' = todas)
const activeCats = new Set(store.get('cats', []));

const salvaPorPncpId = id => salvas.find(c => c.aviso.id === id);

/* ---------------------------------------------------------- utilitários */

function norm(s) { return s.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase(); }
function esc(s) { return String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])); }
function link(url, texto) { return /^https?:\/\//i.test(url || '') ? `<a href="${esc(url)}" target="_blank" rel="noopener">${texto}</a>` : ''; }

function prazo(iso) {
  if (!iso) return { txt: 'sem prazo', cls: '' };
  const h = (new Date(iso) - Date.now()) / 36e5;
  const d = new Date(iso).toLocaleString('pt-BR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });
  if (h <= 0) return { txt: `encerrou ${d}`, cls: 'encerrada' };
  if (h < 48) return { txt: `encerra ${d} (${Math.round(h)}h)`, cls: 'urgente' };
  return { txt: `encerra ${d} (${Math.round(h / 24)} dias)`, cls: '' };
}

let toastTimer;
function toast(msg, erro = false) {
  const t = $('toast');
  t.textContent = msg;
  t.className = 'toast show' + (erro ? ' err' : '');
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => { t.className = 'toast'; }, erro ? 5000 : 2200);
}

/* --------------------------------------------------------------- fichas */

/** Ficha de um aviso. Com `cand`, mostra o acompanhamento (status, notas, remover). */
function card(i, cand) {
  const p = prazo(i.encerraEm);
  const pill = cand
    ? `<span class="pill st-${esc(cand.status)}">${esc(STATUS[cand.status])}</span>`
    : `<span class="pill fit-${esc(i.fit)}">${FIT_LABEL[i.fit] ?? ''}</span>`;
  const acao = cand
    ? `<button type="button" class="btn danger" data-remove="${cand.id}">Remover</button>`
    : salvaPorPncpId(i.id)
      ? `<button type="button" class="btn saved" data-goto-salvas>✓ Salva</button>`
      : `<button type="button" class="btn primary" data-save="${esc(i.id)}">Salvar</button>`;
  const track = cand ? `
    <div class="track">
      <label class="f">Status
        <select data-status="${cand.id}">${Object.entries(STATUS).map(([k, l]) =>
          `<option value="${k}"${k === cand.status ? ' selected' : ''}>${esc(l)}</option>`).join('')}</select>
      </label>
      <label class="f grow">Notas
        <textarea data-notas="${cand.id}" rows="2" placeholder="Documentos pendentes, valor da proposta, contato do órgão…">${esc(cand.notas)}</textarea>
      </label>
    </div>` : '';

  return `<article class="card ${cand ? `st-${esc(cand.status)}` : `fit-${esc(i.fit)}`}">
    <div>
      <div class="meta"><span>${esc(i.orgao)}</span><span>${esc(i.municipio)}/${esc(i.uf)}</span><span>${esc(i.esfera)}</span></div>
      <h2 class="obj">${esc(i.objeto)}</h2>
      <p class="why">${esc(i.motivo)}</p>
      ${i.categorias?.length ? `<div class="tags">${i.categorias.map(c => `<span class="tag">${esc(CATS[c] ?? c)}</span>`).join('')}</div>` : ''}
    </div>
    <div class="side">
      <span class="valor ${i.valor ? '' : 'sigiloso'}">${i.valor ? brl.format(i.valor) : 'valor sigiloso'}</span>
      <span class="prazo ${p.cls}">${p.txt}</span>
      ${pill}
    </div>
    ${i.complemento ? `<details><summary>Informação complementar</summary><p>${esc(i.complemento)}</p></details>` : ''}
    ${track}
    <div class="actions">
      ${link(i.linkPncp, 'Aviso no PNCP')}
      ${link(i.linkOrigem, 'Enviar proposta')}
      <span class="spacer"></span>
      ${acao}
    </div>
  </article>`;
}

const stat = (n, label) => `<span class="stat"><b>${n}</b>${label}</span>`;
const vazio = (titulo, texto) => `<div class="state"><strong>${titulo}</strong>${texto}</div>`;

/* ---------------------------------------------------------------- radar */

function renderRadar() {
  const q = norm($('q').value.trim());
  const vmax = parseFloat($('vmax').value) || Infinity;
  const allow = new Set(['alta']);
  if ($('showVerificar').checked) allow.add('verificar');
  if ($('showBaixa').checked) allow.add('baixa');
  if ($('showFora').checked) allow.add('fora');

  const all = radar.itens || [];
  const shown = all.filter(i =>
    allow.has(i.fit) &&
    (i.valor || 0) <= vmax &&
    (!activeCats.size || i.categorias.some(c => activeCats.has(c))) &&
    (!q || norm(`${i.objeto} ${i.orgao} ${i.municipio} ${i.complemento}`).includes(q)));

  const count = f => all.filter(i => i.fit === f).length;
  const soma = shown.reduce((s, i) => s + (i.valor || 0), 0);
  $('summary').innerHTML =
    stat(shown.length, 'exibidas') + stat(count('alta'), 'fit PF') + stat(count('verificar'), 'a verificar') +
    stat(all.length, 'abertas no recorte') + stat(brl.format(soma), 'estimado exibido') +
    `<span class="when">Consulta: ${radar.consultadoEm ? new Date(radar.consultadoEm).toLocaleTimeString('pt-BR') : '-'}${radar.truncado ? ' · resultado limitado a 1.500 registros' : ''}</span>`;

  $('list').innerHTML = shown.length
    ? shown.map(i => card(i)).join('')
    : vazio('Nenhuma dispensa neste filtro', 'Amplie o prazo, troque a UF ou inclua os itens a verificar.');
}

async function loadRadar(fresh = false) {
  $('list').innerHTML = '<div class="skeleton"></div>'.repeat(4);
  $('summary').textContent = '';
  $('reload').disabled = true;
  try {
    radar = await api.dispensas($('uf').value, $('dias').value, fresh);
    renderRadar();
  } catch (e) {
    $('list').innerHTML = `<div class="state err">${esc(e.message)}</div>`;
  } finally {
    $('reload').disabled = false;
  }
}

/* --------------------------------------------------------- candidaturas */

function renderSalvas() {
  $('countSalvas').textContent = salvas.length;
  const n = s => salvas.filter(c => c.status === s).length;

  $('statusFilter').innerHTML =
    `<button type="button" class="chip" data-st="" aria-pressed="${statusAtivo === ''}">Todas <small>${salvas.length}</small></button>` +
    Object.entries(STATUS).map(([k, l]) =>
      `<button type="button" class="chip" data-st="${k}" aria-pressed="${statusAtivo === k}">${esc(l)} <small>${n(k)}</small></button>`).join('');

  const shown = salvas.filter(c => !statusAtivo || c.status === statusAtivo);
  const emJogo = salvas.filter(c => c.status === 'salva' || c.status === 'enviada');
  $('summarySalvas').innerHTML =
    stat(emJogo.length, 'em andamento') +
    stat(brl.format(emJogo.reduce((s, c) => s + (c.aviso.valor || 0), 0)), 'estimado em andamento') +
    stat(n('ganhei'), 'ganhas');

  $('listSalvas').innerHTML = shown.length
    ? shown.map(c => card(c.aviso, c)).join('')
    : salvas.length
      ? vazio('Nada com este status', 'Escolha outro filtro acima.')
      : vazio('Nenhuma candidatura salva', 'No radar, clique em "Salvar" nas dispensas que você quer disputar.');
}

async function loadSalvas() {
  try {
    salvas = (await api.candidaturas()).itens;
  } catch (e) {
    $('listSalvas').innerHTML = `<div class="state err">${esc(e.message)}</div>`;
    toast(e.message, true);
    return;
  }
  renderSalvas();
  renderRadar();   // atualiza os botões "Salva"
}

function setView(v) {
  view = v === 'salvas' ? 'salvas' : 'radar';
  store.set('view', view);
  $('viewRadar').hidden = view !== 'radar';
  $('viewSalvas').hidden = view !== 'salvas';
  document.querySelectorAll('.tabs [data-view]').forEach(b => b.setAttribute('aria-selected', b.dataset.view === view));
}

/* -------------------------------------------------------------- eventos */

$('uf').innerHTML = UFS.map(u => `<option value="${u}">${u === 'BR' ? 'Brasil todo' : u}</option>`).join('');
$('uf').value = store.get('uf', 'SC');
$('dias').value = store.get('dias', '30');

$('cats').innerHTML = Object.entries(CATS).map(([k, l]) =>
  `<button type="button" class="chip" data-cat="${k}" aria-pressed="${activeCats.has(k)}">${esc(l)}</button>`).join('');
$('cats').addEventListener('click', e => {
  const b = e.target.closest('[data-cat]'); if (!b) return;
  const k = b.dataset.cat;
  activeCats.has(k) ? activeCats.delete(k) : activeCats.add(k);
  b.setAttribute('aria-pressed', activeCats.has(k));
  store.set('cats', [...activeCats]); renderRadar();
});

['q', 'vmax', 'showVerificar', 'showBaixa', 'showFora'].forEach(id => $(id).addEventListener('input', renderRadar));
$('uf').addEventListener('change', () => { store.set('uf', $('uf').value); loadRadar(); });
$('dias').addEventListener('change', () => { store.set('dias', $('dias').value); loadRadar(); });
$('reload').addEventListener('click', () => loadRadar(true));

document.querySelector('.tabs').addEventListener('click', e => {
  const b = e.target.closest('[data-view]'); if (b) setView(b.dataset.view);
});

$('list').addEventListener('click', async e => {
  if (e.target.closest('[data-goto-salvas]')) return setView('salvas');
  const b = e.target.closest('[data-save]'); if (!b) return;
  const aviso = radar.itens.find(i => i.id === b.dataset.save); if (!aviso) return;
  b.disabled = true;
  try {
    salvas.push(await api.salvar(aviso));
    renderSalvas(); renderRadar();
    toast('Salva em Minhas candidaturas.');
  } catch (err) {
    b.disabled = false;
    toast(err.message, true);
  }
});

$('statusFilter').addEventListener('click', e => {
  const b = e.target.closest('[data-st]'); if (!b) return;
  statusAtivo = b.dataset.st; renderSalvas();
});

$('listSalvas').addEventListener('click', async e => {
  const b = e.target.closest('[data-remove]'); if (!b) return;
  if (!confirm('Remover esta candidatura? As notas serão apagadas.')) return;
  const id = Number(b.dataset.remove);
  try {
    await api.remover(id);
    salvas = salvas.filter(c => c.id !== id);
    renderSalvas(); renderRadar();
    toast('Candidatura removida.');
  } catch (err) { toast(err.message, true); }
});

$('listSalvas').addEventListener('change', async e => {
  const el = e.target;
  const campo = el.dataset.status ? 'status' : el.dataset.notas ? 'notas' : null;
  if (!campo) return;
  const id = Number(el.dataset[campo]);
  try {
    const atual = await api.atualizar(id, { [campo]: el.value });
    salvas = salvas.map(c => c.id === id ? atual : c);
    if (campo === 'status') renderSalvas();   // notas: não re-renderiza para não tirar o foco
    toast(campo === 'status' ? `Status: ${STATUS[atual.status]}.` : 'Notas salvas.');
  } catch (err) { toast(err.message, true); }
});

setView(view);
renderSalvas();
loadSalvas();
loadRadar();
