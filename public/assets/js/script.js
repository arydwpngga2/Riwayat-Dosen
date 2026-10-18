// Update kecil: tampilan tetap berfungsi penuh tanpa JavaScript.
(function () {
  var form = document.querySelector('form.search');
  var input = form && form.querySelector('input[name="q"]');
  if (!form || !input) return;

  // Beri umpan balik saat pencarian dikirim (PDDikti kadang lambat).
  form.addEventListener('submit', function () {
    var btn = form.querySelector('button');
    if (btn) { btn.textContent = 'Mencari…'; btn.disabled = true; }
  });

  // Tekan "/" untuk langsung fokus ke kolom pencarian.
  document.addEventListener('keydown', function (e) {
    var tag = (document.activeElement || {}).tagName;
    if (e.key === '/' && tag !== 'INPUT' && tag !== 'TEXTAREA') {
      e.preventDefault();
      input.focus();
      input.select();
    }
  });

  // Kembalikan tombol bila halaman dipulihkan dari cache browser (tombol Back).
  window.addEventListener('pageshow', function () {
    var btn = form.querySelector('button');
    if (btn) { btn.textContent = 'Cari'; btn.disabled = false; }
  });
})();