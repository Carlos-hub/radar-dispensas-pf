/* Radar de Dispensas PF: melhorias progressivas. Tudo funciona sem este arquivo. */

// Filtros do radar: envia ao mudar um seletor, uma categoria ou uma opção de aderência.
const filtros = document.querySelector('form[data-auto-enviar]');
if (filtros) {
  filtros.addEventListener('change', e => {
    if (e.target.matches('select, input[type=checkbox]')) filtros.requestSubmit();
  });
}
