(function () {
  var total = document.getElementById('total');
  function update() {
    if (!total) return;
    var p = document.querySelector('input[name=plan]:checked');
    var y = document.querySelector('input[name=years]:checked');
    if (!p || !y) { total.textContent = '—'; return; }
    var c = Math.round(parseInt(p.dataset.price, 10) * parseInt(y.value, 10) * (100 - parseFloat(y.dataset.discount || '0')) / 100);
    total.textContent = '₱' + (c / 100).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }
  document.addEventListener('change', update);
  update();
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-copy]');
    if (!b) return;
    var el = document.getElementById(b.dataset.copy);
    el.select();
    (navigator.clipboard ? navigator.clipboard.writeText(el.value) : Promise.reject()).catch(function () { document.execCommand('copy'); });
    b.textContent = 'Copied';
  });
})();
