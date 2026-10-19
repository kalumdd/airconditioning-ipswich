// Menu + enquiry forms (sent to Formspree without leaving the page)
(function () {
  var btn = document.querySelector('.menu-btn');
  var nav = document.getElementById('nav');
  if (btn && nav) {
    btn.addEventListener('click', function () {
      var open = nav.classList.toggle('open');
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
  }
  var PHONE = document.body.getAttribute('data-phone') || '';
  document.querySelectorAll('form.quote-form').forEach(function (form) {
    form.addEventListener('submit', function (e) {
      if (!window.fetch || !window.FormData) return;
      e.preventDefault();
      if (!form.reportValidity()) return;
      var status = form.querySelector('.form-status');
      var submit = form.querySelector('button[type="submit"]');
      submit.disabled = true;
      submit.dataset.label = submit.dataset.label || submit.innerHTML;
      submit.textContent = 'Sending…';
      if (status) { status.textContent = ''; status.className = 'form-status'; }
      fetch(form.action, { method: 'POST', body: new FormData(form), headers: { Accept: 'application/json' } })
        .then(function (r) {
          if (!r.ok) throw new Error('failed');
          if (window.gtag) try { gtag('event', 'generate_lead', { method: form.dataset.form || 'quote_form' }); } catch (err) {}
          form.innerHTML = '<div class="form-done" role="status"><div class="tick">✓</div>' +
            '<h3>Thanks, we have your enquiry.</h3>' +
            '<p>We reply during working hours (Mon–Fri, 8am–5.30pm), usually the same day. ' +
            'If it is urgent, call <a href="tel:' + PHONE.replace(/\s/g, '') + '">' + PHONE + '</a>.</p></div>';
        })
        .catch(function () {
          submit.disabled = false;
          submit.innerHTML = submit.dataset.label;
          if (status) {
            status.className = 'form-status error';
            status.textContent = 'Sorry, that did not send. Please try again or call ' + PHONE + '.';
          }
        });
    });
  });
})();
