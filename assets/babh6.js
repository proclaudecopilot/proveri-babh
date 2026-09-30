/* Регистър на добавките v6 — публичен фронтенд (vanilla JS, без зависимости) */
(function () {
'use strict';

var CFG = window.BABH6_CFG || { rest: '/wp-json/babh6/v1' };
var root = document.getElementById('babh6-app');
if (!root) return;

var $ = function (s, r) { return (r || root).querySelector(s); };
var $$ = function (s, r) { return Array.prototype.slice.call((r || root).querySelectorAll(s)); };

var state = {
  tab: 'register', q: '', flagged: false, rt: '', recent: false, more: false,
  cat: null, year: null, obl: null, sort: 'rel',
  page: 1, per: 20, items: [], total: 0, loading: false,
  openReg: null, stats: null, deepReg: null, waitOk: {}, statsErr: false,
  producer: null, producerName: '', trader: null, traderName: '', own: false, brand: null, inferred: false,
  /* Списъци с фирми — отделно състояние за производители (p) и търговци (t), защото таблото ги показва едновременно */
  plist: { p: newPList(), t: newPList() },
  /* Отворен профил на фирма */
  party: { kind: 'p', open: null, detail: null }
};
function newPList() { return { q: '', sort: 'products', all: false, page: 1, per: 8, items: [], total: 0, allN: 0, allBg: 0, loading: false, loaded: false }; }
var PRO = String(CFG.pro) === '1';

/* ===== Icons ===== */
var I = {
  search: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>',
  x: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>',
  warn: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.7 16.7-8-13.4a2 2 0 0 0-3.4 0l-8 13.4A2 2 0 0 0 4 20h16a2 2 0 0 0 1.7-3.3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>',
  chev: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>',
  store: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m2 7 4.4-4.4A2 2 0 0 1 7.8 2h8.4a2 2 0 0 1 1.4.6L22 7"/><path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8"/><path d="M2 7h20"/></svg>',
  factory: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 20a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V8l-7 5V8l-7 5V4a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2Z"/></svg>',
  flag: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1Z"/><line x1="4" x2="4" y1="22" y2="15"/></svg>',
  bell: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg>',
  dl: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/></svg>',
  share: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8"/><polyline points="16 6 12 2 8 6"/><line x1="12" x2="12" y1="2" y2="15"/></svg>',
  flask: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 3h6v6l4 8a2 2 0 0 1-2 3H7a2 2 0 0 1-2-3l4-8V3Z"/></svg>',
  grid: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3h18v18H3z"/><path d="M3 9h18M9 21V9"/></svg>',
  layers: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 2 7 12 12 22 7 12 2"/><polyline points="2 17 12 22 22 17"/><polyline points="2 12 12 17 22 12"/></svg>',
  bolt: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M13 2 3 14h9l-1 8 10-12h-9l1-8z"/></svg>',
  menu: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M3 12h18"/><path d="M3 6h18"/><path d="M3 18h18"/></svg>',
  empty: '<svg width="120" height="88" viewBox="0 0 130 96" fill="none"><rect x="18" y="26" width="66" height="46" rx="8" stroke="#CECEC8" stroke-width="2"/><path d="M18 40h66" stroke="#CECEC8" stroke-width="2"/><path d="M30 52h24M30 60h32" stroke="#E4E4E0" stroke-width="2.5" stroke-linecap="round"/><circle cx="92" cy="60" r="16" stroke="#E66A3D" stroke-width="3"/><path d="m103 71 12 12" stroke="#E66A3D" stroke-width="3.5" stroke-linecap="round"/></svg>'
};

var CAT_COLORS = { vitamins: '#F59E0B', minerals: '#10B981', protein: '#EF4444', collagen: '#EC4899', omega: '#06B6D4', digestion: '#84CC16', immune: '#22C55E', herbal: '#65A30D', weight: '#F97316', beauty: '#D946EF', men: '#3B82F6', women: '#A855F7', children: '#14B8A6', sleep: '#6366F1', cardio: '#DC2626', detox: '#0EA5E9', other: '#9AA0AB' };
var CAT_ICONS = {
  vitamins: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m10.5 20.5 10-10a4.95 4.95 0 1 0-7-7l-10 10a4.95 4.95 0 1 0 7 7Z"/><path d="m8.5 8.5 7 7"/></svg>',
  minerals: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 3h12l4 6-10 13L2 9Z"/><path d="M2 9h20"/></svg>',
  protein: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M13 2 3 14h9l-1 8 10-12h-9l1-8z"/></svg>',
  collagen: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><circle cx="19" cy="5" r="2"/><circle cx="5" cy="19" r="2"/><path d="m14.1 9.9 3.5-3.5"/><path d="m6.4 17.6 3.5-3.5"/></svg>',
  omega: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22a7 7 0 0 0 7-7c0-2-1-3.9-3-5.5s-3.5-4-4-6.5c-.5 2.5-2 4.9-4 6.5C6 11.1 5 13 5 15a7 7 0 0 0 7 7z"/></svg>',
  digestion: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 20A7 7 0 0 1 9.8 6.1C15.5 5 17 4.48 19 2c1 2 2 4.18 2 8 0 5.5-4.78 10-10 10Z"/><path d="M2 21c0-3 1.85-5.36 5.08-6C9.5 14.52 12 13 13 12"/></svg>',
  immune: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/></svg>',
  herbal: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 20h10"/><path d="M12 20v-9"/><path d="M12 11a4 4 0 0 1-4-4V4a7.9 7.9 0 0 1 4 1 8 8 0 0 1 4-1v3a4 4 0 0 1-4 4Z"/></svg>',
  weight: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.153.433-2.294 1-3a2.5 2.5 0 0 0 2.5 2.5z"/></svg>',
  beauty: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m12 3-1.9 5.8L4 11l6.1 1.9L12 19l1.9-6.1L20 11l-6.1-2.2L12 3Z"/></svg>',
  men: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="10" cy="14" r="5"/><path d="m19 5-5.4 5.4"/><path d="M14 5h5v5"/></svg>',
  women: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="5"/><path d="M12 13v8"/><path d="M9 18h6"/></svg>',
  children: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M8 14s1.5 2 4 2 4-2 4-2"/><line x1="9" x2="9.01" y1="9" y2="9"/><line x1="15" x2="15.01" y1="9" y2="9"/></svg>',
  sleep: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/></svg>',
  cardio: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/></svg>',
  detox: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M7 12h10"/><path d="M10 18h4"/></svg>',
  other: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m7.5 4.27 9 5.15"/><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/></svg>'
};

/* ===== Utils ===== */
function esc(s) { if (s == null) return ''; return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
function fmtDate(s) { if (!s) return ''; var m = String(s).match(/^(\d{4})-(\d{2})-(\d{2})/); return m ? m[3] + '.' + m[2] + '.' + m[1] : s; }
function nfmt(n) { return (n == null ? 0 : n).toLocaleString('bg'); }
function plural(n, a, b) { return n === 1 ? a : b; }
function firstPart(s) { return (s || '').split(',')[0].trim(); }
function restPart(s) { var p = (s || '').split(','); return p.length > 1 ? p.slice(1).join(',').trim() : ''; }
function catLabel(code, fallback) {
  if (state.stats) {
    var f = state.stats.cats.filter(function (c) { return c.code === code; });
    if (f.length) return f[0].label;
  }
  return fallback || 'Категорията не е определена';
}
function monthLabel(m) { var names = ['яну', 'фев', 'мар', 'апр', 'май', 'юни', 'юли', 'авг', 'сеп', 'окт', 'ное', 'дек']; return names[parseInt(m.slice(5), 10) - 1] + ' ' + m.slice(0, 4); }
function pluralN(n, one, many) { return nfmt(n) + ' ' + (n === 1 ? one : many); }
function api(path, params) {
  var qs = '';
  if (params) {
    var parts = [];
    Object.keys(params).forEach(function (k) {
      var v = params[k];
      if (v === null || v === undefined || v === '' || v === false) return;
      parts.push(encodeURIComponent(k) + '=' + encodeURIComponent(v === true ? 1 : v));
    });
    if (parts.length) qs = '?' + parts.join('&');
  }
  var opts = { credentials: 'same-origin', headers: CFG.nonce ? { 'X-WP-Nonce': CFG.nonce } : {} };
  return fetch(restUrl(path, qs), opts).then(function (r) {
    if (r.status === 401) { location.reload(); throw new Error('locked'); }
    if (r.status === 404 && CFG.rest2 && !CFG._alt) {
      /* /wp-json/ пътят е блокиран или пренаписването не работи → резервен ?rest_route= */
      CFG._alt = true;
      try { sessionStorage.setItem('babh6_rest_alt', '1'); } catch (e) {}
      return fetch(restUrl(path, qs), opts).then(function (r2) {
        if (!r2.ok) throw new Error('HTTP ' + r2.status + ' (и през ?rest_route=)');
        return r2.json();
      });
    }
    if (!r.ok) throw new Error('HTTP ' + r.status);
    return r.json();
  });
}
function restUrl(path, qs) {
  var base = (CFG._alt && CFG.rest2) ? CFG.rest2 : CFG.rest;
  if (base.indexOf('?') !== -1) return base + path + (qs ? '&' + qs.slice(1) : '');
  return base + path + qs;
}
try { if (sessionStorage.getItem('babh6_rest_alt') === '1' && CFG.rest2) CFG._alt = true; } catch (e) {}
function currentParams() {
  return {
    q: state.q, flagged: state.flagged, rt: state.rt, recent: state.recent,
    cat: state.cat, year: state.year, obl: state.obl, sort: state.sort,
    producer: state.producer, trader: state.trader, own: state.own, brand: state.brand, inferred: state.inferred
  };
}
/* Отваря регистъра, филтриран по фирма (от Pro профилите) */
function gotoProducts(f) {
  state.producer = f.producer || null; state.producerName = f.producerName || '';
  state.trader = f.trader || null; state.traderName = f.traderName || '';
  state.own = !!f.own; state.brand = f.brand || null; state.inferred = !!f.inferred;
  state.q = ''; state.page = 1; state.cat = null; state.year = null; state.obl = null; state.flagged = false; state.rt = ''; state.recent = false;
  setTab('register');
}
function partyLink(kind, norm, name) {
  if (!PRO || !norm) return esc(name);
  return '<a href="#" class="b6-plink" data-party="' + esc(kind) + '|' + esc(norm) + '" title="Профил на фирмата">' + esc(name) + '</a>';
}

/* ===== Composition parsing ===== */
function parseComp(raw) {
  if (!raw) return [];
  return raw.split(/;/g).map(function (s) { return s.trim(); }).filter(Boolean).map(function (p) {
    var m = p.match(/^(.+?)\s+([\d,\.]+)\s*(мг|мкг|µg|г|gr|g|iu|ме|млрд|млн|cfu|%)\.?$/i);
    if (m) return { name: m[1].replace(/[\-–—\s]+$/, '').trim(), amount: m[2].replace(',', '.'), unit: m[3].toLowerCase() };
    return { name: p, amount: '', unit: '' };
  });
}
/* Съставка с автоматична бележка: единственият източник са бележките от сървъра (един речник). */
function classifyIng(n, flags) {
  var nl = n.toLowerCase();
  for (var i = 0; i < (flags || []).length; i++) {
    if (flags[i].term && nl.indexOf(flags[i].term) !== -1) return 'warn';
  }
  return '';
}

/* ===== Password gate ===== */
function renderGate() {
  root.innerHTML =
    '<div class="b6-gate"><div class="b6-gate-card">' +
      '<div class="b6-mark" style="margin:0 auto 14px"></div>' +
      '<div class="b6-gate-t">Регистър на добавките</div>' +
      '<div class="b6-gate-s">Достъпът е ограничен. Въведи парола.</div>' +
      '<input type="password" id="b6-gate-pw" placeholder="Парола…" autocomplete="current-password">' +
      '<button id="b6-gate-go">Влез</button>' +
      '<div class="b6-gate-err" id="b6-gate-err"></div>' +
    '</div></div>';
  var inp = $('#b6-gate-pw'), go = $('#b6-gate-go'), err = $('#b6-gate-err');
  function submit() {
    var pw = inp.value;
    if (!pw) { inp.focus(); return; }
    go.disabled = true; go.textContent = 'Проверка…'; err.textContent = '';
    fetch(restUrl('/auth', ''), {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ password: pw })
    }).then(function (r) { return r.json().then(function (d) { return { ok: r.ok, d: d }; }); })
      .then(function (x) {
        if (x.ok) { location.reload(); return; }
        go.disabled = false; go.textContent = 'Влез';
        err.textContent = (x.d && x.d.message) ? x.d.message : 'Грешна парола.';
      }).catch(function () { go.disabled = false; go.textContent = 'Влез'; err.textContent = 'Грешка при връзка.'; });
  }
  go.addEventListener('click', submit);
  inp.addEventListener('keydown', function (e) { if (e.key === 'Enter') submit(); });
  setTimeout(function () { inp.focus(); }, 100);
}

/* ===== Shell ===== */
function renderShell() {
  root.innerHTML =
    '<div class="b6-layout">' +
      '<aside class="b6-sb" id="b6-sb">' +
        '<div class="b6-brand"><div class="b6-mark"></div><div><div class="b6-brand-n">Регистър на добавките</div><div class="b6-brand-t">Данни от БАБХ</div></div></div>' +
        '<div class="b6-sb-l">Меню</div>' +
        '<button class="b6-item on" data-tab="register">' + I.search + '<span class="lbl">Регистър</span><span class="count" id="b6-c-total">—</span></button>' +
        '<button class="b6-item alert" data-tab="flagged">' + I.warn + '<span class="lbl">За проверка</span><span class="count" id="b6-c-flag" aria-label="Данните се зареждат">—</span></button>' +
        '<button class="b6-item" data-tab="producers">' + I.factory + '<span class="lbl">Производители</span><span class="pro">PRO</span></button>' +
        '<button class="b6-item" data-tab="traders">' + I.store + '<span class="lbl">Търговци</span><span class="pro">PRO</span></button>' +
        '<div class="b6-sb-l">Pro инструменти</div>' +
        '<button class="b6-item" data-tab="novel">' + I.flask + '<span class="lbl">Проверка на съставки</span>' + (CFG.hasAI == 1 ? '<span class="pro">PRO</span>' : '<span class="pro soon">В подготовка</span>') + '</button>' +
        '<button class="b6-item" data-tab="inspector">' + I.grid + '<span class="lbl">Промени в регистъра</span><span class="pro soon">В подготовка</span></button>' +
        '<button class="b6-item" data-tab="watchlist">' + I.bell + '<span class="lbl">Известия</span><span class="pro soon">В подготовка</span></button>' +
        '<div class="b6-sb-foot">Данните са от регистъра на БАБХ. Сайт за справки по данни от регистъра на БАБХ.</div>' +
      '</aside>' +
      '<div class="b6-main">' +
        '<div class="b6-top">' +
          '<button class="b6-burger" id="b6-burger" aria-label="Отвори менюто" aria-expanded="false">' + I.menu + '</button>' +
          '<div class="b6-topq">' + I.search + '<input id="b6-topq" type="text" placeholder="Търси продукт, фирма или съставка" aria-label="Търсене в регистъра" autocomplete="off"></div>' +
          '<div class="b6-live"><span class="b6-live-dot"></span><span id="b6-live-t" aria-live="polite">Зареждане…</span></div>' +
        '</div>' +
        '<div class="b6-content" id="b6-content"></div>' +
      '</div>' +
    '</div>' +
    '<div class="b6-scrim" id="b6-scrim"></div>' +
    '<nav class="b6-bnav">' +
      '<button data-tab="register" class="on">' + I.search + '<span>Регистър</span></button>' +
      '<button data-tab="flagged">' + I.warn + '<span>За проверка</span></button>' +
      '<button data-tab="producers">' + I.factory + '<span>Производители</span></button>' +
      '<button data-btn="menu">' + I.menu + '<span>Меню</span></button>' +
    '</nav>';

  $$('.b6-item').forEach(function (b) { b.addEventListener('click', function () { setTab(b.getAttribute('data-tab')); }); });
  $$('.b6-bnav button').forEach(function (b) {
    b.addEventListener('click', function () {
      if (b.getAttribute('data-btn') === 'menu') { $('#b6-sb').classList.add('open'); $('#b6-scrim').classList.add('show'); return; }
      setTab(b.getAttribute('data-tab'));
    });
  });
  $('#b6-burger').addEventListener('click', function () { $('#b6-sb').classList.add('open'); $('#b6-scrim').classList.add('show'); this.setAttribute('aria-expanded', 'true'); });
  $('#b6-scrim').addEventListener('click', closeSidebar);
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeSidebar(); });

  var tqT = null;
  $('#b6-topq').addEventListener('input', function (e) {
    clearTimeout(tqT);
    var v = e.target.value;
    tqT = setTimeout(function () {
      state.q = v; state.page = 1;
      var qi = $('#b6-q'); if (qi && qi.value !== v) { qi.value = v; var cl = $('#b6-qclr'); if (cl) cl.classList.toggle('show', !!v); }
      if (state.tab !== 'register' && state.tab !== 'flagged') setTab('register'); else { loadProducts(false); }
    }, 350);
  });
}
function closeSidebar() { $('#b6-sb').classList.remove('open'); $('#b6-scrim').classList.remove('show'); var bg = $('#b6-burger'); if (bg) bg.setAttribute('aria-expanded', 'false'); }

function setTab(t) {
  state.tab = t; state.page = 1; state.openReg = null;
  state.flagged = (t === 'flagged');
  closeSidebar();
  $$('.b6-item').forEach(function (b) { b.classList.toggle('on', b.getAttribute('data-tab') === t); });
  $$('.b6-bnav button[data-tab]').forEach(function (b) { b.classList.toggle('on', b.getAttribute('data-tab') === t); });
  if (t === 'producers' || t === 'traders') { state.party.open = null; state.party.detail = null; state.party.kind = t === 'producers' ? 'p' : 't'; }
  render();
  if (t === 'register' || t === 'flagged') loadProducts(false);
  window.scrollTo({ top: root.offsetTop - 20, behavior: 'smooth' });
}

/* ===== Data ===== */
function loadStats() {
  return api('/stats').then(function (s) {
    state.stats = s;
    var ct = $('#b6-c-total'), cf = $('#b6-c-flag'), lv = $('#b6-live-t');
    if (ct) ct.textContent = nfmt(s.total);
    if (cf) cf.textContent = nfmt(s.flagged);
    if (lv) lv.textContent = s.last_update ? 'Данни, качени на ' + s.last_update : 'Още няма качен регистър.';
    state.statsErr = false;
  }).catch(function (e) {
    state.statsErr = true;
    var lv = $('#b6-live-t'); if (lv) lv.textContent = 'Статистиката временно не е достъпна.';
    throw e;
  });
}
function loadProducts(append) {
  var c0 = $('#b6-list');
  if (state.q && state.q.trim().length === 1) {
    state.loading = false;
    if (c0) c0.innerHTML = '<div class="b6-empty"><div class="b6-empty-t">Въведи поне 2 знака.</div><div>Търсенето започва от втория знак.</div></div>';
    return Promise.resolve();
  }
  state.loading = true;
  if (!append) renderList(true);
  var p = currentParams();
  p.page = state.page; p.per = state.per;
  return api('/products', p).then(function (r) {
    state.total = r.total;
    state.items = append ? state.items.concat(r.items) : r.items;
    state.loading = false;
    renderList(false);
  }).catch(function (e) {
    state.loading = false;
    var c = $('#b6-list');
    if (!c) return;
    if (append) {
      state.page--;
      var more = $('#b6-more');
      if (more) { more.disabled = false; more.textContent = 'Покажи още'; }
      var err = document.createElement('div'); err.className = 'b6-load-err'; err.setAttribute('role', 'alert');
      err.innerHTML = 'Не успяхме да заредим следващите резултати. <button type="button" class="b6-retry" id="b6-retry-more">Опитай отново</button>';
      var old = $('.b6-load-err'); if (old) old.remove();
      c.appendChild(err);
      $('#b6-retry-more').addEventListener('click', function () { err.remove(); state.page++; loadProducts(true); });
      return;
    }
    c.innerHTML = '<div class="b6-empty" role="alert">' + I.empty + '<div class="b6-empty-t">Не успяхме да заредим резултатите.</div><div>Опитай отново. Ако проблемът продължи, презареди страницата.</div>' +
      '<div style="margin-top:12px"><button type="button" class="b6-retry" id="b6-retry">Опитай отново</button></div>' +
      '<details class="b6-errdet"><summary>Технически подробности</summary><code>' + esc(e.message) + '</code></details></div>';
    $('#b6-retry').addEventListener('click', function () { loadProducts(false); });
  });
}

/* ===== Views ===== */
function render() {
  var c = $('#b6-content');
  c.classList.toggle('flag-mode', state.tab === 'flagged');
  switch (state.tab) {
    case 'register':
    case 'flagged': renderRegister(c); break;
    case 'producers': if (PRO) { state.party.kind = 'p'; renderParties(c, 'p'); } else { renderSoon(c, 'Производители', 'Тук можеш да разглеждаш производителите, фирмите, за които произвеждат, и продуктите, в които са посочени. Разделът е достъпен за потребители с Pro достъп.', 'producers', true); } break;
    case 'traders': if (PRO) { state.party.kind = 't'; renderParties(c, 't'); } else { renderSoon(c, 'Търговци', 'Тук можеш да разглеждаш търговците, производителите зад тях и продуктите, в които са посочени. Разделът е достъпен за потребители с Pro достъп.', 'traders', true); } break;
    case 'novel': if (CFG.hasAI == 1) { renderNovel(c); } else { renderSoon(c, 'Проверка на съставки', 'Подготвяме справка за съставки с препратки към използваните източници. Функцията още не е достъпна.', 'novel', false); } break;
    case 'inspector': renderSoon(c, 'Промени в регистъра', 'Тук ще можеш да сравняваш качени версии на регистъра и да виждаш добавени, променени и липсващи записи. Функцията още не е достъпна.', 'inspector', false); break;
    case 'watchlist': renderSoon(c, 'Известия за промени', 'Подготвяме известия по имейл за нови записи, свързани с избрани фирми или съставки. Функцията още не е достъпна.', 'watchlist', false); break;
  }
}

function bentoHTML(s) {
  if (!s) return '';
  var max = 1, i;
  for (i = 0; i < s.monthly.length; i++) if (s.monthly[i].c > max) max = s.monthly[i].c;
  var names = ['яну', 'фев', 'мар', 'апр', 'май', 'юни', 'юли', 'авг', 'сеп', 'окт', 'ное', 'дек'];
  var bars = '', labels = '', srText = [];
  for (i = 0; i < s.monthly.length; i++) {
    var mo = s.monthly[i];
    var lbl = names[parseInt(mo.m.slice(5), 10) - 1];
    var full = monthLabel(mo.m) + ': ' + pluralN(mo.c, 'уведомление', 'уведомления');
    srText.push(full);
    bars += '<div class="b6-bar' + (i === s.monthly.length - 1 ? ' now' : '') + '" style="height:' + Math.max(7, Math.round(mo.c / max * 100)) + '%" title="' + esc(full) + '"></div>';
    labels += '<span>' + lbl + '</span>';
  }
  var period = s.monthly.length ? monthLabel(s.monthly[0].m) + ' – ' + monthLabel(s.monthly[s.monthly.length - 1].m) : '';
  var thisM = s.monthly[s.monthly.length - 1].c;
  var pOther = (s.producers || 0) - (s.producers_bg || 0), tOther = (s.traders || 0) - (s.traders_bg || 0);
  return '<div class="b6-bento">' +
      '<div class="b6-bm"><div class="b6-bm-l">' + I.layers + 'Продукти в наличните данни</div>' +
        '<div class="b6-bm-n">' + nfmt(s.total) + '</div>' +
        '<div class="b6-bm-d">' + pluralN(thisM, 'уведомление', 'уведомления') + ' този месец · ' + nfmt(s.recent12) + ' за ' + esc(period) + '</div>' +
        '<div class="b6-chart" role="img" aria-label="Уведомления по месеци: ' + esc(srText.join('; ')) + '">' + bars + '</div><div class="b6-chart-x">' + labels + '</div>' +
      '</div>' +
      '<div class="b6-side">' +
        '<div class="b6-bc tint link" data-scroll="b6-s-products" data-flag="1" role="button" tabindex="0"><div class="b6-bc-l">' + I.warn + 'Продукти за проверка</div><div class="b6-bc-n">' + nfmt(s.flagged) + '</div><div class="b6-bc-s">с автоматична бележка</div></div>' +
        '<div class="b6-bc link" data-scroll="b6-s-producers" role="button" tabindex="0"><div class="b6-bc-l">' + I.factory + 'Производители от България</div><div class="b6-bc-n">' + nfmt(s.producers_bg) + '</div><div class="b6-bc-s">' + (pOther > 0 ? 'още ' + nfmt(pOther) + ' чуждестранни · ' : '') + 'по име и адрес в регистъра</div></div>' +
      '</div>' +
      '<div class="b6-side">' +
        '<div class="b6-bc link" data-scroll="b6-s-traders" role="button" tabindex="0"><div class="b6-bc-l">' + I.store + 'Търговци от България</div><div class="b6-bc-n">' + nfmt(s.traders_bg) + '</div><div class="b6-bc-s">' + (tOther > 0 ? 'още ' + nfmt(tOther) + ' чуждестранни · ' : '') + 'по име и адрес в регистъра</div></div>' +
        '<div class="b6-bc"><div class="b6-bc-l">' + I.bolt + 'Уведомления за периода</div><div class="b6-bc-n">' + nfmt(s.recent12) + '</div><div class="b6-bc-s">' + esc(period) + '</div></div>' +
      '</div>' +
    '</div>';
}

/* Има ли активен филтър от панела „Още филтри“ */
function advancedActive() { return !!(state.recent || state.year || state.obl || state.rt); }

function renderRegister(c) {
  var s = state.stats;
  var isFlag = state.tab === 'flagged';
  var years = s ? s.years : [];
  var obls = s ? s.oblasti : [];
  var cats = s ? s.cats.filter(function (x) { return x.count > 0; }) : [];
  var showMore = state.more || advancedActive();

  c.innerHTML =
    '<div class="b6-head"><div>' +
      '<div class="b6-title">' + (isFlag ? 'Продукти за проверка' : 'Регистър на хранителните добавки') + '</div>' +
      '<div class="b6-sub">' + (s && s.last_update ? 'Данни, качени на <b>' + esc(s.last_update) + '</b>. ' : '') +
        (isFlag ? 'Бележките се създават автоматично по думи в наличните данни. Те не са становище на БАБХ за конкретния продукт.' : 'Продукти, производители и търговци на едно място. Отвори продукт, за да видиш наличните данни, състава и бележките за проверка.') + '</div>' +
    '</div><div class="b6-actions"><button class="b6-btn" id="b6-export">' + I.dl + '<span>Изтегли CSV</span></button></div></div>' +
    (isFlag ? '' : bentoHTML(s)) +

    /* ---- 1. Продукти ---- */
    '<section class="b6-section" id="b6-s-products">' +
      (isFlag ? '' : '<div class="b6-sech"><div><div class="b6-sech-t">' + I.layers + 'Продукти</div><div class="b6-sech-s">Търси по наименование, съставка, фирма или регистрационен номер. Кирилица и латиница се търсят взаимозаменяемо.</div></div></div>') +
      '<div class="b6-fbar">' +
        '<div class="b6-fq">' + I.search + '<input id="b6-q" type="text" placeholder="Име на продукт, съставка, фирма или рег. №" aria-label="Търсене в регистъра" value="' + esc(state.q) + '" autocomplete="off"><button class="clr' + (state.q ? ' show' : '') + '" id="b6-qclr" aria-label="Изчисти търсенето">' + I.x + '</button></div>' +
        '<button class="b6-chip' + (state.flagged ? ' on danger' : '') + '" data-f="flagged" aria-pressed="' + (state.flagged ? 'true' : 'false') + '" title="Само продукти с автоматична бележка за проверка">' + I.warn + 'За проверка' + (s ? '<span class="c">' + nfmt(s.flagged) + '</span>' : '') + '</button>' +
        (state.producer ? '<button class="b6-chip on firm" data-clear="producer" title="Премахни филтъра">' + I.factory + esc(state.producerName || 'Производител') + (state.own ? ' · собствени' : '') + I.x + '</button>' : '') +
        (state.trader ? '<button class="b6-chip on firm" data-clear="trader" title="Премахни филтъра">' + I.store + esc(state.traderName || 'Търговец') + (state.own && !state.producer ? ' · собствени' : '') + I.x + '</button>' : '') +
        (state.brand ? '<button class="b6-chip on firm" data-clear="brand" title="Премахни филтъра">' + I.layers + 'Марка: ' + esc(state.brand) + I.x + '</button>' : '') +
        (state.inferred ? '<button class="b6-chip on firm" data-clear="inferred" title="Премахни филтъра">' + I.store + 'Търговец по името' + I.x + '</button>' : '') +
        '<select class="b6-sel" id="b6-sort" aria-label="Подреждане">' +
          '<option value="rel"' + (state.sort === 'rel' ? ' selected' : '') + '>По съвпадение</option>' +
          '<option value="new"' + (state.sort === 'new' ? ' selected' : '') + '>По рег. №: най-нови</option>' +
          '<option value="date"' + (state.sort === 'date' ? ' selected' : '') + '>По дата на уведомление</option>' +
          '<option value="old"' + (state.sort === 'old' ? ' selected' : '') + '>По рег. №: най-стари</option>' +
          '<option value="name"' + (state.sort === 'name' ? ' selected' : '') + '>По име: А–Я</option>' +
          '<option value="flagged"' + (state.sort === 'flagged' ? ' selected' : '') + '>С най-много бележки</option>' +
        '</select>' +
        '<button class="b6-chip' + (showMore ? ' on' : '') + '" id="b6-fmore" aria-expanded="' + (showMore ? 'true' : 'false') + '" aria-controls="b6-fmore-panel">Още филтри' + (advancedActive() ? '<span class="c">•</span>' : '') + I.chev + '</button>' +
      '</div>' +
      '<div class="b6-fmore" id="b6-fmore-panel"' + (showMore ? '' : ' hidden') + '>' +
        '<span class="b6-fmore-l">Период</span>' +
        '<button class="b6-chip' + (state.recent ? ' on' : '') + '" data-f="recent" aria-pressed="' + (state.recent ? 'true' : 'false') + '" title="Дата на уведомление през последните 12 месеца">Последните 12 месеца</button>' +
        '<select class="b6-sel" id="b6-year" aria-label="Година в регистрационния номер"><option value="">Всички години (по рег. №)</option>' + years.map(function (y) { return '<option value="' + y + '"' + (state.year === y ? ' selected' : '') + '>' + y + '</option>'; }).join('') + '</select>' +
        '<span class="b6-fmore-l">Рег. №</span>' +
        '<select class="b6-sel" id="b6-obl" aria-label="Област според регистрационния номер"><option value="">Всички области (по рег. №)</option>' + obls.map(function (o) { return '<option value="' + esc(o) + '"' + (state.obl === o ? ' selected' : '') + '>' + esc(o) + '</option>'; }).join('') + '</select>' +
        '<select class="b6-sel" id="b6-rt" aria-label="Първа буква на регистрационния номер"><option value="">Всички рег. номера</option><option value="П"' + (state.rt === 'П' ? ' selected' : '') + '>Само с „П“</option><option value="Т"' + (state.rt === 'Т' ? ' selected' : '') + '>Само с „Т“</option></select>' +
        (advancedActive() ? '<button class="b6-chip" id="b6-fclear">' + I.x + 'Изчисти</button>' : '') +
      '</div>' +
      '<div class="b6-cats">' + cats.map(function (ct) {
        return '<button class="b6-cat' + (state.cat === ct.code ? ' on' : '') + '" data-cat="' + esc(ct.code) + '"><span class="dot" style="background:' + (CAT_COLORS[ct.code] || '#999') + '"></span>' + esc(ct.label) + '<span class="c">' + nfmt(ct.count) + '</span></button>';
      }).join('') + '</div>' +
      '<div class="b6-legend">Категориите са определени автоматично по наименованието и състава. Бележките за проверка са автоматични съвпадения по думи в наличните данни, не становище на БАБХ.' + (s && s.inferred ? ' При ' + nfmt(s.inferred) + ' продукта търговецът е определен по името (регистърът не го посочва) и е отбелязан „по името“.' : '') + '</div>' +
      '<div id="b6-list"></div>' +
    '</section>' +

    /* ---- 2. Производители, 3. Търговци ---- */
    (isFlag ? '' : partySectionHTML('p') + partySectionHTML('t'));

  var qi = $('#b6-q'), qT = null;
  qi.addEventListener('input', function (e) {
    clearTimeout(qT);
    var v = e.target.value;
    $('#b6-qclr').classList.toggle('show', !!v);
    var tq = $('#b6-topq'); if (tq && tq.value !== v) tq.value = v;
    qT = setTimeout(function () { state.q = v; state.page = 1; loadProducts(false); }, 350);
  });
  $('#b6-qclr').addEventListener('click', function () { state.q = ''; state.page = 1; qi.value = ''; this.classList.remove('show'); var tq = $('#b6-topq'); if (tq) tq.value = ''; loadProducts(false); });
  $$('.b6-chip[data-f]').forEach(function (b) {
    b.addEventListener('click', function () {
      var f = b.getAttribute('data-f');
      state[f] = !state[f];
      state.page = 1;
      if (f === 'flagged') { setTab(state.flagged ? 'flagged' : 'register'); return; }
      b.classList.toggle('on'); b.setAttribute('aria-pressed', state[f] ? 'true' : 'false');
      loadProducts(false);
    });
  });
  $$('.b6-chip[data-clear]').forEach(function (b) {
    b.addEventListener('click', function () {
      var f = b.getAttribute('data-clear');
      if (f === 'inferred') state.inferred = false; else { state[f] = null; if (f !== 'brand') state[f + 'Name'] = ''; }
      if (!state.producer && !state.trader) { state.own = false; state.inferred = false; }
      state.page = 1; render(); loadProducts(false);
    });
  });
  $('#b6-fmore').addEventListener('click', function () {
    state.more = !state.more;
    var panel = $('#b6-fmore-panel'), on = state.more || advancedActive();
    if (on) panel.removeAttribute('hidden'); else panel.setAttribute('hidden', '');
    this.classList.toggle('on', on); this.setAttribute('aria-expanded', on ? 'true' : 'false');
  });
  var fclear = $('#b6-fclear');
  if (fclear) fclear.addEventListener('click', function () { state.recent = false; state.year = null; state.obl = null; state.rt = ''; state.page = 1; render(); loadProducts(false); });
  $('#b6-year').addEventListener('change', function (e) { state.year = e.target.value ? parseInt(e.target.value, 10) : null; state.page = 1; loadProducts(false); });
  $('#b6-obl').addEventListener('change', function (e) { state.obl = e.target.value || null; state.page = 1; loadProducts(false); });
  $('#b6-rt').addEventListener('change', function (e) { state.rt = e.target.value || ''; state.page = 1; loadProducts(false); });
  $('#b6-sort').addEventListener('change', function (e) { state.sort = e.target.value; state.page = 1; loadProducts(false); });
  $$('.b6-cat[data-cat]').forEach(function (b) {
    b.addEventListener('click', function () {
      var cd = b.getAttribute('data-cat');
      state.cat = state.cat === cd ? null : cd;
      state.page = 1;
      $$('.b6-cat').forEach(function (x) { x.classList.toggle('on', x.getAttribute('data-cat') === state.cat); });
      loadProducts(false);
    });
  });
  $('#b6-export').addEventListener('click', function () {
    if (state.total > 5000 && !confirm('Ще се изтеглят първите 5000 резултата според избраното подреждане. Стесни търсенето, за да включиш всички нужни записи. Да продължим ли?')) return;
    var p = currentParams(); p.page = null; p.per = null;
    var parts = [];
    Object.keys(p).forEach(function (k) {
      var v = p[k];
      if (v === null || v === undefined || v === '' || v === false) return;
      parts.push(encodeURIComponent(k) + '=' + encodeURIComponent(v === true ? 1 : v));
    });
    window.location.href = restUrl('/export', parts.length ? '?' + parts.join('&') : '');
  });
  $$('[data-scroll]', c).forEach(function (el) {
    var go = function () {
      if (el.getAttribute('data-flag')) { setTab('flagged'); return; }
      var target = $('#' + el.getAttribute('data-scroll'));
      if (target) target.scrollIntoView({ behavior: 'smooth', block: 'start' });
    };
    el.addEventListener('click', go);
    el.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); go(); } });
  });

  renderList(true);
  if (!isFlag && PRO) { bindPartyToolbar('p'); bindPartyToolbar('t'); ensureParties('p'); ensureParties('t'); }
}

/* Секция „Производители“ / „Търговци“ в таблото (компактен списък; пълният е в отделния раздел) */
function partySectionHTML(kind) {
  var isP = kind === 'p', tab = isP ? 'producers' : 'traders';
  var title = isP ? 'Производители' : 'Търговци';
  var sub = isP
    ? 'Кой за кого произвежда: клиентите на всеки производител и колко продукта има при всеки. По подразбиране са показани само дружества с българска регистрация и седалище.'
    : 'Кой доставя на кого: производителите зад всеки търговец и с колко продукта. По подразбиране са показани само дружества с българска регистрация и седалище.';
  var body;
  if (PRO) {
    body = partyToolbarHTML(kind) + '<div id="b6-plist-' + kind + '"></div>';
  } else {
    body = '<div class="b6-lock"><div>' + (isP ? 'Списъкът на производителите, клиентите им и продуктите при всеки клиент' : 'Списъкът на търговците, доставчиците им и продуктите при всеки') + ' е достъпен за потребители с Pro достъп.</div>' +
      '<button class="b6-btn" data-goto-pro="' + tab + '">' + I.bell + '<span>Уведоми ме за достъпа</span></button></div>';
  }
  return '<section class="b6-section" id="b6-s-' + tab + '">' +
    '<div class="b6-sech"><div><div class="b6-sech-t">' + (isP ? I.factory : I.store) + title + '<span class="b6-pro-badge">PRO</span></div><div class="b6-sech-s">' + sub + '</div></div>' +
      (PRO ? '<div class="b6-actions"><button class="b6-btn" data-goto-pro="' + tab + '">' + I.grid + '<span>Пълен списък</span></button></div>' : '') +
    '</div>' + body + '</section>';
}

function renderList(skeleton) {
  var c = $('#b6-list');
  if (!c) return;
  if (skeleton && state.loading !== false && !state.items.length) {
    var sk = '';
    for (var i = 0; i < 6; i++) sk += '<div class="b6-sk"><div class="b6-sk-line" style="width:' + (28 + i * 7) % 60 + '%"></div><div class="b6-sk-line" style="width:80%;margin-top:10px"></div></div>';
    c.innerHTML = sk;
    return;
  }
  if (!state.items.length) {
    var noData = state.stats && state.stats.total === 0;
    c.innerHTML = noData
      ? '<div class="b6-empty">' + I.empty + '<div class="b6-empty-t">Все още няма качени данни за справка.</div></div>'
      : '<div class="b6-empty">' + I.empty + '<div class="b6-empty-t">Няма резултати по тези критерии.</div><div>Провери изписването или промени търсенето и филтрите.</div></div>';
    return;
  }
  var deepMiss = state.deepReg && !state.items.some(function (p) { return p.reg === state.deepReg; });
  var html = (deepMiss ? '<div class="b6-load-err" role="alert">Не е намерен запис с този регистрационен номер. Потърси продукта по име или фирма.</div>' : '') +
    '<div class="b6-meta"><span><b>' + nfmt(state.total) + '</b> ' + plural(state.total, 'резултат', 'резултата') + (state.q ? ' за „' + esc(state.q) + '“' : '') + '</span><span>Показани: ' + nfmt(state.items.length) + ' от ' + nfmt(state.total) + '</span></div><div class="b6-cards">';
  state.items.forEach(function (p) { html += cardHTML(p); });
  html += '</div>';
  if (state.items.length < state.total) {
    var left = state.total - state.items.length;
    html += '<div class="b6-more-wrap"><button class="b6-more" id="b6-more">Покажи още ' + Math.min(state.per, left) + '</button><div class="b6-left">' + (left === 1 ? 'Остава 1 резултат' : 'Остават ' + nfmt(left) + ' резултата') + '</div></div>';
  }
  c.innerHTML = html;

  $$('.b6-card').forEach(function (card) {
    card.addEventListener('keydown', function (e) { if ((e.key === 'Enter' || e.key === ' ') && e.target === card) { e.preventDefault(); card.click(); } });
    card.addEventListener('click', function (e) {
      if (e.target.closest('.b6-act') || e.target.closest('.b6-ing') || e.target.closest('.b6-orig') || e.target.closest('.b6-plink')) return;
      var reg = card.getAttribute('data-reg');
      state.openReg = state.openReg === reg ? null : reg;
      renderList(false);
      if (state.openReg === reg) {
        var el = $('.b6-card[data-reg="' + CSS.escape(reg) + '"]');
        if (el) setTimeout(function () { el.scrollIntoView({ behavior: 'smooth', block: 'nearest' }); }, 60);
      }
    });
  });
  var more = $('#b6-more');
  if (more) more.addEventListener('click', function () { more.disabled = true; more.textContent = 'Зареждане…'; state.page++; loadProducts(true); });
  $$('.b6-ing[data-ing]').forEach(function (el) {
    el.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); el.click(); } });
    el.addEventListener('click', function (e) {
      e.stopPropagation();
      state.q = el.getAttribute('data-ing');
      state.page = 1; state.openReg = null;
      var qi = $('#b6-q'); if (qi) { qi.value = state.q; $('#b6-qclr').classList.add('show'); }
      loadProducts(false);
      window.scrollTo({ top: root.offsetTop - 20, behavior: 'smooth' });
    });
  });
  $$('[data-share]').forEach(function (b) {
    b.addEventListener('click', function (e) {
      e.stopPropagation();
      var url = location.origin + location.pathname + '#p=' + encodeURIComponent(b.getAttribute('data-share'));
      var done = function () { var old = b.innerHTML; b.innerHTML = 'Линкът е копиран.'; b.setAttribute('aria-live', 'polite'); setTimeout(function () { b.innerHTML = old; }, 1800); };
      if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(url).then(done, function () { prompt('Копирай този линк:', url); });
      else prompt('Копирай този линк:', url);
    });
  });
}

function cardHTML(p) {
  var open = state.openReg === p.reg;
  var cat = p.cat || 'other';
  var col = CAT_COLORS[cat] || '#9AA0AB';
  var flags = (p.f || []).slice(0, 2).map(function (f) {
    return '<span class="b6-tag warn" title="Автоматична бележка: намерено „' + esc(f.term || f.label) + '“ в ' + esc(f.field_label || 'наличните данни') + '">' + I.warn + 'Бележка: ' + esc(f.label) + '</span>';
  }).join('');
  var traderFirm = p.tr && p.tk === 'firm';
  var producerFirm = p.p && p.pk === 'firm';
  var firm = traderFirm ? firstPart(p.tr) : (producerFirm ? firstPart(p.p) : firstPart(p.p || ''));
  var firmIcon = traderFirm ? I.store : (producerFirm ? I.factory : I.flag);
  var infTag = (traderFirm && p.ti) ? '<span class="b6-tag info" title="Търговецът е определен автоматично по марката в наименованието; регистърът не го посочва">по името</span>' : '';
  return '<div class="b6-card' + ((p.f || []).length ? ' flagged' : '') + (open ? ' open' : '') + '" data-reg="' + esc(p.reg) + '" role="button" tabindex="0" aria-expanded="' + (open ? 'true' : 'false') + '" aria-label="' + (open ? 'Скрий подробностите за ' : 'Покажи подробности за ') + esc(p.n) + '">' +
    '<div class="b6-ch">' +
      '<div class="b6-tile" style="background:' + col + '1C;color:' + col + '" title="' + esc(catLabel(cat, p.catl)) + '" aria-label="Автоматична категория: ' + esc(catLabel(cat, p.catl)) + '">' + (CAT_ICONS[cat] || CAT_ICONS.other) + '</div>' +
      '<div style="min-width:0"><div class="b6-cn">' + esc(p.n) + '</div>' +
        '<div class="b6-cm"><span class="b6-reg">' + esc(p.reg) + '</span>' +
        (firm ? '<span class="b6-firm">' + firmIcon + esc(firm) + '</span>' : '') + infTag +
        flags + '</div>' +
      '</div>' +
      '<div class="b6-cd">' + (p.nd ? '<span class="b6-date" title="Дата на уведомление" aria-label="Дата на уведомление: ' + fmtDate(p.nd) + '">' + fmtDate(p.nd) + '</span>' : '') + '<span class="b6-chev" aria-hidden="true">' + I.chev + '</span></div>' +
    '</div>' +
    (open ? bodyHTML(p) : '') +
  '</div>';
}

function bodyHTML(p) {
  var ings = parseComp(p.c);
  var hasAmt = false;
  var ingsH = ings.map(function (i) {
    var cls = classifyIng(i.name, p.f);
    if (i.amount) hasAmt = true;
    return '<span class="b6-ing' + (cls ? ' ' + cls : '') + '" data-ing="' + esc(i.name) + '" role="button" tabindex="0" title="Потърси това наименование">' + esc(i.name) + (i.amount ? ' <span class="amt">' + esc(i.amount) + ' ' + esc(i.unit) + '</span>' : '') + '</span>';
  }).join('');
  var flagH = '';
  if ((p.f || []).length) {
    flagH = '<div class="b6-flagbox">' + I.warn + '<div>' + p.f.map(function (f) {
      return '<b>Бележка за „' + esc(f.term || f.label) + '“</b>' +
        (f.excerpt ? 'Намерено в ' + esc(f.field_label || 'наличните данни') + ': „' + esc(f.excerpt) + '“.' : 'В наличните данни е намерен термин от списъка за проверка.');
    }).join('<div style="height:6px"></div>') +
    '<div class="b6-flagnote">Това е автоматично съвпадение по текст, а не становище за продукта. Бележката не определя статуса на продукта.</div></div></div>';
  }

  var rows = '';
  if (p.p) {
    if (p.pk === 'country') rows += '<span class="l">Държава в полето „Производител“</span><span class="v">' + esc(p.p) + '</span>';
    else rows += '<span class="l">Производител</span><span class="v">' + partyLink('p', p.pn, firstPart(p.p)) + (restPart(p.p) ? '<div style="font-size:11.5px;color:var(--t3)">' + esc(restPart(p.p)) + '</div>' : '') + '</span>';
  }
  if (p.tr && p.tk === 'firm') {
    if (p.ti) {
      rows += '<span class="l">Търговец <span class="b6-tag info">по името</span></span><span class="v">' + partyLink('t', p.tn, firstPart(p.tr)) +
        '<div style="font-size:11.5px;color:var(--t3);margin-top:3px">В регистъра търговец не е посочен' + (p.tro ? ' (записано: „' + esc(p.tro) + '“)' : '') + '. Определен е автоматично по марката в наименованието, както при други продукти, вписани с този търговец. Не е информация от БАБХ.</div></span>';
    } else {
      rows += '<span class="l">Търговец</span><span class="v">' + partyLink('t', p.tn, firstPart(p.tr)) + (restPart(p.tr) ? '<div style="font-size:11.5px;color:var(--t3)">' + esc(restPart(p.tr)) + '</div>' : '') + '</span>';
    }
  }
  if (p.nd) rows += '<span class="l">Дата на уведомление</span><span class="v">' + fmtDate(p.nd) + '</span>';
  if (p.ld) rows += '<span class="l">Дата на пускане на пазара</span><span class="v">' + fmtDate(p.ld) + '</span>';
  if (p.o) rows += '<span class="l">Област според рег. №</span><span class="v">' + esc(p.o) + '</span>';
  if (p.st) rows += '<span class="l">Данни за съхранение</span><span class="v" style="font-size:12.5px">' + esc(p.st) + '</span>';
  if (p.del) rows += '<span class="l">Бележка за заличаване</span><span class="v" style="color:var(--red);font-weight:600">' + (p.dn ? esc(p.dn) : 'В източника има бележка за заличаване.') + '</span>';

  return '<div class="b6-body"><div>' +
    flagH +
    '<div class="b6-sec"><div class="b6-sec-l">Данни от регистъра</div><div class="b6-grid">' + rows + '</div></div>' +
    (p.pp ? '<div class="b6-sec"><div class="b6-sec-l">Предназначение според регистъра</div><div class="b6-purpose">' + esc(p.pp) + '</div></div>' : '') +
    (ings.length ? '<div class="b6-sec"><div class="b6-sec-l"><span>Състав според регистъра</span></div><div class="b6-ings">' + ingsH + '</div>' +
      (hasAmt ? '<div class="b6-flagnote">Количествата са показани според наличния текст. Проверявай в източника за каква доза се отнасят.</div>' : '') +
      '<details class="b6-orig"><summary>Покажи текста на състава от регистъра</summary><div>' + esc(p.c) + '</div></details></div>' : '') +
  '</div><div class="b6-aside">' +
    '<div class="b6-acard"><div class="b6-acts">' +
      '<button class="b6-act" data-share="' + esc(p.reg) + '">' + I.share + 'Копирай линк</button>' +
    '</div></div>' +
  '</div></div>';
}

var NV_STATUS = {
  banned:  { cls: 'danger', bg: 'var(--red-bg)',   bd: 'var(--red-line)',   fg: 'var(--red)' },
  ok:      { cls: 'ok',     bg: 'var(--green-bg)', bd: '#BFE3CC',           fg: 'var(--green)' },
  caution: { cls: 'warn',   bg: 'var(--amber-bg)', bd: 'var(--amber-line)', fg: 'var(--amber)' },
  pending: { cls: 'info',   bg: 'var(--blue-bg)',  bd: '#C3D8F5',           fg: 'var(--blue)' }
};
var nvHistory = [];
function renderNovel(c) {
  c.innerHTML =
    '<div class="b6-head"><div>' +
      '<div class="b6-title">Проверка на съставки <span class="b6-pro-badge">PRO</span></div>' +
      '<div class="b6-sub">Справка за съставка по EU Novel Food Catalogue и Union List с помощта на AI и търсене в интернет. Резултатът е ориентировъчен и се пази 7 дни.</div>' +
    '</div></div>' +
    '<div class="b6-nv">' +
      '<div class="b6-nv-bar">' +
        '<div class="b6-fq" style="flex:1">' + I.search + '<input id="b6-nv-q" type="text" placeholder="Напр. туркестерон, NMN, berberine, ashwagandha…" autocomplete="off"></div>' +
        '<button class="b6-nv-go" id="b6-nv-go">' + I.flask + ' Провери</button>' +
      '</div>' +
      '<div id="b6-nv-res"></div>' +
      '<div id="b6-nv-hist"></div>' +
      '<div class="b6-nv-note">Инструментът е информативен и не замества правна консултация. Източник: EU Novel Food Catalogue, Union List (Reg. 2017/2470).</div>' +
    '</div>';
  var inp = $('#b6-nv-q'), go = $('#b6-nv-go'), res = $('#b6-nv-res');
  function renderHist() {
    var h = $('#b6-nv-hist');
    if (!nvHistory.length) { h.innerHTML = ''; return; }
    h.innerHTML = '<div class="b6-sec-l" style="margin:18px 0 8px">Последни проверки</div>' +
      nvHistory.map(function (r, i) {
        var st = NV_STATUS[r.status] || NV_STATUS.caution;
        return '<button class="b6-nv-hrow" data-h="' + i + '"><span class="b6-nv-dot" style="background:' + st.fg + '"></span><span class="b6-nv-hn">' + esc(r.ingredient) + '</span><span class="b6-nv-hl" style="color:' + st.fg + '">' + esc(r.label_bg) + '</span></button>';
      }).join('');
    $$('.b6-nv-hrow').forEach(function (b) {
      b.addEventListener('click', function () { showResult(nvHistory[parseInt(b.getAttribute('data-h'), 10)]); });
    });
  }
  function showResult(r) {
    var st = NV_STATUS[r.status] || NV_STATUS.caution;
    res.innerHTML =
      '<div class="b6-nv-card" style="background:' + st.bg + ';border-color:' + st.bd + '">' +
        '<div class="b6-nv-top"><span class="b6-nv-badge" style="background:' + st.fg + '">' + esc(r.label_bg) + '</span><span class="b6-nv-ing">' + esc(r.ingredient) + '</span>' + (r.cached ? '<span class="b6-nv-cache">от кеш</span>' : '') + '</div>' +
        '<div class="b6-nv-sum" style="color:' + st.fg + '">' + esc(r.summary_bg) + '</div>' +
        '<div class="b6-nv-meta">' + (r.source ? 'Източник: ' + esc(r.source) + ' · ' : '') + 'Сигурност: ' + esc(r.confidence || '—') + '</div>' +
      '</div>';
  }
  function check() {
    var q = (inp.value || '').trim();
    if (q.length < 2) { inp.focus(); return; }
    go.disabled = true; go.innerHTML = 'Проверявам…';
    res.innerHTML = '<div class="b6-sk"><div class="b6-sk-line" style="width:35%"></div><div class="b6-sk-line" style="width:85%;margin-top:10px"></div><div class="b6-sk-line" style="width:60%;margin-top:8px"></div></div>';
    fetch(restUrl('/novel-check', ''), {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ ingredient: q })
    }).then(function (r) { return r.json().then(function (d) { return { ok: r.ok, d: d }; }); })
      .then(function (x) {
        go.disabled = false; go.innerHTML = I.flask + ' Провери';
        if (!x.ok) {
          res.innerHTML = '<div class="b6-nv-card" style="background:var(--red-bg);border-color:var(--red-line)"><div class="b6-nv-sum" style="color:var(--red)">' + esc((x.d && x.d.message) || 'Грешка при проверката.') + '</div></div>';
          return;
        }
        showResult(x.d);
        nvHistory = [x.d].concat(nvHistory.filter(function (h) { return h.ingredient !== x.d.ingredient; })).slice(0, 8);
        renderHist();
      }).catch(function () {
        go.disabled = false; go.innerHTML = I.flask + ' Провери';
        res.innerHTML = '<div class="b6-nv-card" style="background:var(--red-bg);border-color:var(--red-line)"><div class="b6-nv-sum" style="color:var(--red)">Грешка при връзка — опитай пак.</div></div>';
      });
  }
  go.addEventListener('click', check);
  inp.addEventListener('keydown', function (e) { if (e.key === 'Enter') check(); });
  renderHist();
  setTimeout(function () { inp.focus(); }, 100);
}

/* ===== Pro: Производители / Търговци ===== */
function partyToolbarHTML(kind) {
  var ps = state.plist[kind], isP = kind === 'p';
  return '<div class="b6-fbar flat">' +
      '<div class="b6-fq">' + I.search + '<input id="b6-pq-' + kind + '" type="text" placeholder="' + (isP ? 'Търси производител…' : 'Търси търговец…') + '" aria-label="' + (isP ? 'Търсене на производител' : 'Търсене на търговец') + '" value="' + esc(ps.q) + '" autocomplete="off"><button class="clr' + (ps.q ? ' show' : '') + '" id="b6-pqclr-' + kind + '" aria-label="Изчисти търсенето">' + I.x + '</button></div>' +
      '<button class="b6-chip' + (ps.all ? '' : ' on') + '" id="b6-pbg-' + kind + '" aria-pressed="' + (ps.all ? 'false' : 'true') + '" title="Определено по името и адреса в регистъра">' + I.flag + (ps.all ? 'Всички, вкл. чуждестранни' : 'Само с българска регистрация') + '</button>' +
      '<select class="b6-sel" id="b6-psort-' + kind + '" aria-label="Подреждане">' +
        '<option value="products"' + (ps.sort === 'products' ? ' selected' : '') + '>Най-много продукти</option>' +
        '<option value="partners"' + (ps.sort === 'partners' ? ' selected' : '') + '>Най-много ' + (isP ? 'клиенти' : 'доставчици') + '</option>' +
        '<option value="flagged"' + (ps.sort === 'flagged' ? ' selected' : '') + '>Най-много за проверка</option>' +
        '<option value="newest"' + (ps.sort === 'newest' ? ' selected' : '') + '>Най-нови регистрации</option>' +
        '<option value="name"' + (ps.sort === 'name' ? ' selected' : '') + '>По име</option>' +
      '</select>' +
    '</div>';
}
function bindPartyToolbar(kind) {
  var ps = state.plist[kind];
  var qi = $('#b6-pq-' + kind), qT = null;
  if (!qi) return;
  qi.addEventListener('input', function (e) {
    clearTimeout(qT); var v = e.target.value; $('#b6-pqclr-' + kind).classList.toggle('show', !!v);
    qT = setTimeout(function () { ps.q = v; ps.page = 1; loadParties(kind, false); }, 350);
  });
  $('#b6-pqclr-' + kind).addEventListener('click', function () { ps.q = ''; ps.page = 1; qi.value = ''; this.classList.remove('show'); loadParties(kind, false); });
  $('#b6-pbg-' + kind).addEventListener('click', function () {
    ps.all = !ps.all; ps.page = 1;
    this.classList.toggle('on', !ps.all); this.setAttribute('aria-pressed', ps.all ? 'false' : 'true');
    this.innerHTML = I.flag + (ps.all ? 'Всички, вкл. чуждестранни' : 'Само с българска регистрация');
    loadParties(kind, false);
  });
  $('#b6-psort-' + kind).addEventListener('change', function (e) { ps.sort = e.target.value; ps.page = 1; loadParties(kind, false); });
}
/* Показва списъка, ако вече е зареден с подходящ размер; иначе го зарежда */
function ensureParties(kind) {
  var ps = state.plist[kind], per = state.tab === 'register' ? 8 : 40;
  if (ps.loaded && ps.per === per) { renderPartyList(kind, false); return; }
  if (ps.loading && ps.per === per) { renderPartyList(kind, true); return; }  /* заявката тече; ще се покаже в новия контейнер */
  ps.per = per; ps.page = 1; ps.loaded = false;
  loadParties(kind, false);
}
function loadParties(kind, append) {
  var ps = state.plist[kind];
  ps.loading = true;
  if (!append) renderPartyList(kind, true);
  return api('/parties', { kind: kind, q: ps.q, sort: ps.sort, all: ps.all, page: ps.page, per: ps.per }).then(function (r) {
    ps.total = r.total; ps.allN = r.all; ps.allBg = r.all_bg;
    ps.items = append ? ps.items.concat(r.items) : r.items;
    ps.loading = false; ps.loaded = true;
    renderPartyList(kind, false);
  }).catch(function (e) {
    ps.loading = false;
    var c = $('#b6-plist-' + kind);
    if (c) c.innerHTML = '<div class="b6-empty">' + I.empty + '<div class="b6-empty-t">Грешка при зареждане</div><div>' + esc(e.message) + ' — опитай да презаредиш страницата.</div></div>';
  });
}
function openParty(kind, norm) {
  var ps = state.party;
  ps.kind = kind; ps.open = norm; ps.detail = null;
  var tab = kind === 'p' ? 'producers' : 'traders';
  if (state.tab !== tab) { state.tab = tab; state.page = 1; state.openReg = null; closeSidebar();
    $$('.b6-item').forEach(function (b) { b.classList.toggle('on', b.getAttribute('data-tab') === state.tab); });
    $$('.b6-bnav button[data-tab]').forEach(function (b) { b.classList.toggle('on', b.getAttribute('data-tab') === state.tab); }); }
  render();
  api('/party', { kind: kind, norm: norm }).then(function (d) {
    ps.detail = d; render();
  }).catch(function (e) {
    var c = $('#b6-content');
    c.innerHTML = '<div class="b6-empty">' + I.empty + '<div class="b6-empty-t">Грешка при зареждане</div><div>' + esc(e.message) + '</div></div>';
  });
  window.scrollTo({ top: root.offsetTop - 20, behavior: 'smooth' });
}
/* Пълен раздел: списък + профил */
function renderParties(c, kind) {
  var ps = state.party;
  if (ps.open && ps.kind === kind) { renderPartyDetail(c); return; }
  var isP = kind === 'p';
  c.innerHTML =
    '<div class="b6-head"><div>' +
      '<div class="b6-title">' + (isP ? 'Производители' : 'Търговци') + ' <span class="b6-pro-badge">PRO</span></div>' +
      '<div class="b6-sub">' + (isP ? 'Кой за кого произвежда: клиентите на всеки производител и колко продукта има при всеки от тях.' : 'Кой доставя на кого: производителите зад всеки търговец и с колко продукта.') + ' Само записи, налични в последното качване. Фирмите са групирани автоматично по името в регистъра; „българска регистрация“ е определена по името и адреса.</div>' +
    '</div><div class="b6-actions"><button class="b6-btn" id="b6-pexport">' + I.dl + '<span>CSV на списъка</span></button></div></div>' +
    partyToolbarHTML(kind) +
    '<div id="b6-plist-' + kind + '"></div>';
  bindPartyToolbar(kind);
  $('#b6-pexport').addEventListener('click', function () {
    var pl = state.plist[kind];
    var rows = [[isP ? 'Производител' : 'Търговец', 'Българска регистрация (автоматично, по име и адрес)', 'Продукти', 'От тях с търговец по името (автоматично)', isP ? 'Клиенти' : 'Доставчици', 'С бележки за проверка', 'От година', 'До година']];
    pl.items.forEach(function (it) { rows.push([it.name, it.bg ? 'да' : '', it.products, it.inferred || 0, it.partners, it.flagged, it.y1 || '', it.y2 || '']); });
    downloadCSV(rows, (isP ? 'proizvoditeli' : 'targovci') + '.csv');
  });
  ensureParties(kind);
}
function renderPartyList(kind, skeleton) {
  var ps = state.plist[kind], c = $('#b6-plist-' + kind);
  if (!c) return;
  var isP = kind === 'p', compact = state.tab === 'register';
  if (skeleton && ps.loading && !ps.items.length) {
    var sk = ''; for (var i = 0; i < (compact ? 4 : 6); i++) sk += '<div class="b6-sk"><div class="b6-sk-line" style="width:' + (30 + i * 9) % 60 + '%"></div></div>';
    c.innerHTML = sk; return;
  }
  if (!ps.items.length) {
    c.innerHTML = '<div class="b6-empty">' + I.empty + '<div class="b6-empty-t">Няма съвпадения</div><div>' + (ps.all ? 'Опитай по-кратка дума.' : 'Опитай по-кратка дума или включи и чуждестранните фирми.') + '</div></div>';
    return;
  }
  var scope = ps.all ? '(вкл. чуждестранни)' : 'с българска регистрация';
  var html = '<div class="b6-meta"><span><b>' + nfmt(ps.total) + '</b> ' + plural(ps.total, 'фирма', 'фирми') + ' ' + scope + (ps.q ? ' за „' + esc(ps.q) + '“' : '') + (ps.allN ? ' · общо в регистъра ' + nfmt(ps.allN) + ' (' + nfmt(ps.allBg) + ' български)' : '') + '</span><span>Показани ' + ps.items.length + '</span></div>' +
    '<div class="b6-tbl-wrap"><table class="b6-tbl"><thead><tr><th>#</th><th>Фирма</th><th class="num">Продукти</th><th class="num">' + (isP ? 'Клиенти' : 'Доставчици') + '</th><th class="num" title="Продукти с автоматична бележка за проверка">За проверка</th>' + (compact ? '' : '<th class="num">Години</th>') + '</tr></thead><tbody>';
  ps.items.forEach(function (it, i) {
    html += '<tr class="b6-prow" data-party="' + esc(kind) + '|' + esc(it.norm) + '" tabindex="0" role="link" aria-label="Профил на ' + esc(it.name) + '">' +
      '<td class="idx">' + (i + 1) + '</td>' +
      '<td><div class="b6-pname">' + esc(it.name) + (it.bg ? ' <span class="b6-tag green" title="Българска регистрация — определено по името и адреса в регистъра">' + I.flag + 'БГ</span>' : '') + '</div></td>' +
      '<td class="num"><b>' + nfmt(it.products) + '</b>' + (it.inferred ? '<div class="b6-inf" title="Продукти, при които търговецът е определен по името (регистърът не го посочва)">' + nfmt(it.inferred) + ' по името</div>' : '') + '</td>' +
      '<td class="num">' + nfmt(it.partners) + '</td>' +
      '<td class="num">' + (it.flagged ? '<span class="b6-tag warn">' + nfmt(it.flagged) + '</span>' : '<span style="color:var(--t4)">0</span>') + '</td>' +
      (compact ? '' : '<td class="num" style="color:var(--t3)">' + (it.y1 ? (it.y1 === it.y2 ? it.y1 : it.y1 + '–' + it.y2) : '—') + '</td>') +
    '</tr>';
  });
  html += '</tbody></table></div>';
  if (ps.items.length < ps.total) html += '<div class="b6-more-wrap" style="margin-top:10px"><button class="b6-more" id="b6-pmore-' + kind + '">Покажи още ' + Math.min(ps.per, ps.total - ps.items.length) + '</button><div class="b6-left">' + nfmt(ps.total - ps.items.length) + ' остават' + (compact ? ' · пълният списък е в раздел „' + (isP ? 'Производители' : 'Търговци') + '“' : '') + '</div></div>';
  c.innerHTML = html;
  var more = $('#b6-pmore-' + kind);
  if (more) more.addEventListener('click', function () { more.disabled = true; more.textContent = 'Зареждане…'; ps.page++; loadParties(kind, true); });
  $$('.b6-prow', c).forEach(function (tr) {
    tr.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); tr.click(); } });
  });
}
function renderPartyDetail(c) {
  var ps = state.party, d = ps.detail, isP = ps.kind === 'p';
  var back = '<button class="b6-back" id="b6-pback">' + I.chev + 'Всички ' + (isP ? 'производители' : 'търговци') + '</button>';
  if (!d) {
    c.innerHTML = back + '<div class="b6-sk" style="margin-top:14px"><div class="b6-sk-line" style="width:40%"></div><div class="b6-sk-line" style="width:70%;margin-top:10px"></div></div>';
    $('#b6-pback').addEventListener('click', function () { ps.open = null; ps.detail = null; render(); });
    return;
  }
  var partnersLbl = isP ? 'Клиенти' : 'Доставчици';
  var partnerKind = isP ? 't' : 'p';
  var maxC = 1; d.partners.forEach(function (x) { if (x.count > maxC) maxC = x.count; });
  var rows = d.partners.map(function (x, i) {
    return '<tr>' +
      '<td class="idx">' + (i + 1) + '</td>' +
      '<td><div class="b6-pname"><a href="#" class="b6-plink" data-party="' + esc(partnerKind) + '|' + esc(x.norm) + '">' + esc(x.name) + '</a></div>' +
        '<div class="b6-share"><i style="width:' + Math.max(2, Math.round(100 * x.count / maxC)) + '%"></i></div></td>' +
      '<td class="num"><a href="#" class="b6-plink num" data-products="' + esc(x.norm) + '" title="Покажи продуктите"><b>' + nfmt(x.count) + '</b></a>' +
        (x.inferred ? '<div class="b6-inf" title="Продукти без посочен търговец в регистъра, отнесени тук по марката в наименованието">' + nfmt(x.inferred) + ' по името</div>' : '') + '</td>' +
      '<td class="num" style="color:var(--t3)">' + x.share + '%</td>' +
      '<td class="num">' + (x.flagged ? '<span class="b6-tag warn">' + nfmt(x.flagged) + '</span>' : '<span style="color:var(--t4)">0</span>') + '</td>' +
      '<td class="num" style="color:var(--t3);white-space:nowrap">' + (x.first ? fmtDate(x.first).slice(3) : '—') + (x.last && x.last !== x.first ? ' – ' + fmtDate(x.last).slice(3) : '') + '</td>' +
    '</tr>';
  }).join('');
  var yrs = '';
  if (d.years.length) {
    var ymax = 1; d.years.forEach(function (y) { if (y.c > ymax) ymax = y.c; });
    yrs = '<div class="b6-yrs">' + d.years.map(function (y) { return '<div class="b6-yr" title="' + y.y + ': ' + y.c + '"><i style="height:' + Math.max(6, Math.round(100 * y.c / ymax)) + '%"></i><span>' + String(y.y).slice(2) + '</span></div>'; }).join('') + '</div>';
  }
  var cats = d.cats.map(function (ct) { return '<span class="b6-chip" style="cursor:default"><span class="dot" style="display:inline-block;width:7px;height:7px;border-radius:50%;background:' + (CAT_COLORS[ct.code] || '#999') + '"></span>' + esc(ct.label) + '<span class="c">' + nfmt(ct.count) + '</span></span>'; }).join('');
  var hasInf = d.inferred > 0;
  c.innerHTML = back +
    '<div class="b6-head" style="margin-top:12px"><div>' +
      '<div class="b6-title" style="font-size:24px">' + (isP ? I.factory : I.store) + ' ' + esc(d.name) + (d.bg ? ' <span class="b6-tag green" title="Българска регистрация — определено по името и адреса в регистъра">' + I.flag + 'БГ</span>' : ' <span class="b6-tag" style="background:var(--bg-soft);color:var(--t3)" title="Не е разпозната българска регистрация по името и адреса">чуждестранна</span>') + '</div>' +
      (d.full && d.full !== d.name ? '<div class="b6-sub">' + esc(d.full) + '</div>' : '') +
    '</div><div class="b6-actions">' +
      '<button class="b6-btn" id="b6-pall">' + I.search + '<span>Всички продукти</span></button>' +
      '<button class="b6-btn" id="b6-pcsv">' + I.dl + '<span>CSV ' + partnersLbl.toLowerCase() + '</span></button>' +
    '</div></div>' +
    '<div class="b6-kpis">' +
      '<div class="b6-bc"><div class="b6-bc-l">' + I.layers + 'Продукти в наличните данни</div><div class="b6-bc-n">' + nfmt(d.products) + '</div><div class="b6-bc-s">' + (d.y1 ? 'рег. номера ' + d.y1 + (d.y2 && d.y2 !== d.y1 ? '–' + d.y2 : '') : '') + (d.deleted ? ' · ' + nfmt(d.deleted) + ' липсващи в последващ файл' : '') + '</div></div>' +
      '<div class="b6-bc tint"><div class="b6-bc-l">' + (isP ? I.store : I.factory) + partnersLbl + '</div><div class="b6-bc-n">' + nfmt(d.partners.length) + '</div><div class="b6-bc-s">' + (isP ? 'фирми, за които произвежда' : 'фирми, които произвеждат за него') + (hasInf ? ' · <a href="#" class="b6-plink" id="b6-pinf">' + nfmt(d.inferred) + ' продукта по името</a>' : '') + '</div></div>' +
      '<div class="b6-bc"><div class="b6-bc-l">' + I.flag + (isP ? 'Собствени / без търговец' : 'Без посочен производител') + '</div><div class="b6-bc-n"><a href="#" class="b6-plink" id="b6-pown">' + nfmt(d.own) + '</a></div><div class="b6-bc-s">' + (isP ? 'продукти без насрещна фирма' : 'или производителят е държава') + '</div></div>' +
      '<div class="b6-bc"><div class="b6-bc-l">' + I.warn + 'За проверка</div><div class="b6-bc-n">' + nfmt(d.flagged) + '</div><div class="b6-bc-s">с автоматична бележка</div></div>' +
    '</div>' +
    (yrs || cats ? '<div class="b6-pmeta">' + (yrs ? '<div><div class="b6-sec-l">Регистрации по години</div>' + yrs + '</div>' : '') + (cats ? '<div><div class="b6-sec-l">Категории</div><div class="b6-pcats">' + cats + '</div></div>' : '') + '</div>' : '') +
    '<div class="b6-meta" style="margin-top:18px"><span><b>' + nfmt(d.partners.length) + '</b> ' + partnersLbl.toLowerCase() + ' според полето „' + (isP ? 'Търговец' : 'Производител') + '“ в регистъра' + (hasInf ? ', допълнено по името на продукта' : '') + ' · клик на числото показва продуктите</span></div>' +
    (d.partners.length
      ? '<div class="b6-tbl-wrap"><table class="b6-tbl"><thead><tr><th>#</th><th>' + (isP ? 'Клиент (търговец)' : 'Производител') + '</th><th class="num">Продукти</th><th class="num">Дял</th><th class="num" title="Продукти с автоматична бележка за проверка">За проверка</th><th class="num">Период</th></tr></thead><tbody>' + rows + '</tbody></table></div>' +
        (hasInf ? '<div class="b6-flagnote">„По името“: продукти, при които регистърът не посочва търговец (или посочва държава / самия производител), а марката в наименованието съвпада с други продукти, правилно вписани с този търговец. Това е автоматично предположение, не данни на БАБХ.</div>' : '')
      : '<div class="b6-empty">' + I.empty + '<div class="b6-empty-t">Няма ' + partnersLbl.toLowerCase() + '</div><div>Всички продукти са без насрещна фирма (собствена марка / без търговец).</div></div>') +
    brandsHTML(d, isP);
  $('#b6-pback').addEventListener('click', function () { ps.open = null; ps.detail = null; render(); });
  $$('[data-brand]', c).forEach(function (a) {
    a.addEventListener('click', function (e) {
      e.preventDefault(); e.stopPropagation();
      var f = { brand: a.getAttribute('data-brand') };
      var pn = a.getAttribute('data-brand-producer');
      if (pn) { f.producer = pn; f.producerName = a.getAttribute('data-brand-pname') || ''; }
      else if (isP) { f.producer = d.norm; f.producerName = d.name; f.own = true; }
      gotoProducts(f);
    });
  });
  var self = {}; self[isP ? 'producer' : 'trader'] = d.norm; self[isP ? 'producerName' : 'traderName'] = d.name;
  $('#b6-pall').addEventListener('click', function () { gotoProducts(self); });
  $('#b6-pown').addEventListener('click', function (e) { e.preventDefault(); var f = {}; f[isP ? 'producer' : 'trader'] = d.norm; f[isP ? 'producerName' : 'traderName'] = d.name; f.own = true; gotoProducts(f); });
  var pinf = $('#b6-pinf');
  if (pinf) pinf.addEventListener('click', function (e) { e.preventDefault(); var f = {}; f[isP ? 'producer' : 'trader'] = d.norm; f[isP ? 'producerName' : 'traderName'] = d.name; f.inferred = true; gotoProducts(f); });
  $('#b6-pcsv').addEventListener('click', function () {
    var rows = [[isP ? 'Производител' : 'Търговец', isP ? 'Клиент' : 'Доставчик', 'Продукти', 'От тях с търговец по името (автоматично)', 'Дял %', 'С бележки за проверка', 'Първо уведомление', 'Последно уведомление']];
    d.partners.forEach(function (x) { rows.push([d.name, x.name, x.count, x.inferred || 0, x.share, x.flagged, x.first || '', x.last || '']); });
    var fn = d.norm.replace(/[^a-z0-9]+/gi, '-').replace(/^-+|-+$/g, '');
    downloadCSV(rows, (isP ? 'klienti-' : 'dostavchici-') + (fn || d.norm.length) + '.csv');
  });
  $$('[data-products]', c).forEach(function (a) {
    a.addEventListener('click', function (e) {
      e.preventDefault(); e.stopPropagation();
      var norm = a.getAttribute('data-products'), p = null;
      d.partners.forEach(function (x) { if (x.norm === norm) p = x; });
      var f = {};
      f[isP ? 'producer' : 'trader'] = d.norm; f[isP ? 'producerName' : 'traderName'] = d.name;
      f[isP ? 'trader' : 'producer'] = norm; f[isP ? 'traderName' : 'producerName'] = p ? p.name : '';
      gotoProducts(f);
    });
  });
}
/* Марки по първата дума в наименованието — автоматично; регистърът често не посочва търговец */
function brandsHTML(d, isP) {
  var b = d.brands || [];
  if (!b.length) return '';
  var head, note, rows;
  if (isP) {
    head = 'Марки в имената на продуктите без посочен търговец';
    note = 'Определени автоматично по първата дума в наименованието на продуктите, при които регистърът не посочва търговец. „Възможна фирма“ е фирма от регистъра със същото начало на името. Това е предположение, не данни на БАБХ.';
    rows = b.map(function (x, i) {
      return '<tr><td class="idx">' + (i + 1) + '</td>' +
        '<td><div class="b6-pname">' + esc(x.token.toUpperCase()) + '</div></td>' +
        '<td class="num"><a href="#" class="b6-plink num" data-brand="' + esc(x.token) + '" title="Покажи продуктите"><b>' + nfmt(x.count) + '</b></a></td>' +
        '<td>' + (x.match ? '<a href="#" class="b6-plink" data-party="' + esc(x.match.kind) + '|' + esc(x.match.norm) + '">' + esc(x.match.name) + '</a> <span style="color:var(--t4);font-size:11px">(' + (x.match.kind === 't' ? 'търговец' : 'производител') + ')</span>' : '<span style="color:var(--t4)">—</span>') + '</td></tr>';
    }).join('');
    return '<div class="b6-meta" style="margin-top:22px"><span><b>' + nfmt(b.length) + '</b> ' + (b.length === 1 ? 'марка' : 'марки') + ' в имената на продуктите</span></div>' +
      '<div class="b6-tbl-wrap"><table class="b6-tbl"><thead><tr><th>#</th><th>Марка (първа дума)</th><th class="num">Продукти</th><th>Възможна фирма</th></tr></thead><tbody>' + rows + '</tbody></table></div>' +
      '<div class="b6-flagnote">' + note + '</div>';
  }
  head = 'Продукти с марка „' + esc((d.brand_token || '').toUpperCase()) + '“ при други производители';
  note = 'Продукти, чието наименование започва с първата дума от името на фирмата, а в регистъра е посочен друг производител без този търговец. Определено автоматично по името, не по данни на БАБХ.';
  var total = 0; b.forEach(function (x) { total += x.count; });
  rows = b.filter(function (x) { return x.match; }).map(function (x, i) {
    return '<tr><td class="idx">' + (i + 1) + '</td>' +
      '<td><div class="b6-pname"><a href="#" class="b6-plink" data-party="p|' + esc(x.match.norm) + '">' + esc(x.match.name) + '</a></div></td>' +
      '<td class="num"><a href="#" class="b6-plink num" data-brand="' + esc(x.token) + '" data-brand-producer="' + esc(x.match.norm) + '" data-brand-pname="' + esc(x.match.name) + '" title="Покажи продуктите"><b>' + nfmt(x.count) + '</b></a></td></tr>';
  }).join('');
  return '<div class="b6-meta" style="margin-top:22px"><span><b>' + nfmt(total) + '</b> ' + (total === 1 ? 'продукт' : 'продукта') + ' · ' + head + '</span></div>' +
    '<div class="b6-tbl-wrap"><table class="b6-tbl"><thead><tr><th>#</th><th>Производител</th><th class="num">Продукти</th></tr></thead><tbody>' + rows + '</tbody></table></div>' +
    '<div class="b6-flagnote">' + note + '</div>';
}
function downloadCSV(rows, filename) {
  var csv = '\uFEFF' + rows.map(function (r) { return r.map(function (v) { v = String(v == null ? '' : v); return /[";\n]/.test(v) ? '"' + v.replace(/"/g, '""') + '"' : v; }).join(';'); }).join('\r\n');
  var blob = new Blob([csv], { type: 'text/csv;charset=utf-8' });
  var a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = filename; document.body.appendChild(a); a.click();
  setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 500);
}

function renderSoon(c, title, desc, source, isPro) {
  var ok = state.waitOk[source];
  c.innerHTML =
    '<div class="b6-head"><div><div class="b6-title">' + esc(title) + '</div></div></div>' +
    '<div class="b6-soon">' +
      '<span class="b6-soon-badge"><i></i>' + (isPro ? 'Pro достъп' : 'В подготовка') + '</span>' +
      '<h3>' + esc(title) + '</h3><p>' + esc(desc) + '</p>' +
      (ok
        ? '<div class="b6-wait-ok" role="status">' + (ok === 'exists' ? 'Този имейл вече е записан за известие.' : 'Имейлът е записан за известие при ' + (isPro ? 'отваряне на достъпа' : 'пускането на функцията') + '.') + '</div>'
        : '<label class="b6-wl-label" for="b6-wl-email">Имейл адрес</label><div class="b6-wait"><input type="email" id="b6-wl-email" placeholder="name@example.com" autocomplete="email" aria-describedby="b6-wl-err"><input type="text" id="b6-wl-hp" class="b6-hp" tabindex="-1" autocomplete="off"><button id="b6-wl-go">' + I.bell + ' Уведоми ме' + (isPro ? '' : ' при пускане') + '</button></div><div class="b6-wl-err" id="b6-wl-err" role="alert"></div>') +
      '<div class="b6-soon-note">Записването е за известие ' + (isPro ? 'за възможностите за достъп' : 'при пускане на функцията') + '.</div>' +
    '</div>';
  var go = $('#b6-wl-go');
  if (go) go.addEventListener('click', function () {
    var inp = $('#b6-wl-email'), errEl = $('#b6-wl-err');
    var em = (inp.value || '').trim();
    var showErr = function (t) { errEl.textContent = t; inp.setAttribute('aria-invalid', 'true'); inp.style.borderColor = 'var(--red)'; inp.focus(); };
    if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(em)) { showErr('Въведи валиден имейл адрес, например name@example.com.'); return; }
    errEl.textContent = ''; inp.removeAttribute('aria-invalid'); inp.style.borderColor = '';
    go.disabled = true; go.textContent = 'Записване…';
    var status = 0;
    fetch(restUrl('/waitlist', ''), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify({ email: em, source: source, website: $('#b6-wl-hp').value || '' })
    }).then(function (r) { status = r.status; return r.json().catch(function () { return {}; }); }).then(function (j) {
      if (status === 200 && j && j.ok) { state.waitOk[source] = j.exists ? 'exists' : 'ok'; render(); return; }
      go.disabled = false; go.innerHTML = I.bell + ' Уведоми ме' + (isPro ? '' : ' при пускане');
      showErr(j && j.message ? j.message : 'Не успяхме да запишем имейла. Опитай отново.');
    }).catch(function () { go.disabled = false; go.innerHTML = I.bell + ' Уведоми ме' + (isPro ? '' : ' при пускане'); showErr('Не успяхме да запишем имейла. Опитай отново.'); });
  });
}

/* ===== Init ===== */
if (String(CFG.locked) === '1') { renderGate(); return; }
renderShell();
var m = location.hash.match(/#p=([^&]+)/);
if (m) { state.q = decodeURIComponent(m[1]); state.deepReg = state.q; }
render();
loadStats().then(function () { if (state.tab === 'register') render(); loadProducts(false).then(function () {
  if (state.deepReg) { var hit = state.items.filter(function (p) { return p.reg === state.deepReg; })[0]; if (hit) { state.openReg = hit.reg; } renderList(false); }
}); }).catch(function () {
  loadProducts(false);
});
document.addEventListener('click', function (e) {
  var b = e.target.closest ? e.target.closest('[data-goto-pro]') : null;
  if (b && root.contains(b)) { e.stopPropagation(); setTab(b.getAttribute('data-goto-pro')); return; }
  var pl = e.target.closest ? e.target.closest('[data-party]') : null;
  if (pl && root.contains(pl) && PRO) {
    e.preventDefault(); e.stopPropagation();
    var kv = pl.getAttribute('data-party').split('|');
    openParty(kv[0], kv.slice(1).join('|'));
  }
}, true);

})();
