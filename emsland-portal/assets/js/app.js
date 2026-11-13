/* EmsQuartier – kleine Verbesserungen; alles funktioniert auch ohne JavaScript. */
(function () {
  'use strict';

  // Umschalter Urlaub / Business in Suchmaske
  document.querySelectorAll('[data-search]').forEach(function (form) {
    form.querySelectorAll('input[name="modus"]').forEach(function (r) {
      r.addEventListener('change', function () { form.dataset.mode = r.value; });
    });
  });

  // Abreise frühestens einen Tag nach Anreise
  function linkDates(von, bis) {
    if (!von || !bis) return;
    von.addEventListener('change', function () {
      if (!von.value) return;
      var d = new Date(von.value + 'T12:00:00');
      d.setDate(d.getDate() + 1);
      var min = d.toISOString().slice(0, 10);
      bis.min = min;
      if (!bis.value || bis.value < min) bis.value = min;
      bis.dispatchEvent(new Event('change'));
    });
  }
  linkDates(document.getElementById('s-von'), document.getElementById('s-bis'));
  linkDates(document.getElementById('f-von'), document.getElementById('f-bis'));
  linkDates(document.getElementById('b-von'), document.getElementById('b-bis'));

  // Sortierung sofort anwenden
  document.querySelectorAll('[data-autosubmit]').forEach(function (s) {
    s.addEventListener('change', function () { s.form.submit(); });
  });

  // Buchungsformular: privat/beruflich + Live-Preis
  var booking = document.querySelector('[data-booking]');
  if (booking) {
    booking.querySelectorAll('[data-mode-switch]').forEach(function (r) {
      r.addEventListener('change', function () { booking.dataset.mode = r.dataset.modeSwitch; });
    });
    var box = booking.querySelector('[data-quote]');
    var timer;
    var esc = function (s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; };
    var update = function () {
      var von = booking.querySelector('[name=von]').value;
      var bis = booking.querySelector('[name=bis]').value;
      var g = booking.querySelector('[name=gaeste]').value;
      if (!von || !bis) return;
      clearTimeout(timer);
      timer = setTimeout(function () {
        var u = booking.dataset.quoteUrl + '?u=' + encodeURIComponent(booking.dataset.listing) +
          '&von=' + von + '&bis=' + bis + '&gaeste=' + encodeURIComponent(g);
        fetch(u, { headers: { 'Accept': 'application/json' } })
          .then(function (r) { return r.json(); })
          .then(function (q) {
            if (q.error) { box.innerHTML = '<span class="error">' + esc(q.error) + '</span>'; return; }
            var h = '<table>';
            q.lines.forEach(function (l) { h += '<tr><td>' + esc(l.text) + '</td><td>' + esc(l.amount) + '</td></tr>'; });
            h += '<tr class="total"><td>Gesamtpreis</td><td>' + esc(q.total) + '</td></tr></table>';
            box.innerHTML = h;
          })
          .catch(function () { /* Preis wird beim Absenden geprüft */ });
      }, 250);
    };
    ['von', 'bis', 'gaeste'].forEach(function (n) {
      var el = booking.querySelector('[name=' + n + ']');
      el.addEventListener('change', update);
      el.addEventListener('input', update);
    });
  }

  // Sicherheitsabfrage (Admin)
  document.querySelectorAll('[data-confirm]').forEach(function (el) {
    el.addEventListener('submit', function (ev) {
      if (!window.confirm(el.dataset.confirm)) ev.preventDefault();
    });
  });
})();
