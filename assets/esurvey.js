// Video e-survey uploader: sends each video in 4 MB pieces to /e-survey/api.php,
// then emails the details + private viewing link through the normal Formspree form.
(function () {
  var form = document.getElementById('esurvey-form');
  if (!form) return;

  var API = '/e-survey/api.php';
  var input = form.querySelector('input[type=file]');
  var drop = form.querySelector('.es-drop');
  var list = form.querySelector('.es-list');
  var status = form.querySelector('.form-status');
  var submit = form.querySelector('button[type=submit]');
  var files = [];
  var MAX_FILES = 15, MAX_FILE = 1024 * 1024 * 1024;

  function size(b) {
    if (b >= 1073741824) return (b / 1073741824).toFixed(2) + ' GB';
    if (b >= 1048576) return (b / 1048576).toFixed(1) + ' MB';
    return Math.max(1, Math.round(b / 1024)) + ' KB';
  }
  function esc(s) { return String(s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  var okExt = /\.(mp4|mov|m4v|3gp|webm|avi|mkv|hevc|jpe?g|png|heic|heif|webp|gif|pdf)$/i;

  function render() {
    list.innerHTML = '';
    files.forEach(function (f, i) {
      var li = document.createElement('li');
      li.className = 'es-item' + (f.done ? ' done' : '');
      li.innerHTML = '<span class="es-icon" aria-hidden="true">' + (/^video|\.(mov|mp4|m4v|3gp|webm|avi|mkv)$/i.test(f.file.type || f.file.name) ? '▶' : '▣') + '</span>' +
        '<span class="es-name">' + esc(f.file.name) + '<small>' + size(f.file.size) + '</small></span>' +
        '<span class="es-bar"><span style="width:' + Math.round(f.progress * 100) + '%"></span></span>' +
        (f.busy ? '' : '<button type="button" class="es-remove" aria-label="Remove ' + esc(f.file.name) + '">×</button>');
      var rm = li.querySelector('.es-remove');
      if (rm) rm.addEventListener('click', function () { files.splice(i, 1); render(); });
      list.appendChild(li);
    });
    var total = files.reduce(function (a, f) { return a + f.file.size; }, 0);
    form.querySelector('.es-total').textContent = files.length ? files.length + ' file' + (files.length > 1 ? 's' : '') + ' · ' + size(total) : '';
  }

  function add(fileList) {
    status.textContent = ''; status.className = 'form-status';
    Array.prototype.forEach.call(fileList, function (file) {
      if (files.length >= MAX_FILES) { status.className = 'form-status error'; status.textContent = 'Up to ' + MAX_FILES + ' files per survey.'; return; }
      if (!okExt.test(file.name) && !/^(video|image)\//.test(file.type)) { status.className = 'form-status error'; status.textContent = file.name + ' is not a video or photo.'; return; }
      if (file.size > MAX_FILE) { status.className = 'form-status error'; status.textContent = file.name + ' is over 1 GB. Please trim it or film shorter clips.'; return; }
      if (files.some(function (f) { return f.file.name === file.name && f.file.size === file.size; })) return;
      files.push({ file: file, progress: 0, done: false });
    });
    render();
  }

  input.addEventListener('change', function () { add(input.files); input.value = ''; });
  ['dragenter', 'dragover'].forEach(function (ev) { drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('over'); }); });
  ['dragleave', 'drop'].forEach(function (ev) { drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.remove('over'); }); });
  drop.addEventListener('drop', function (e) { if (e.dataTransfer && e.dataTransfer.files) add(e.dataTransfer.files); });

  function post(data) {
    var fd = new FormData();
    Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
    return fetch(API, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) {
      return r.json().catch(function () { return { ok: false, error: 'Server error (' + r.status + ')' }; }).then(function (j) { j._status = r.status; return j; });
    });
  }

  function wait(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }

  function uploadFile(session, f, idx, onTick) {
    var CH = session.chunk, file = f.file, offset = 0;
    var name = file.name || ('clip-' + (idx + 1) + '.mp4');
    if (!okExt.test(name)) name += (/^image\//.test(file.type) ? '.jpg' : '.mp4');
    function next(tries) {
      if (offset >= file.size) return Promise.resolve();
      var len = Math.min(CH, file.size - offset);
      var q = new URLSearchParams({ action: 'chunk', sid: session.sid, key: session.key, idx: idx, name: name, size: file.size, offset: offset });
      return fetch(API + '?' + q.toString(), { method: 'POST', body: file.slice(offset, offset + len), headers: { 'Content-Type': 'application/octet-stream' }, credentials: 'same-origin' })
        .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'retry' }; }).then(function (j) { j._status = r.status; return j; }); })
        .then(function (j) {
          if (j.ok) { offset += len; f.progress = offset / file.size; onTick(); return next(0); }
          if (j.error === 'resync' && typeof j.have === 'number') { offset = j.have; return next(tries); }
          if (j._status >= 400 && j._status < 500 && j._status !== 408 && j._status !== 429) throw new Error(j.error || 'Upload refused');
          throw new Error(j.error || 'retry');
        })
        .catch(function (err) {
          if (tries < 5 && !/refused|supported|too large|limit|session/i.test(err.message)) return wait(1500 * (tries + 1)).then(function () { return next(tries + 1); });
          throw err;
        });
    }
    return next(0);
  }

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    if (!window.fetch || !window.FormData || !Blob.prototype.slice) {
      status.className = 'form-status error';
      status.textContent = 'Your browser cannot upload here. Please email your videos to info@anglianaircon.co.uk (WeTransfer works well).';
      return;
    }
    if (!files.length) { status.className = 'form-status error'; status.textContent = 'Please add at least one video or photo.'; drop.focus(); return; }
    if (!form.reportValidity()) return;

    var fd = new FormData(form);
    var details = {};
    ['name', 'contact', 'postcode', 'property', 'service', 'rooms', 'timescale', 'message'].forEach(function (k) { details[k] = (fd.get(k) || '').toString(); });
    details.page = location.href;

    submit.disabled = true;
    submit.dataset.label = submit.dataset.label || submit.innerHTML;
    status.className = 'form-status';
    status.textContent = 'Starting upload… please keep this page open.';
    files.forEach(function (f) { f.busy = true; });
    render();

    var totalBytes = files.reduce(function (a, f) { return a + f.file.size; }, 0);
    function tick() {
      var sent = files.reduce(function (a, f) { return a + f.file.size * f.progress; }, 0);
      var pct = Math.floor(sent / totalBytes * 100);
      submit.textContent = 'Uploading ' + pct + '%';
      status.textContent = 'Uploading ' + size(sent) + ' of ' + size(totalBytes) + '. Please keep this page open.';
      list.querySelectorAll('.es-bar span').forEach(function (bar, i) { if (files[i]) bar.style.width = Math.round(files[i].progress * 100) + '%'; });
    }

    var session;
    post({ action: 'start', _gotcha: fd.get('_gotcha') || '' })
      .then(function (j) {
        if (!j.ok) throw new Error(j.error || 'Could not start the upload');
        session = j;
        return files.reduce(function (p, f, i) {
          return p.then(function () { return uploadFile(session, f, i, tick).then(function () { f.done = true; }); });
        }, Promise.resolve());
      })
      .then(function () {
        status.textContent = 'Finishing…';
        var data = Object.assign({ action: 'finish', sid: session.sid, key: session.key }, details);
        return post(data);
      })
      .then(function (j) {
        if (!j.ok) throw new Error(j.error || 'Could not finish the upload');
        // Email the enquiry through the site's usual form service
        var mail = new FormData();
        mail.append('_subject', 'VIDEO E-SURVEY ' + j.ref + ' – ' + details.name + ' (' + (details.postcode || 'no postcode') + ') – ' + location.hostname);
        mail.append('enquiry_type', 'Video e-survey');
        Object.keys(details).forEach(function (k) { if (details[k]) mail.append(k, details[k]); });
        mail.append('videos', j.files + ' file(s), ' + j.size);
        mail.append('watch_videos', j.view);
        mail.append('website', location.origin);
        ['utm_source', 'utm_medium', 'utm_campaign', 'first_page', 'referrer'].forEach(function (k) { var v = form.querySelector('[name=' + k + ']'); if (v && v.value) mail.append(k, v.value); });
        return fetch(form.action, { method: 'POST', body: mail, headers: { Accept: 'application/json' } })
          .then(function (r) { if (!r.ok) throw new Error('mail'); })
          .catch(function () { return post({ action: 'notify', sid: session.sid, key: session.key }); })
          .then(function () { return j; });
      })
      .then(function (j) {
        if (window.gtag) try { gtag('event', 'generate_lead', { method: 'video_e_survey' }); } catch (e) {}
        form.innerHTML = '<div class="form-done" role="status"><div class="tick">✓</div>' +
          '<h3>Thanks ' + esc(details.name.split(' ')[0]) + ', your survey has arrived.</h3>' +
          '<p>Reference <strong>' + esc(j.ref) + '</strong> · ' + esc(j.files + ' file(s), ' + j.size) + '.</p>' +
          '<p>We will watch your videos and come back to you with a written quote or a couple of questions, usually within one working day (Mon–Fri, 8am–5.30pm).</p></div>';
        form.scrollIntoView({ behavior: 'smooth', block: 'start' });
      })
      .catch(function (err) {
        submit.disabled = false;
        submit.innerHTML = submit.dataset.label;
        files.forEach(function (f) { f.busy = false; });
        render();
        status.className = 'form-status error';
        status.textContent = (err && err.message && err.message !== 'retry' ? err.message + ' ' : 'The upload did not complete. ') +
          'Please try again on Wi-Fi, or email the videos to info@anglianaircon.co.uk.';
      });
  });
})();
