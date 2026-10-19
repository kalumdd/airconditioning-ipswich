// Lead tools: aircon size calculator, running-cost calculator, F-Gas/TM44 checker,
// quote-form prefill, lead source tracking and the "quote by video" prompt.
(function () {
  var $ = function (s, el) { return (el || document).querySelector(s); };
  var $$ = function (s, el) { return Array.prototype.slice.call((el || document).querySelectorAll(s)); };
  function store(k, v) { try { if (v === undefined) return localStorage.getItem(k); localStorage.setItem(k, v); } catch (e) { return null; } }
  function sstore(k, v) { try { if (v === undefined) return sessionStorage.getItem(k); sessionStorage.setItem(k, v); } catch (e) { return null; } }
  function esc(s) { return String(s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function gbp(n) { return '£' + (n >= 100 ? Math.round(n).toLocaleString('en-GB') : n.toFixed(2)); }
  function track(name, params) { if (window.gtag) try { gtag('event', name, params || {}); } catch (e) {} }

  // ---------- Lead source tracking (adds hidden fields to every enquiry form) ----------
  var qs = new URLSearchParams(location.search);
  if (!sstore('lead_first_page')) {
    sstore('lead_first_page', location.pathname);
    sstore('lead_referrer', document.referrer ? document.referrer.split('?')[0] : 'direct');
    ['utm_source', 'utm_medium', 'utm_campaign'].forEach(function (k) { if (qs.get(k)) sstore('lead_' + k, qs.get(k)); });
    if (qs.get('gclid')) sstore('lead_utm_source', 'google-ads');
  }
  $$('form.quote-form, form#esurvey-form').forEach(function (form) {
    var add = function (name, val) {
      if (!val || form.querySelector('[name="' + name + '"]')) return;
      var i = document.createElement('input'); i.type = 'hidden'; i.name = name; i.value = val; form.appendChild(i);
    };
    add('first_page', sstore('lead_first_page'));
    add('referrer', sstore('lead_referrer'));
    add('sent_from_page', location.pathname);
    ['utm_source', 'utm_medium', 'utm_campaign'].forEach(function (k) { add(k, sstore('lead_' + k)); });
  });

  // ---------- Prefill quote forms from calculator links (?msg=...&service=...) ----------
  $$('form.quote-form, form#esurvey-form').forEach(function (form) {
    var msg = qs.get('msg'), service = qs.get('service'), pc = qs.get('postcode');
    var ta = form.querySelector('textarea[name="message"]');
    if (msg && ta && !ta.value) ta.value = msg;
    var sel = form.querySelector('select[name="service"]');
    if (service && sel) $$('option', sel).forEach(function (o) { if (o.textContent.toLowerCase().indexOf(service.toLowerCase()) > -1) sel.value = o.value || o.textContent; });
    var p = form.querySelector('input[name="postcode"]');
    if (pc && p && !p.value) p.value = pc;
  });

  // ---------- Size calculator ----------
  var SIZES = [2.0, 2.5, 3.5, 4.2, 5.0, 6.0, 7.1, 8.0, 10.0, 12.5, 14.0];
  function sizeTool(el) {
    el.innerHTML =
      '<div class="tool-grid"><div class="tool-inputs">' +
      '<label class="t-full"><span>Room type</span><select name="type">' +
      '<option value="1">Bedroom</option><option value="1.05" selected>Living room / lounge</option><option value="1.15">Kitchen / diner</option>' +
      '<option value="1.1">Home office / study</option><option value="1.25">Loft room</option><option value="1.6">Conservatory / orangery</option>' +
      '<option value="1.35">Garden room / cabin</option><option value="1.2">Office (commercial)</option><option value="1.15">Shop / salon</option><option value="1.3">Gym / studio</option></select></label>' +
      '<label><span>Length (m)</span><input name="l" type="number" inputmode="decimal" min="1" max="40" step="0.1" value="5"></label>' +
      '<label><span>Width (m)</span><input name="w" type="number" inputmode="decimal" min="1" max="40" step="0.1" value="4"></label>' +
      '<label><span>Ceiling height (m)</span><input name="h" type="number" inputmode="decimal" min="2" max="6" step="0.1" value="2.4"></label>' +
      '<label><span>People usually in it</span><input name="p" type="number" inputmode="numeric" min="0" max="60" step="1" value="2"></label>' +
      '<fieldset class="t-full"><legend>Sunshine through the windows</legend><div class="t-seg">' +
      '<label><input type="radio" name="sun" value="0.9"><span>Little / north</span></label>' +
      '<label><input type="radio" name="sun" value="1" checked><span>Some</span></label>' +
      '<label><input type="radio" name="sun" value="1.2"><span>Lots / south or west</span></label></div></fieldset>' +
      '<fieldset class="t-full"><legend>Insulation</legend><div class="t-seg">' +
      '<label><input type="radio" name="ins" value="1.15"><span>Older / single glazed</span></label>' +
      '<label><input type="radio" name="ins" value="1" checked><span>Average</span></label>' +
      '<label><input type="radio" name="ins" value="0.9"><span>New build / well insulated</span></label></div></fieldset>' +
      '<label class="t-full"><span>Computers, TVs, gaming PCs or equipment</span><input name="e" type="number" inputmode="numeric" min="0" max="60" step="1" value="1"></label>' +
      '</div><div class="tool-result" aria-live="polite"></div></div>';
    var res = $('.tool-result', el);
    function v(n) { var i = el.querySelector('[name="' + n + '"]:checked') || el.querySelector('[name="' + n + '"]'); return parseFloat(i.value) || 0; }
    function calc() {
      var area = v('l') * v('w'), vol = area * Math.max(2, v('h'));
      var typeSel = $('[name=type]', el), typeName = typeSel.options[typeSel.selectedIndex].text;
      var watts = vol * 50 * v('type') * v('sun') * v('ins') + v('p') * 100 + v('e') * 150;
      var kw = watts / 1000 * 1.1; // 10% headroom
      var pick = SIZES.filter(function (s) { return s >= kw; })[0];
      var multi = !pick;
      var btu = Math.round(kw * 3412 / 500) * 500;
      var html = '<span class="t-eyebrow">Recommended size</span>';
      if (multi) {
        html += '<div class="t-big">' + kw.toFixed(1) + '<small> kW+</small></div><p>That is a big space: it usually needs two indoor units or a ducted/cassette system. Send us a video and we will design it properly.</p>';
      } else {
        html += '<div class="t-big">' + pick.toFixed(1) + '<small> kW</small></div><p class="t-sub">≈ ' + btu.toLocaleString('en-GB') + ' BTU/h · calculated load ' + kw.toFixed(2) + ' kW for ' + area.toFixed(1) + ' m²</p>';
        html += '<div class="t-scale">' + SIZES.slice(0, 8).map(function (s) { return '<span class="' + (s === pick ? 'on' : '') + '">' + s.toFixed(1) + '</span>'; }).join('') + '</div>';
        html += '<p>A ' + pick.toFixed(1) + ' kW wall-mounted split will cool this ' + typeName.toLowerCase() + ' in summer and heat it efficiently in winter.</p>';
      }
      html += '<p class="t-note">A guide, not a survey. Glazing, roof type and room use all change the answer, so we confirm sizes before quoting.</p>';
      var msg = 'Size calculator: ' + typeName + ', ' + v('l') + 'm x ' + v('w') + 'm (' + area.toFixed(1) + ' m2), suggested ' + (multi ? kw.toFixed(1) + ' kW+ (multiple units)' : pick.toFixed(1) + ' kW') + '.';
      html += '<div class="t-ctas"><a class="btn btn-green" data-track="size_to_esurvey" href="/e-survey/?msg=' + encodeURIComponent(msg) + '">Get a fixed quote by video</a>' +
        '<a class="btn btn-ghost" data-track="size_to_contact" href="/contact/?msg=' + encodeURIComponent(msg) + '">Ask for a quote</a></div>';
      res.innerHTML = html;
    }
    el.addEventListener('input', calc);
    el.addEventListener('change', calc);
    calc();
  }

  // ---------- Running cost calculator ----------
  function runTool(el) {
    var elec = parseFloat(el.dataset.elec || '26.3'), gas = parseFloat(el.dataset.gas || '8.0');
    el.innerHTML =
      '<div class="t-tabs" role="tablist"><button type="button" role="tab" aria-selected="true" data-mode="cool">Cooling costs</button><button type="button" role="tab" aria-selected="false" data-mode="heat">Heating vs gas &amp; electric</button></div>' +
      '<div class="tool-grid"><div class="tool-inputs">' +
      '<label><span>System size (kW)</span><select name="kw">' + SIZES.slice(0, 9).map(function (s) { return '<option' + (s === 3.5 ? ' selected' : '') + '>' + s.toFixed(1) + '</option>'; }).join('') + '</select></label>' +
      '<label><span>Hours a day</span><input name="hrs" type="number" inputmode="decimal" min="1" max="24" step="0.5" value="6"></label>' +
      '<label><span>Days a year</span><input name="days" type="number" inputmode="numeric" min="1" max="365" step="1" value="60"></label>' +
      '<label><span>Electricity (p/kWh)</span><input name="elec" type="number" inputmode="decimal" min="5" max="80" step="0.1" value="' + elec + '"></label>' +
      '<label class="t-cool"><span>Cooling efficiency (SEER)</span><select name="seer"><option value="5.6">A+ (SEER 5.6)</option><option value="6.1" selected>A++ (SEER 6.1)</option><option value="8.5">A+++ (SEER 8.5)</option></select></label>' +
      '<label class="t-heat" hidden><span>Heating efficiency (SCOP)</span><select name="scop"><option value="3.4">A (SCOP 3.4)</option><option value="4.0" selected>A+ (SCOP 4.0)</option><option value="4.6">A++ (SCOP 4.6)</option><option value="5.1">A+++ (SCOP 5.1)</option></select></label>' +
      '<label class="t-heat" hidden><span>Gas (p/kWh)</span><input name="gas" type="number" inputmode="decimal" min="2" max="40" step="0.1" value="' + gas + '"></label>' +
      '</div><div class="tool-result" aria-live="polite"></div></div>' +
      '<p class="t-note">Assumes the unit averages half its rated output once the room is at temperature (inverter systems throttle down). Default prices: Ofgem cap from 1 October 2026, ' + elec + 'p electricity and ' + gas + 'p gas per kWh. Change them to match your bill.</p>';
    var mode = 'cool', res = $('.tool-result', el);
    function n(name) { return parseFloat($('[name=' + name + ']', el).value) || 0; }
    function calc() {
      var kw = n('kw'), hrs = n('hrs'), days = n('days'), p = n('elec') / 100;
      var load = kw * 0.5, html;
      if (mode === 'cool') {
        var perHour = load / n('seer') * p;
        html = '<span class="t-eyebrow">Typical cooling cost</span><div class="t-big">' + (perHour * 100).toFixed(1) + '<small>p / hour</small></div>' +
          '<dl class="t-dl"><dt>Per day (' + hrs + ' h)</dt><dd>' + gbp(perHour * hrs) + '</dd><dt>Per year (' + days + ' days)</dt><dd>' + gbp(perHour * hrs * days) + '</dd></dl>' +
          '<p>Less than running a kettle for ' + Math.max(1, Math.round(perHour / (3 * p) * 60)) + ' minutes each hour.</p>';
      } else {
        var heat = load * hrs * days, hp = heat / n('scop') * p, el2 = heat * p, gs = heat / 0.9 * (n('gas') / 100);
        var max = Math.max(hp, el2, gs);
        var bar = function (label, val, cls) { return '<div class="t-bar ' + cls + '"><span class="t-bl">' + label + '</span><span class="t-bt"><span style="width:' + Math.max(4, val / max * 100) + '%"></span></span><b>' + gbp(val) + '</b></div>'; };
        html = '<span class="t-eyebrow">Yearly heating cost for this room</span>' +
          bar('Air-to-air heat pump', hp, 'hp') + bar('Gas boiler (90%)', gs, 'gas') + bar('Electric heaters', el2, 'elec') +
          '<p class="t-save">Saves about <strong>' + gbp(el2 - hp) + ' a year</strong> against electric heaters' + (gs > hp ? ' and ' + gbp(gs - hp) + ' against gas' : '') + '.</p>';
      }
      var msg = 'Running cost calculator: ' + kw.toFixed(1) + ' kW system, ' + hrs + ' h/day, ' + days + ' days/yr (' + (mode === 'cool' ? 'cooling' : 'heating') + ').';
      html += '<div class="t-ctas"><a class="btn btn-green" data-track="cost_to_esurvey" href="/e-survey/?msg=' + encodeURIComponent(msg) + '">Get a quote by video</a></div>';
      res.innerHTML = html;
    }
    $$('.t-tabs button', el).forEach(function (b) {
      b.addEventListener('click', function () {
        mode = b.dataset.mode;
        $$('.t-tabs button', el).forEach(function (x) { x.setAttribute('aria-selected', x === b ? 'true' : 'false'); });
        $$('.t-cool', el).forEach(function (x) { x.hidden = mode !== 'cool'; });
        $$('.t-heat', el).forEach(function (x) { x.hidden = mode !== 'heat'; });
        $('[name=days]', el).value = mode === 'cool' ? 60 : 150;
        $('[name=hrs]', el).value = mode === 'cool' ? 6 : 8;
        calc();
      });
    });
    el.addEventListener('input', calc);
    el.addEventListener('change', calc);
    calc();
  }

  // ---------- F-Gas leak-check & TM44 checker (GB rules, AR4 GWPs) ----------
  var GAS = [['R410A', 2088], ['R32', 675], ['R407C', 1774], ['R134a', 1430], ['R404A', 3922], ['R454B', 466], ['R513A', 631], ['R22 (HCFC)', 0], ['R290 (propane)', 3], ['R744 (CO₂)', 1]];
  function fgasTool(el) {
    el.innerHTML =
      '<div class="tool-grid"><div class="tool-inputs">' +
      '<label><span>Refrigerant</span><select name="gas">' + GAS.map(function (g, i) { return '<option value="' + i + '">' + g[0] + '</option>'; }).join('') + '</select></label>' +
      '<label><span>Charge per system (kg)</span><input name="kg" type="number" inputmode="decimal" min="0.1" max="2000" step="0.1" value="6"></label>' +
      '<label><span>Total cooling capacity on site (kW)</span><input name="cap" type="number" inputmode="decimal" min="0" max="10000" step="0.5" value="24"></label>' +
      '<label><span>Number of systems</span><input name="n" type="number" inputmode="numeric" min="1" max="500" step="1" value="2"></label>' +
      '<label class="t-check t-full"><input type="checkbox" name="ld"><span>Fixed leak detection installed</span></label>' +
      '<label class="t-check t-full"><input type="checkbox" name="herm"><span>Hermetically sealed and labelled as such</span></label>' +
      '</div><div class="tool-result" aria-live="polite"></div></div>' +
      '<p class="t-note">Based on the F-Gas Regulation as it applies in Great Britain and CIBSE TM44. Guidance only: we confirm charges from nameplates and records during a site visit.</p>';
    var res = $('.tool-result', el);
    function calc() {
      var g = GAS[+$('[name=gas]', el).value], kg = parseFloat($('[name=kg]', el).value) || 0;
      var cap = parseFloat($('[name=cap]', el).value) || 0, n = parseInt($('[name=n]', el).value, 10) || 1;
      var ld = $('[name=ld]', el).checked, herm = $('[name=herm]', el).checked;
      var t = kg * g[1] / 1000, freq, html = '<span class="t-eyebrow">Per system</span>';
      if (g[1] === 0) {
        html += '<div class="t-big t-warn">R22</div><p>R22 has been banned for servicing since 2015. Leaks cannot be topped up, so these systems should be planned for replacement.</p>';
      } else {
        html += '<div class="t-big">' + t.toFixed(t < 10 ? 2 : 1) + '<small> tCO₂e</small></div>';
        if (t < 5 || (herm && t < 10)) freq = 'No mandatory leak checks';
        else if (t < 50) freq = ld ? 'Every 24 months' : 'Every 12 months';
        else if (t < 500) freq = ld ? 'Every 12 months' : 'Every 6 months';
        else freq = ld ? 'Every 6 months' : 'Every 3 months';
        html += '<dl class="t-dl"><dt>Leak checks</dt><dd>' + freq + '</dd>';
        html += '<dt>Records to keep</dt><dd>' + (t >= 5 ? 'Yes, 5 years per system' : 'Recommended') + '</dd>';
        if (t >= 500) html += '<dt>Leak detection</dt><dd>' + (ld ? 'Installed, check yearly' : 'Required by law') + '</dd>';
        html += '</dl>';
        if (g[0] === 'R410A' || g[0] === 'R404A' || g[0] === 'R407C') html += '<p>' + g[0] + ' is being phased down, so refrigerant will get dearer. Worth planning the next replacement.</p>';
      }
      html += '<span class="t-eyebrow" style="margin-top:18px">Whole site</span><dl class="t-dl"><dt>TM44 energy inspection</dt><dd>' + (cap > 12 ? 'Required at least every 5 years' : 'Not required (12 kW or under)') + '</dd>' +
        '<dt>Systems to check</dt><dd>' + n + '</dd></dl>';
      var msg = 'Compliance checker: ' + n + ' system(s), ' + g[0] + ' ' + kg + ' kg each, ' + cap + ' kW total. Please quote for leak checks / maintenance.';
      html += '<div class="t-ctas"><a class="btn btn-green" data-track="fgas_to_contact" href="/contact/?service=maintenance&msg=' + encodeURIComponent(msg) + '">Quote me a maintenance plan</a></div>';
      res.innerHTML = html;
    }
    el.addEventListener('input', calc);
    el.addEventListener('change', calc);
    calc();
  }

  $$('[data-tool="size"]').forEach(sizeTool);
  $$('[data-tool="running"]').forEach(runTool);
  $$('[data-tool="fgas"]').forEach(fgasTool);
  document.addEventListener('click', function (e) {
    var a = e.target.closest && e.target.closest('[data-track]');
    if (a) track('tool_cta', { cta: a.dataset.track });
  });

  // ---------- "Quote by video" prompt ----------
  var path = location.pathname;
  var skip = /^\/(e-survey|contact)\//.test(path) || document.body.dataset.noPromo !== undefined;
  var last = parseInt(store('esPromoClosed') || '0', 10);
  if (!skip && Date.now() - last > 14 * 864e5) {
    var shown = false;
    var show = function () {
      if (shown) return; shown = true;
      var box = document.createElement('aside');
      box.className = 'es-promo';
      box.setAttribute('aria-label', 'Quote by video');
      box.innerHTML = '<button type="button" class="es-x" aria-label="Close">×</button>' +
        '<span class="t-eyebrow">No home visit needed</span><strong>Get a quote from a 2-minute video</strong>' +
        '<p>Film the room and outside wall on your phone, upload it, and we reply with a written quote.</p>' +
        '<a class="btn btn-green" href="/e-survey/" data-track="promo_esurvey">Start a video survey</a>';
      document.body.appendChild(box);
      requestAnimationFrame(function () { box.classList.add('in'); });
      $('.es-x', box).addEventListener('click', function () { store('esPromoClosed', String(Date.now())); box.classList.remove('in'); setTimeout(function () { box.remove(); }, 300); });
    };
    var onScroll = function () {
      var max = document.documentElement.scrollHeight - innerHeight;
      if (max > 0 && scrollY / max > 0.45) { show(); removeEventListener('scroll', onScroll); }
    };
    addEventListener('scroll', onScroll, { passive: true });
    setTimeout(show, 35000);
  }
})();
