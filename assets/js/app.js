/* Cityland Online Property Bidding — front-end behaviour (no dependencies). */
(function () {
  'use strict';
  var base = document.body.getAttribute('data-base') || '';

  function $(sel, ctx) { return (ctx || document).querySelector(sel); }
  function $all(sel, ctx) { return Array.prototype.slice.call((ctx || document).querySelectorAll(sel)); }
  function pad(n) { return (n < 10 ? '0' : '') + n; }
  function peso(n) { return '₱' + Number(n).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }

  // ---------------------------------------------------------------- nav toggles
  $all('.nav-toggle').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var target = document.getElementById(btn.getAttribute('aria-controls'));
      if (!target) return;
      var open = target.classList.toggle('open');
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
  });

  // ---------------------------------------------------------------- confirmations
  $all('form[data-confirm]').forEach(function (f) {
    f.addEventListener('submit', function (e) {
      var msg = f.getAttribute('data-confirm');
      var amt = f.querySelector('[name="amount"]');
      if (amt && amt.value) msg = msg.replace('{amount}', peso(String(amt.value).replace(/,/g, '')));
      if (!window.confirm(msg)) e.preventDefault();
    });
  });
  $all('[data-print]').forEach(function (b) { b.addEventListener('click', function () { window.print(); }); });
  $all('[data-back]').forEach(function (b) { b.addEventListener('click', function () { history.back(); }); });

  // ---------------------------------------------------------------- captcha refresh
  $all('.js-captcha-refresh').forEach(function (b) {
    b.addEventListener('click', function () {
      var img = b.parentNode.querySelector('.captcha-img');
      if (img) img.src = img.src.split('?')[0] + '?r=' + Date.now();
    });
  });

  // ---------------------------------------------------------------- gallery / carousel
  $all('[data-gallery]').forEach(function (g) {
    var slides = $all('.slide', g);
    var thumbs = $all('.thumbs button', g.parentNode);
    var counter = $('.counter', g);
    var i = 0;
    if (slides.length < 2) { $all('.nav-btn', g).forEach(function (b) { b.style.display = 'none'; }); }
    function show(n) {
      i = (n + slides.length) % slides.length;
      slides.forEach(function (s, k) { s.classList.toggle('active', k === i); });
      thumbs.forEach(function (t, k) { t.classList.toggle('active', k === i); });
      if (counter) counter.textContent = (i + 1) + ' / ' + slides.length;
    }
    var prev = $('.nav-btn.prev', g), next = $('.nav-btn.next', g);
    if (prev) prev.addEventListener('click', function () { show(i - 1); });
    if (next) next.addEventListener('click', function () { show(i + 1); });
    thumbs.forEach(function (t, k) { t.addEventListener('click', function () { show(k); }); });
    var sx = null;
    g.addEventListener('touchstart', function (e) { sx = e.touches[0].clientX; }, { passive: true });
    g.addEventListener('touchend', function (e) {
      if (sx === null) return;
      var dx = e.changedTouches[0].clientX - sx;
      if (Math.abs(dx) > 40) show(dx < 0 ? i + 1 : i - 1);
      sx = null;
    });
    g.setAttribute('tabindex', '0');
    g.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowLeft') show(i - 1);
      if (e.key === 'ArrowRight') show(i + 1);
    });
    show(0);
  });

  // ---------------------------------------------------------------- countdown (server-time based)
  // The server sends the number of seconds remaining; we count down using a monotonic clock
  // (performance.now) so the bidder's device clock is irrelevant. We re-sync with the server
  // every 30s to pick up anti-sniping extensions or status changes.
  $all('[data-countdown]').forEach(function (el) {
    var valueEl = $('.cd-value', el);
    var labelEl = $('.cd-label', el);
    var subEl = $('.cd-sub', el);
    var pid = el.getAttribute('data-property-id');
    var mode = el.getAttribute('data-mode');
    var status = el.getAttribute('data-status');
    var target = performance.now() + parseInt(el.getAttribute('data-remaining'), 10) * 1000;
    var reloaded = false;

    function render() {
      var s = Math.max(0, Math.floor((target - performance.now()) / 1000));
      var d = Math.floor(s / 86400), h = Math.floor((s % 86400) / 3600), m = Math.floor((s % 3600) / 60), sec = s % 60;
      valueEl.textContent = (d > 0 ? d + 'd ' : '') + pad(h) + ':' + pad(m) + ':' + pad(sec);
      el.classList.toggle('urgent', mode === 'closing' && s <= 300 && s > 0);
      if (s === 0 && !reloaded) {
        reloaded = true;
        valueEl.textContent = '00:00:00';
        setTimeout(sync, 1500);
      }
    }
    function sync() {
      if (!pid) return;
      fetch(base + '/status.php?id=' + encodeURIComponent(pid), { credentials: 'same-origin', cache: 'no-store' })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          if (!d || !d.ok) return;
          if (d.status !== status) { window.location.reload(); return; }
          var rem = mode === 'opening' ? d.opens_in : d.closes_in;
          target = performance.now() + rem * 1000;
          reloaded = false;
          if (subEl && d.closing_label) subEl.textContent = (mode === 'opening' ? 'Opens ' + d.opening_label : 'Official closing: ' + d.closing_label) + (d.extensions ? ' · extended ' + d.extensions + '×' : '');
          var hb = document.querySelector('[data-live-highest]');
          if (hb && d.highest) hb.textContent = d.highest;
          var bc = document.querySelector('[data-live-count]');
          if (bc && d.bidders !== undefined && d.bidders !== null) bc.textContent = d.bidders;
        })
        .catch(function () {});
    }
    render();
    setInterval(render, 1000);
    setInterval(sync, 30000);
    if (labelEl && mode === 'closing') labelEl.textContent = 'Bidding closes in';
  });

  // ---------------------------------------------------------------- admin server clock
  $all('[data-server-clock]').forEach(function (el) {
    var start = performance.now();
    var serverMs = parseInt(el.getAttribute('data-server-clock'), 10) * 1000;
    var fmt = new Intl.DateTimeFormat('en-PH', { timeZone: 'Asia/Manila', dateStyle: 'medium', timeStyle: 'medium' });
    setInterval(function () { el.textContent = fmt.format(new Date(serverMs + (performance.now() - start))); }, 1000);
  });

  // ---------------------------------------------------------------- GPS consent + bid form
  var bidForm = $('#bid-form');
  if (bidForm) {
    var gpsRequired = bidForm.getAttribute('data-gps-required') === '1';
    var eligible = bidForm.getAttribute('data-eligible') === '1';
    var submitBtn = $('#bid-submit', bidForm);
    var terms = $('#accept-terms', bidForm);
    var amount = $('#amount', bidForm);
    var statusEl = $('#gps-status');
    var f = {
      status: $('[name="gps_status"]', bidForm), lat: $('[name="gps_lat"]', bidForm),
      lng: $('[name="gps_lng"]', bidForm), acc: $('[name="gps_accuracy"]', bidForm)
    };
    function setGps(st, msg, ok) {
      f.status.value = st;
      if (statusEl) { statusEl.textContent = msg; statusEl.className = 'gps-status ' + (ok === true ? 'ok' : ok === 'neutral' ? '' : 'no'); }
      update();
    }
    function update() {
      var ready = eligible && (!terms || terms.checked) && f.status.value !== '' &&
        (!gpsRequired || f.status.value === 'granted') && (!amount || amount.value.trim() !== '');
      submitBtn.disabled = !ready;
    }
    var allow = $('#gps-allow');
    if (allow) {
      allow.addEventListener('click', function () {
        if (!('geolocation' in navigator)) { setGps('unavailable', 'Location is not supported on this device/browser.', false); return; }
        if (statusEl) { statusEl.textContent = 'Requesting permission from your browser…'; statusEl.className = 'gps-status'; }
        navigator.geolocation.getCurrentPosition(function (pos) {
          f.lat.value = pos.coords.latitude.toFixed(7);
          f.lng.value = pos.coords.longitude.toFixed(7);
          f.acc.value = pos.coords.accuracy ? pos.coords.accuracy.toFixed(2) : '';
          setGps('granted', '✔ Location captured (accuracy ±' + Math.round(pos.coords.accuracy || 0) + ' m). It will be recorded with your bid.', true);
        }, function (err) {
          f.lat.value = f.lng.value = f.acc.value = '';
          if (err.code === 1) setGps('denied', '✖ Location permission not granted.' + (gpsRequired ? ' This event requires location to bid.' : ' Your bid will record: "Location permission not granted."'), false);
          else setGps('unavailable', '✖ Location could not be determined (' + (err.message || 'unavailable') + ').', false);
        }, { enableHighAccuracy: true, timeout: 20000, maximumAge: 0 });
      });
    }
    var decline = $('#gps-decline');
    if (decline) {
      decline.addEventListener('click', function () {
        f.lat.value = f.lng.value = f.acc.value = '';
        setGps('denied', gpsRequired ? '✖ Location is required for this bidding event.' : 'You chose not to share location. Your bid will record: "Location permission not granted."', gpsRequired ? false : 'neutral');
      });
    }
    if (terms) terms.addEventListener('change', update);
    if (amount) {
      amount.addEventListener('input', update);
      amount.addEventListener('blur', function () {
        var v = amount.value.replace(/[^0-9.]/g, '');
        if (v !== '' && !isNaN(v)) amount.value = Number(v).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
      });
    }
    var dev = $('[name="device_info"]', bidForm);
    if (dev) {
      dev.value = [navigator.platform || '', (screen.width + 'x' + screen.height), navigator.language || '',
        (Intl.DateTimeFormat().resolvedOptions().timeZone || '')].join(' | ');
    }
    update();
  }

  // ---------------------------------------------------------------- QR codes (vendor/qrcode.js, MIT, bundled locally)
  $all('[data-qr]').forEach(function (el) {
    if (typeof window.qrcode === 'undefined') { el.textContent = el.getAttribute('data-qr'); return; }
    var qr = window.qrcode(0, 'M');
    qr.addData(el.getAttribute('data-qr'), 'Byte');
    qr.make();
    var size = parseInt(el.getAttribute('data-size') || '160', 10);
    var cell = Math.max(2, Math.floor(size / (qr.getModuleCount() + 4)));
    el.innerHTML = qr.createSvgTag(cell, cell * 2);
  });


  // ---------------------------------------------------------------- light / dark mode
  $all('[data-theme-toggle]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var next = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
      document.documentElement.setAttribute('data-theme', next);
      try { localStorage.setItem('cl_theme', next); } catch (e) {}
    });
  });

  // ---------------------------------------------------------------- notification sound (Web Audio, no files)
  var audioCtx = null;
  function soundOn() { try { return localStorage.getItem('cl_sound') !== 'off'; } catch (e) { return true; } }
  function unlockAudio() {
    if (audioCtx || !(window.AudioContext || window.webkitAudioContext)) return;
    try { audioCtx = new (window.AudioContext || window.webkitAudioContext)(); } catch (e) { audioCtx = null; }
  }
  ['pointerdown', 'keydown', 'touchstart'].forEach(function (ev) { window.addEventListener(ev, unlockAudio, { once: true, passive: true }); });
  function chime() {
    if (!soundOn() || !audioCtx) return;
    try {
      if (audioCtx.state === 'suspended') audioCtx.resume();
      var t = audioCtx.currentTime;
      [[880, 0], [1318.5, 0.13]].forEach(function (n) {
        var o = audioCtx.createOscillator(), g = audioCtx.createGain();
        o.type = 'sine'; o.frequency.value = n[0];
        g.gain.setValueAtTime(0.0001, t + n[1]);
        g.gain.exponentialRampToValueAtTime(0.18, t + n[1] + 0.02);
        g.gain.exponentialRampToValueAtTime(0.0001, t + n[1] + 0.55);
        o.connect(g); g.connect(audioCtx.destination);
        o.start(t + n[1]); o.stop(t + n[1] + 0.6);
      });
    } catch (e) {}
  }

  // ---------------------------------------------------------------- toasts
  var toastStack = $('[data-toasts]');
  var bellSvg = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>';
  function toast(title, body, link) {
    if (!toastStack) return;
    var el = document.createElement('div');
    el.className = 'toast';
    el.setAttribute('role', 'status');
    var icon = document.createElement('div'); icon.className = 't-icon'; icon.innerHTML = bellSvg;
    var wrap = document.createElement(link ? 'a' : 'div');
    if (link) wrap.href = link;
    var t = document.createElement('div'); t.className = 't-title'; t.textContent = title;
    var b = document.createElement('div'); b.className = 't-body'; b.textContent = body || '';
    wrap.appendChild(t); wrap.appendChild(b);
    el.appendChild(icon); el.appendChild(wrap);
    toastStack.appendChild(el);
    setTimeout(function () { el.classList.add('leaving'); setTimeout(function () { el.remove(); }, 320); }, 7000);
  }

  // ---------------------------------------------------------------- notification bell (live feed)
  var feedUrl = document.body.getAttribute('data-feed');
  var bellWrap = $('[data-notif]');
  if (feedUrl && bellWrap) {
    var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
    var btn = $('[data-notif-toggle]', bellWrap), panel = $('[data-notif-panel]', bellWrap);
    var list = $('[data-notif-list]', bellWrap), countEl = $('[data-notif-count]', bellWrap);
    var storeKey = 'cl_notif_last:' + feedUrl, stopped = false, timer = null;
    var soundBtn = $('[data-sound-toggle]', bellWrap);

    function setCount(n) {
      if (!countEl) return;
      countEl.textContent = n > 99 ? '99+' : String(n);
      countEl.hidden = !n;
      btn.setAttribute('aria-label', 'Notifications' + (n ? ' (' + n + ' unread)' : ''));
    }
    function render(items) {
      list.innerHTML = '';
      if (!items.length) { var e = document.createElement('div'); e.className = 'notif-empty'; e.textContent = 'You have no notifications yet.'; list.appendChild(e); return; }
      items.forEach(function (it) {
        var a = document.createElement('a');
        a.className = 'notif-item' + (it.read ? '' : ' unread');
        a.href = it.link || document.body.getAttribute('data-notif-page') || '#';
        var t = document.createElement('div'); t.className = 'ni-title'; t.textContent = it.title;
        var b = document.createElement('div'); b.className = 'ni-body'; b.textContent = it.body || '';
        var d = document.createElement('div'); d.className = 'ni-time'; d.textContent = it.time;
        a.appendChild(t); a.appendChild(b); a.appendChild(d);
        a.addEventListener('click', function () {
          if (!it.read) post({ action: 'read', id: it.id }, true);
        });
        list.appendChild(a);
      });
    }
    function handle(d, silent) {
      if (!d || !d.ok) return;
      setCount(d.unread);
      render(d.items || []);
      var last = null;
      try { last = parseInt(localStorage.getItem(storeKey) || '', 10); } catch (e) {}
      if (!isNaN(last) && last !== null && d.latest_id > last && !silent) {
        var fresh = (d.items || []).filter(function (it) { return it.id > last && !it.read; }).reverse();
        fresh.slice(-3).forEach(function (it) { toast(it.title, it.body, it.link); });
        if (fresh.length) {
          chime();
          btn.classList.remove('ringing'); void btn.offsetWidth; btn.classList.add('ringing');
        }
      }
      try { localStorage.setItem(storeKey, String(d.latest_id || 0)); } catch (e) {}
    }
    function load(silent) {
      if (stopped) return;
      fetch(feedUrl, { credentials: 'same-origin', cache: 'no-store', headers: { 'Accept': 'application/json' } })
        .then(function (r) { if (r.status === 401) { stopped = true; return null; } return r.json(); })
        .then(function (d) { handle(d, silent); })
        .catch(function () {});
    }
    function post(data, keepalive) {
      var body = new URLSearchParams(data); body.append('_csrf', csrf);
      return fetch(feedUrl, { method: 'POST', credentials: 'same-origin', body: body, keepalive: !!keepalive, headers: { 'X-CSRF-Token': csrf } })
        .then(function (r) { return r.json(); }).then(function (d) { handle(d, true); }).catch(function () {});
    }
    function schedule() { clearInterval(timer); timer = setInterval(function () { if (!document.hidden) load(false); }, 25000); }

    btn.addEventListener('click', function (e) {
      e.stopPropagation();
      var open = panel.hidden;
      panel.hidden = !open;
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
      if (open) load(true);
    });
    document.addEventListener('click', function (e) { if (!panel.hidden && !bellWrap.contains(e.target)) { panel.hidden = true; btn.setAttribute('aria-expanded', 'false'); } });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !panel.hidden) { panel.hidden = true; btn.setAttribute('aria-expanded', 'false'); btn.focus(); } });
    var readAll = $('[data-notif-readall]', bellWrap);
    if (readAll) readAll.addEventListener('click', function () { post({ action: 'read_all' }); });

    function paintSound() {
      if (!soundBtn) return;
      var on = soundOn();
      soundBtn.setAttribute('aria-pressed', on ? 'true' : 'false');
      $('[data-sound-on]', soundBtn).hidden = !on;
      $('[data-sound-off]', soundBtn).hidden = on;
      $('[data-sound-label]', soundBtn).textContent = on ? 'Sound on' : 'Sound off';
    }
    if (soundBtn) soundBtn.addEventListener('click', function () {
      try { localStorage.setItem('cl_sound', soundOn() ? 'off' : 'on'); } catch (e) {}
      paintSound();
      unlockAudio();
      if (soundOn()) chime();
    });
    paintSound();
    document.addEventListener('visibilitychange', function () { if (!document.hidden) load(false); });
    load(false);
    schedule();
  }

  // ---------------------------------------------------------------- email settings presets (admin)
  $all('[data-mail-preset]').forEach(function (b) {
    b.addEventListener('click', function () {
      var p = JSON.parse(b.getAttribute('data-mail-preset'));
      var f = b.form;
      f.mail_driver.value = p.driver; f.mail_host.value = p.host; f.mail_port.value = p.port; f.mail_encryption.value = p.encryption;
      if (f.mail_username.value === '' ) f.mail_username.focus();
    });
  });

  // ---------------------------------------------------------------- misc
  $all('[data-autosubmit]').forEach(function (el) { el.addEventListener('change', function () { el.form.submit(); }); });
  $all('[data-check-all]').forEach(function (m) {
    m.addEventListener('change', function () {
      $all(m.getAttribute('data-check-all')).forEach(function (c) { c.checked = m.checked; });
    });
  });
  $all('[data-toggle-target]').forEach(function (cb) {
    var t = document.getElementById(cb.getAttribute('data-toggle-target'));
    function sync() { if (t) t.style.display = cb.checked ? '' : 'none'; }
    cb.addEventListener('change', sync); sync();
  });
  $all('[data-reason-when-changed]').forEach(function (input) {
    var orig = input.value;
    var box = document.getElementById(input.getAttribute('data-reason-when-changed'));
    function sync() { if (box) box.style.display = input.value !== orig ? '' : 'none'; }
    input.addEventListener('input', sync); input.addEventListener('change', sync); sync();
  });
})();
