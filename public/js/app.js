/* Radar de Dispensas PF: melhorias progressivas. Tudo funciona sem este arquivo. */

// Filtros do radar: envia ao mudar um seletor, uma categoria ou uma opção de aderência.
const filtros = document.querySelector('form[data-auto-enviar]');
if (filtros) {
  filtros.addEventListener('change', e => {
    if (e.target.matches('select, input[type=checkbox]')) filtros.requestSubmit();
  });
}

// Confirmação antes de ações destrutivas.
document.addEventListener('submit', e => {
  const msg = e.target.dataset?.confirmar;
  if (msg && !confirm(msg)) e.preventDefault();
});

// Minhas candidaturas: grava status e notas ao alterar, sem recarregar a página.
let toastTimer;
function toast(msg, erro = false) {
  const t = document.getElementById('toast');
  if (!t) return;
  t.textContent = msg;
  t.className = 'toast show' + (erro ? ' err' : '');
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => { t.className = 'toast'; }, erro ? 5000 : 2200);
}

const csrf = document.querySelector('meta[name=csrf-token]')?.content;
document.querySelectorAll('form[data-candidatura]').forEach(form => {
  form.addEventListener('submit', e => e.preventDefault());
  form.addEventListener('change', async e => {
    const campo = e.target.name;
    if (campo !== 'status' && campo !== 'notas') return;
    try {
      const r = await fetch(form.action, {
        method: 'PATCH',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
        body: JSON.stringify({ [campo]: e.target.value }),
      });
      if (r.status === 419) throw new Error('A sessão expirou. Recarregue a página.');
      if (r.status === 429) throw new Error('Muitas alterações em pouco tempo. Aguarde um minuto.');
      const j = await r.json().catch(() => null);
      if (!r.ok) throw new Error(j?.message || 'Não foi possível salvar.');
      if (campo === 'status') {
        const card = form.closest('.card');
        const pill = card.querySelector('[data-pill]');
        card.className = card.className.replace(/\bst-\w+/, 'st-' + j.status);
        pill.className = 'pill st-' + j.status;
        pill.textContent = e.target.selectedOptions[0].textContent.trim();
      }
      toast(campo === 'status' ? 'Status atualizado.' : 'Notas salvas.');
    } catch (err) {
      toast(err.message, true);
    }
  });
});
