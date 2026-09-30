// Familiebøger — small progressive enhancements. The page works without this file.
(function () {
  'use strict';

  // Show the next possible start date under the price.
  var nextStart = document.querySelector('[data-next-start]');
  if (nextStart && window.fetch) {
    fetch('startdato.php', { headers: { Accept: 'application/json' } })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (data) {
        if (data && data.label) {
          nextStart.textContent = ' Første mulige start: ' + data.label + '.';
          nextStart.hidden = false;
        }
      })
      .catch(function () {});
  }

  // Prevent double submits on the buy button.
  var checkout = document.querySelector('[data-checkout-form]');
  if (checkout) {
    checkout.addEventListener('submit', function () {
      var btn = checkout.querySelector('button');
      btn.disabled = true;
      btn.textContent = 'Sender dig til betaling …';
    });
    // Re-enable if the visitor comes back with the browser's back button.
    window.addEventListener('pageshow', function () {
      var btn = checkout.querySelector('button');
      btn.disabled = false;
      btn.textContent = 'Køb Familiebøger';
    });
  }

  // Waitlist signup without leaving the page.
  var form = document.querySelector('[data-waitlist-form]');
  if (form && window.fetch && window.FormData) {
    var status = form.querySelector('[data-form-status]');
    var email = form.querySelector('input[name="email"]');
    var button = form.querySelector('button[type="submit"]');

    var setStatus = function (text, ok) {
      status.textContent = text;
      status.className = 'form-status ' + (ok ? 'form-status--ok' : 'form-status--error');
    };

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      email.removeAttribute('aria-invalid');
      if (!email.value.trim() || !email.checkValidity()) {
        email.setAttribute('aria-invalid', 'true');
        setStatus('Skriv en gyldig e-mailadresse.', false);
        email.focus();
        return;
      }
      button.disabled = true;
      fetch(form.action, {
        method: 'POST',
        body: new FormData(form),
        headers: { Accept: 'application/json' }
      })
        .then(function (r) { return r.json().catch(function () { return { ok: false }; }); })
        .then(function (data) {
          if (data.ok) {
            setStatus(data.message || 'Tak! Du er skrevet på ventelisten.', true);
            form.reset();
          } else {
            setStatus(data.message || 'Noget gik galt. Prøv igen om lidt.', false);
          }
        })
        .catch(function () { setStatus('Noget gik galt. Prøv igen om lidt.', false); })
        .then(function () { button.disabled = false; });
    });
  }
})();
