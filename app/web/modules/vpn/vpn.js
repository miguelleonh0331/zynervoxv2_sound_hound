document.querySelectorAll('form[data-confirm]').forEach(form => {
  form.addEventListener('submit', event => {
    if (!window.confirm(form.dataset.confirm)) event.preventDefault();
  });
});
document.getElementById('vpn-search').addEventListener('input', event => {
  const query = event.target.value.toLocaleLowerCase();
  document.querySelectorAll('#vpn-inventory tr').forEach(row => {
    row.hidden = !row.textContent.toLocaleLowerCase().includes(query);
  });
});
