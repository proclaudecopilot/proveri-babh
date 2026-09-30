/* Регистър на добавките v6 — публичен фронтенд (vanilla JS, без зависимости) */
(function () {
'use strict';

var CFG = window.BABH6_CFG || { rest: '/wp-json/babh6/v1' };
var root = document.getElementById('babh6-app');
if (!root) return;

var $ = function (s, r) { return (r || root).querySelector(s); };
var $$ = function (s, r) { return Array.prototype.slice.call((r || root).querySelectorAll(s)); };

/* Филтри на „Продукти“. status: '' активни | flagged | deleted; period: '' | '12m' | година (по рег. №) */
function newFilters() {
  return { q: '', status: '', period: '', obl: '', rt: '', cats: [], sort: 'rel',
    producer: null, producerName: '', trader: null, traderName: '', own: false, brand: null };
}
function newPList() { return { q: '', sort: 'products', all: false, page: 1, per: 40, items: [], total: 0, allN: 0, allBg: 0, loading: false, loaded: false }; }
var state = {
  tab: 'overview',
  f: newFilters(),           /* приложени филтри */
  draft: null,               /* чернова в панела на телефон (до „Приложи“) */
  fpanel: false, catsAll: false,
  page: 1, per: 20, items: [], total: 0, loading: false, openReg: null, deepReg: null,
  stats: null, statsErr: false, waitOk: {},
  plist: { p: newPList(), t: newPList() },
  party: { kind: 'p', open: null, detail: null, view: 'partners', prod: { items: [], total: 0, page: 1, loading: false, openReg: null } }
};
var PRO = String(CFG.pro) === '1';
var isMobile = function () { return window.matchMedia && window.matchMedia('(max-width: 900px)').matches; };

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
  filter: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="22 3 2 3 10 12.5 10 19 14 21 14 12.5 22 3"/></svg>',
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
  return fallback || 'Без определена категория';
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

/* Параметри за /products и /export от филтрите */
function filterParams(f) {
  var isYear = /^\d{4}$/.test(f.period);
  return {
    q: f.q, flagged: f.status === 'flagged', deleted: f.status === 'deleted',
    recent: f.period === '12m', year: isYear ? f.period : null,
    obl: f.obl, rt: f.rt, cat: f.cats.length ? f.cats.join(',') : null, sort: f.sort,
    producer: f.producer, trader: f.trader, own: f.own, brand: f.brand
  };
}
/* Отваря „Продукти“ с чисти филтри + подадените (от профили и обобщението) */
function gotoProducts(f) {
  var nf = newFilters();
  Object.keys(f || {}).forEach(function (k) { nf[k] = f[k]; });
  state.f = nf; state.draft = null; state.page = 1; state.openReg = null;
  setTab('products');
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
        '<button class="b6-item on" data-tab="overview">' + I.grid + '<span class="lbl">Регистър</span></button>' +
        '<button class="b6-item" data-tab="products">' + I.search + '<span class="lbl">Продукти</span><span class="count" id="b6-c-total">—</span></button>' +
        '<button class="b6-item" data-tab="producers">' + I.factory + '<span class="lbl">Производители</span>' + (PRO ? '' : '<span class="pro">PRO</span>') + '</button>' +
        '<button class="b6-item" data-tab="traders">' + I.store + '<span class="lbl">Търговци</span>' + (PRO ? '' : '<span class="pro">PRO</span>') + '</button>' +
        '<div class="b6-sb-l">Pro инструменти</div>' +
        '<button class="b6-item" data-tab="novel">' + I.flask + '<span class="lbl">Проверка на съставки</span>' + (CFG.hasAI == 1 ? '<span class="pro">PRO</span>' : '<span class="pro soon">В подготовка</span>') + '</button>' +
        '<button class="b6-item" data-tab="inspector">' + I.layers + '<span class="lbl">Промени в регистъра</span><span class="pro soon">В подготовка</span></button>' +
        '<button class="b6-item" data-tab="watchlist">' + I.bell + '<span class="lbl">Известия</span><span class="pro soon">В подготовка</span></button>' +
        '<div class="b6-sb-foot"><span id="b6-live-t" aria-live="polite">Зареждане…</span><br>Данните са от регистъра на БАБХ. Сайт за справки по данни от регистъра на БАБХ.</div>' +
      '</aside>' +
      '<div class="b6-main">' +
        '<div class="b6-top">' +
          '<button class="b6-burger" id="b6-burger" aria-label="Отвори менюто" aria-expanded="false">' + I.menu + '</button>' +
          '<div class="b6-topq">' + I.search + '<input id="b6-topq" type="text" placeholder="Търси продукт, фирма или съставка" aria-label="Търсене в регистъра" autocomplete="off"></div>' +
          '<div class="b6-live"><span class="b6-live-dot"></span><span id="b6-live-t2">Зареждане…</span></div>' +
        '</div>' +
        '<div class="b6-content" id="b6-content"></div>' +
      '</div>' +
    '</div>' +
    '<div class="b6-scrim" id="b6-scrim"></div>' +
    '<nav class="b6-bnav">' +
      '<button data-tab="overview" class="on">' + I.grid + '<span>Регистър</span></button>' +
      '<button data-tab="products">' + I.search + '<span>Продукти</span></button>' +
      '<button data-tab="producers">' + I.factory + '<span>Производители</span></button>' +
      '<button data-tab="traders">' + I.store + '<span>Търговци</span></button>' +
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
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { closeSidebar(); if (state.fpanel) { state.fpanel = false; renderFilterPanel(); } } });

  var tqT = null;
  $('#b6-topq').addEventListener('input', function (e) {
    clearTimeout(tqT);
    var v = e.target.value;
    tqT = setTimeout(function () {
      if (state.tab !== 'products') { gotoProducts({ q: v }); var tq = $('#b6-topq'); if (tq) tq.value = v; return; }
      state.f.q = v; state.page = 1;
      var qi = $('#b6-q'); if (qi && qi.value !== v) { qi.value = v; var cl = $('#b6-qclr'); if (cl) cl.classList.toggle('show', !!v); }
      loadProducts(false); renderActive();
    }, 350);
  });
}
function closeSidebar() { $('#b6-sb').classList.remove('open'); $('#b6-scrim').classList.remove('show'); var bg = $('#b6-burger'); if (bg) bg.setAttribute('aria-expanded', 'false'); }

function setTab(t) {
  state.tab = t; state.page = 1; state.openReg = null; state.fpanel = false;
  closeSidebar();
  $$('.b6-item').forEach(function (b) { b.classList.toggle('on', b.getAttribute('data-tab') === t); });
  $$('.b6-bnav button[data-tab]').forEach(function (b) { b.classList.toggle('on', b.getAttribute('data-tab') === t); });
  if (t === 'producers' || t === 'traders') { state.party.open = null; state.party.detail = null; state.party.kind = t === 'producers' ? 'p' : 't'; }
  render();
  if (t === 'products') loadProducts(false);
  window.scrollTo({ top: 0, behavior: 'smooth' });
}

/* ===== Data ===== */
function loadStats() {
  return api('/stats').then(function (s) {
    state.stats = s;
    var ct = $('#b6-c-total');
    if (ct) ct.textContent = nfmt(s.total);
    var txt = s.last_update ? 'Данни, качени на ' + s.last_update : 'Още няма качен регистър.';
    $$('#b6-live-t, #b6-live-t2').forEach(function (el) { el.textContent = txt; });
    state.statsErr = false;
  }).catch(function (e) {
    state.statsErr = true;
    $$('#b6-live-t, #b6-live-t2').forEach(function (el) { el.textContent = 'Статистиката временно не е достъпна.'; });
    throw e;
  });
}
function loadProducts(append) {
  var c0 = $('#b6-list');
  if (state.f.q && state.f.q.trim().length === 1) {
    state.loading = false;
    if (c0) c0.innerHTML = '<div class="b6-empty"><div class="b6-empty-t">Въведи поне 2 знака.</div><div>Търсенето започва от втория знак.</div></div>';
    return Promise.resolve();
  }
  state.loading = true;
  if (!append) renderList(true);
  var p = filterParams(state.f);
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
  switch (state.tab) {
    case 'overview': renderOverview(c); break;
    case 'products': renderProducts(c); break;
    case 'producers': if (PRO) { state.party.kind = 'p'; renderParties(c, 'p'); } else { renderSoon(c, 'Производители', 'Тук можеш да разглеждаш производителите, клиентите им (фирмите, за които произвеждат) и продуктите, в които са посочени. Разделът е достъпен за потребители с Pro достъп.', 'producers', true); } break;
    case 'traders': if (PRO) { state.party.kind = 't'; renderParties(c, 't'); } else { renderSoon(c, 'Търговци', 'Тук можеш да разглеждаш търговците, марките, които продават, производителите зад тях и всички продукти, регистрирани с тях като търговец. Разделът е достъпен за потребители с Pro достъп.', 'traders', true); } break;
    case 'novel': if (CFG.hasAI == 1) { renderNovel(c); } else { renderSoon(c, 'Проверка на съставки', 'Подготвяме справка за съставки с препратки към използваните източници. Функцията още не е достъпна.', 'novel', false); } break;
    case 'inspector': renderSoon(c, 'Промени в регистъра', 'Тук ще можеш да сравняваш качени версии на регистъра и да виждаш добавени, променени и липсващи записи. Функцията още не е достъпна.', 'inspector', false); break;
    case 'watchlist': renderSoon(c, 'Известия за промени', 'Подготвяме известия по имейл за нови записи, свързани с избрани фирми или съставки. Функцията още не е достъпна.', 'watchlist', false); break;
  }
}

/* ===== Регистър: обобщение ===== */
function renderOverview(c) {
  var s = state.stats;
  if (!s) {
    c.innerHTML = '<div class="b6-head"><div><div class="b6-title">Регистър на хранителните добавки</div><div class="b6-sub">' + (state.statsErr ? 'Статистиката временно не е достъпна. Продуктите се търсят в раздел „Продукти“.' : 'Зареждане на обобщението…') + '</div></div></div>';
    return;
  }
  var max = 1, i;
  for (i = 0; i < s.monthly.length; i++) if (s.monthly[i].c > max) max = s.monthly[i].c;
  var names = ['яну', 'фев', 'мар', 'апр', 'май', 'юни', 'юли', 'авг', 'сеп', 'окт', 'ное', 'дек'];
  var bars = '', labels = '', srText = [];
  for (i = 0; i < s.monthly.length; i++) {
    var mo = s.monthly[i];
    var full = monthLabel(mo.m) + ': ' + pluralN(mo.c, 'уведомление', 'уведомления');
    srText.push(full);
    bars += '<div class="b6-bar' + (i === s.monthly.length - 1 ? ' now' : '') + '" style="height:' + Math.max(7, Math.round(mo.c / max * 100)) + '%" title="' + esc(full) + '"></div>';
    labels += '<span>' + names[parseInt(mo.m.slice(5), 10) - 1] + '</span>';
  }
  var period = s.monthly.length ? monthLabel(s.monthly[0].m) + ' – ' + monthLabel(s.monthly[s.monthly.length - 1].m) : '';
  var thisM = s.monthly.length ? s.monthly[s.monthly.length - 1].c : 0;
  var cats = s.cats.filter(function (x) { return x.count > 0; });
  var cmax = 1; cats.forEach(function (x) { if (x.code !== 'other' && x.count > cmax) cmax = x.count; });
  var other = cats.filter(function (x) { return x.code === 'other'; });
  cats = cats.filter(function (x) { return x.code !== 'other'; }).concat(other);
  var kpi = function (id, icon, label, n, sub, cls) {
    return '<div class="b6-bc link' + (cls ? ' ' + cls : '') + '" data-go="' + id + '" role="button" tabindex="0"><div class="b6-bc-l">' + icon + label + '</div><div class="b6-bc-n">' + nfmt(n) + '</div><div class="b6-bc-s">' + sub + '</div></div>';
  };
  c.innerHTML =
    '<div class="b6-head"><div>' +
      '<div class="b6-title">Регистър на хранителните добавки</div>' +
      '<div class="b6-sub">' + (s.last_update ? 'Данни, качени на <b>' + esc(s.last_update) + '</b>. ' : '') + 'Обобщение на наличните данни. Търсенето е в раздел „Продукти“, фирмите — в „Производители“ и „Търговци“.</div>' +
    '</div></div>' +
    '<div class="b6-kpis six">' +
      kpi('products', I.layers, 'Продукти в регистъра', s.total, 'активни записи') +
      kpi('flagged', I.warn, 'За проверка', s.flagged, 'с автоматична бележка', 'tint') +
      kpi('deleted', I.x, 'Заличени', s.deleted, 'липсват в последното качване') +
      kpi('producers', I.factory, 'Производители от България', s.producers_bg, (s.producers > s.producers_bg ? 'още ' + nfmt(s.producers - s.producers_bg) + ' чуждестранни' : 'по име и адрес')) +
      kpi('traders', I.store, 'Търговци от България', s.traders_bg, (s.traders > s.traders_bg ? 'още ' + nfmt(s.traders - s.traders_bg) + ' чуждестранни' : 'по име и адрес')) +
      kpi('recent', I.bolt, 'Уведомления за 12 месеца', s.recent12, esc(period)) +
    '</div>' +
    '<div class="b6-ov">' +
      '<div class="b6-bm"><div class="b6-bm-l">' + I.bolt + 'Уведомления по месеци</div>' +
        '<div class="b6-bm-n">' + nfmt(thisM) + '</div>' +
        '<div class="b6-bm-d">' + plural(thisM, 'уведомление', 'уведомления') + ' този месец · ' + nfmt(s.recent12) + ' за ' + esc(period) + '</div>' +
        '<div class="b6-chart" role="img" aria-label="Уведомления по месеци: ' + esc(srText.join('; ')) + '">' + bars + '</div><div class="b6-chart-x">' + labels + '</div>' +
      '</div>' +
      '<div class="b6-ovc"><div class="b6-sec-l">Категории (автоматично по наименование и състав)</div>' +
        cats.map(function (ct) {
          var w = ct.code === 'other' ? 0 : Math.max(2, Math.round(100 * ct.count / cmax));
          return '<a href="#" class="b6-ovcat' + (ct.code === 'other' ? ' muted' : '') + '" data-cat="' + esc(ct.code) + '"><span class="n">' + esc(ct.code === 'other' ? 'Без определена категория' : ct.label) + '</span><span class="bar"><i style="width:' + w + '%"></i></span><span class="c">' + nfmt(ct.count) + '</span></a>';
        }).join('') +
      '</div>' +
    '</div>';
  $$('[data-go]', c).forEach(function (el) {
    var go = function () {
      var id = el.getAttribute('data-go');
      if (id === 'products') gotoProducts({});
      else if (id === 'flagged') gotoProducts({ status: 'flagged' });
      else if (id === 'deleted') gotoProducts({ status: 'deleted' });
      else if (id === 'recent') gotoProducts({ period: '12m' });
      else setTab(id);
    };
    el.addEventListener('click', go);
    el.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); go(); } });
  });
  $$('.b6-ovcat', c).forEach(function (a) { a.addEventListener('click', function (e) { e.preventDefault(); gotoProducts({ cats: [a.getAttribute('data-cat')] }); }); });
}

/* ===== Продукти ===== */
var STATUS_OPTS = [['', 'Активни в регистъра'], ['flagged', 'С бележки за проверка'], ['deleted', 'Заличени (махнати от регистъра)']];
var RT_OPTS = [['', 'Всички рег. номера'], ['П', 'Започващи с „П“'], ['Т', 'Започващи с „Т“']];
function periodOpts() {
  var out = [['', 'Целият период'], ['12m', 'Последните 12 месеца (по дата на уведомление)']];
  (state.stats ? state.stats.years : []).forEach(function (y) { out.push([String(y), 'Година ' + y + ' (по рег. №)']); });
  return out;
}
function optLabel(opts, v) { for (var i = 0; i < opts.length; i++) if (opts[i][0] === String(v)) return opts[i][1]; return String(v); }
function catsList() {
  var cats = state.stats ? state.stats.cats.filter(function (x) { return x.count > 0; }) : [];
  var other = cats.filter(function (x) { return x.code === 'other'; });
  return { main: cats.filter(function (x) { return x.code !== 'other'; }), other: other[0] || null };
}
/* Кой обект редактират контролите: на телефон — чернова до „Приложи“, иначе директно филтрите */
function fsrc() {
  if (!isMobile()) return state.f;
  if (!state.draft) state.draft = JSON.parse(JSON.stringify(state.f));
  return state.draft;
}
function activeCount(f) {
  var n = f.cats.length;
  ['status', 'period', 'obl', 'rt'].forEach(function (k) { if (f[k]) n++; });
  if (f.producer) n++; if (f.trader) n++; if (f.brand) n++; if (f.own) n++;
  return n;
}
function applyFilters() {
  state.page = 1; state.openReg = null;
  loadProducts(false); renderActive(); renderFilterPanel();
  var b = $('#b6-fbtn'); if (b) b.innerHTML = I.filter + 'Филтри' + (activeCount(state.f) ? ' · ' + activeCount(state.f) : '');
}
function onFilterChange() {
  if (isMobile()) { renderFilterPanel(); return; }
  applyFilters();
}

function renderProducts(c) {
  var f = state.f;
  c.innerHTML =
    '<div class="b6-head"><div>' +
      '<div class="b6-title">Продукти</div>' +
      '<div class="b6-sub">Търси по наименование, съставка, фирма или регистрационен номер (кирилица и латиница са взаимозаменяеми). Отвори продукт за наличните данни, състава и бележките за проверка.</div>' +
    '</div><div class="b6-actions"><button class="b6-btn" id="b6-export">' + I.dl + '<span>Изтегли CSV</span></button></div></div>' +
    '<div class="b6-ptool">' +
      '<div class="b6-fq">' + I.search + '<input id="b6-q" type="text" placeholder="Име на продукт, съставка, фирма или рег. №" aria-label="Търсене в регистъра" value="' + esc(f.q) + '" autocomplete="off"><button class="clr' + (f.q ? ' show' : '') + '" id="b6-qclr" aria-label="Изчисти търсенето">' + I.x + '</button></div>' +
      '<button class="b6-btn b6-fbtn" id="b6-fbtn" aria-controls="b6-fpanel" aria-expanded="false">' + I.filter + 'Филтри' + (activeCount(f) ? ' · ' + activeCount(f) : '') + '</button>' +
    '</div>' +
    '<div class="b6-fpanel" id="b6-fpanel"></div>' +
    '<div class="b6-active" id="b6-active"></div>' +
    '<div class="b6-legend">Категориите са определени автоматично по наименованието и състава. Бележките за проверка са автоматични съвпадения по думи в наличните данни, не становище на БАБХ.</div>' +
    '<div id="b6-list"></div>';

  var qi = $('#b6-q'), qT = null;
  qi.addEventListener('input', function (e) {
    clearTimeout(qT);
    var v = e.target.value;
    $('#b6-qclr').classList.toggle('show', !!v);
    var tq = $('#b6-topq'); if (tq && tq.value !== v) tq.value = v;
    qT = setTimeout(function () { state.f.q = v; state.page = 1; loadProducts(false); }, 350);
  });
  $('#b6-qclr').addEventListener('click', function () { state.f.q = ''; state.page = 1; qi.value = ''; this.classList.remove('show'); var tq = $('#b6-topq'); if (tq) tq.value = ''; loadProducts(false); });
  $('#b6-fbtn').addEventListener('click', function () { state.fpanel = !state.fpanel; if (state.fpanel) state.draft = null; renderFilterPanel(); });
  $('#b6-export').addEventListener('click', function () {
    if (state.total > 5000 && !confirm('Ще се изтеглят първите 5000 резултата според избраното подреждане. Стесни търсенето, за да включиш всички нужни записи. Да продължим ли?')) return;
    var p = filterParams(state.f), parts = [];
    Object.keys(p).forEach(function (k) {
      var v = p[k];
      if (v === null || v === undefined || v === '' || v === false) return;
      parts.push(encodeURIComponent(k) + '=' + encodeURIComponent(v === true ? 1 : v));
    });
    window.location.href = restUrl('/export', parts.length ? '?' + parts.join('&') : '');
  });
  renderFilterPanel();
  renderActive();
  renderList(true);
}

function renderFilterPanel() {
  var panel = $('#b6-fpanel');
  if (!panel) return;
  var mobile = isMobile(), f = fsrc();
  var open = !mobile || state.fpanel;
  panel.classList.toggle('sheet', mobile);
  panel.classList.toggle('open', open);
  document.body.classList.toggle('b6-noscroll', mobile && open);
  var btn = $('#b6-fbtn'); if (btn) btn.setAttribute('aria-expanded', open ? 'true' : 'false');
  if (!open) { panel.innerHTML = ''; return; }

  var sel = function (id, label, opts, val) {
    return '<label class="b6-fld"><span class="b6-fld-l">' + label + '</span><select class="b6-sel" data-fk="' + id + '">' +
      opts.map(function (o) { return '<option value="' + esc(o[0]) + '"' + (String(val) === o[0] ? ' selected' : '') + '>' + esc(o[1]) + '</option>'; }).join('') + '</select></label>';
  };
  var obls = [['', 'Всички области (по рег. №)']].concat((state.stats ? state.stats.oblasti : []).map(function (o) { return [o, o]; }));
  var cl = catsList();
  var selectedBeyond = f.cats.some(function (code) { var idx = cl.main.map(function (x) { return x.code; }).indexOf(code); return idx >= 8; });
  var showAll = state.catsAll || selectedBeyond || cl.main.length <= 9;
  var visible = showAll ? cl.main : cl.main.slice(0, 8);
  var catBox = function (ct) {
    var on = f.cats.indexOf(ct.code) !== -1;
    return '<label class="b6-ck' + (on ? ' on' : '') + '"><input type="checkbox" data-cat="' + esc(ct.code) + '"' + (on ? ' checked' : '') + '><span class="b6-ck-l">' + esc(ct.label) + '</span><span class="b6-ck-c">' + nfmt(ct.count) + '</span></label>';
  };
  var catsHTML = '<div class="b6-fcats"><div class="b6-fld-l">Категории <span class="b6-fld-h">' + (f.cats.length ? 'избрани ' + f.cats.length + ' · продукти от поне една от тях' : 'избери една или няколко') + '</span></div>' +
    '<div class="b6-cks">' + visible.map(catBox).join('') + '</div>' +
    (cl.main.length > visible.length ? '<button type="button" class="b6-link" id="b6-catsall">Покажи всички категории (' + cl.main.length + ')</button>' : (state.catsAll && cl.main.length > 9 ? '<button type="button" class="b6-link" id="b6-catsall">Скрий част от категориите</button>' : '')) +
    (cl.other ? '<div class="b6-cks other">' + catBox({ code: 'other', label: 'Без определена категория', count: cl.other.count }) + '</div>' : '') +
    '</div>';
  panel.innerHTML =
    (mobile ? '<div class="b6-sheet-h"><b>Филтри</b><button type="button" class="b6-iconbtn" id="b6-fclose" aria-label="Затвори">' + I.x + '</button></div>' : '') +
    '<div class="b6-fgrid">' +
      sel('status', 'Статус', STATUS_OPTS, f.status) +
      sel('period', 'Период', periodOpts(), f.period) +
      sel('obl', 'Област', obls, f.obl) +
      sel('rt', 'Рег. номер', RT_OPTS, f.rt) +
    '</div>' +
    catsHTML +
    (mobile ? '<div class="b6-sheet-f"><button type="button" class="b6-btn" id="b6-fdraftclear">Изчисти</button><button type="button" class="b6-btn primary" id="b6-fapply">Приложи филтрите' + (activeCount(f) ? ' · ' + activeCount(f) : '') + '</button></div>' : '');

  $$('select[data-fk]', panel).forEach(function (s) {
    s.addEventListener('change', function () { fsrc()[s.getAttribute('data-fk')] = s.value; onFilterChange(); });
  });
  $$('input[data-cat]', panel).forEach(function (cb) {
    cb.addEventListener('change', function () {
      var src = fsrc(), code = cb.getAttribute('data-cat'), i = src.cats.indexOf(code);
      if (cb.checked && i === -1) src.cats.push(code); else if (!cb.checked && i !== -1) src.cats.splice(i, 1);
      onFilterChange();
    });
  });
  var all = $('#b6-catsall'); if (all) all.addEventListener('click', function () { state.catsAll = !state.catsAll; renderFilterPanel(); });
  var cls = $('#b6-fclose'); if (cls) cls.addEventListener('click', function () { state.fpanel = false; state.draft = null; renderFilterPanel(); });
  var ap = $('#b6-fapply'); if (ap) ap.addEventListener('click', function () { if (state.draft) state.f = state.draft; state.draft = null; state.fpanel = false; applyFilters(); });
  var dc = $('#b6-fdraftclear'); if (dc) dc.addEventListener('click', function () { var q = fsrc().q; state.draft = newFilters(); state.draft.q = q; state.draft.sort = state.f.sort; renderFilterPanel(); });
}

/* Ред с активните филтри: всеки се маха с ×, плюс „Изчисти филтрите“ */
function renderActive() {
  var el = $('#b6-active');
  if (!el) return;
  var f = state.f, chips = [];
  var chip = function (key, icon, text, val) { chips.push('<button type="button" class="b6-chip on" data-rm="' + key + '"' + (val ? ' data-val="' + esc(val) + '"' : '') + ' title="Премахни филтъра">' + (icon || '') + esc(text) + I.x + '</button>'); };
  if (f.status) chip('status', I.warn, optLabel(STATUS_OPTS, f.status));
  if (f.period) chip('period', I.bolt, optLabel(periodOpts(), f.period).replace(/ \(.*\)$/, ''));
  if (f.obl) chip('obl', null, f.obl);
  if (f.rt) chip('rt', null, 'Рег. № с „' + f.rt + '“');
  f.cats.forEach(function (code) { chip('cat', null, code === 'other' ? 'Без определена категория' : catLabel(code), code); });
  if (f.producer) chip('producer', I.factory, f.producerName || 'Производител');
  if (f.trader) chip('trader', I.store, f.traderName || 'Търговец');
  if (f.own) chip('own', null, f.producer ? 'Без посочен търговец' : 'Без посочен производител');
  if (f.brand) chip('brand', I.layers, 'Марка: ' + f.brand);
  if (!chips.length) { el.innerHTML = ''; el.classList.remove('has'); return; }
  el.classList.add('has');
  el.innerHTML = '<span class="b6-active-l">Филтри:</span>' + chips.join('') + '<button type="button" class="b6-link" id="b6-fclear">Изчисти филтрите</button>';
  $$('[data-rm]', el).forEach(function (b) {
    b.addEventListener('click', function () {
      var k = b.getAttribute('data-rm');
      if (k === 'cat') { var i = f.cats.indexOf(b.getAttribute('data-val')); if (i !== -1) f.cats.splice(i, 1); }
      else if (k === 'producer' || k === 'trader') { f[k] = null; f[k + 'Name'] = ''; if (!f.producer && !f.trader) f.own = false; }
      else if (k === 'own') f.own = false;
      else if (k === 'brand') f.brand = null;
      else f[k] = '';
      state.draft = null; applyFilters();
    });
  });
  $('#b6-fclear').addEventListener('click', function () { var q = f.q, sort = f.sort; state.f = newFilters(); state.f.q = q; state.f.sort = sort; state.draft = null; state.catsAll = false; applyFilters(); });
}

function sortSelectHTML(val) {
  var opts = [['rel', 'По съвпадение'], ['new', 'По рег. №: най-нови'], ['date', 'По дата на уведомление'], ['old', 'По рег. №: най-стари'], ['name', 'По име: А–Я'], ['flagged', 'С най-много бележки']];
  return '<label class="b6-sortl">Подреди: <select class="b6-sel" id="b6-sort" aria-label="Подреждане">' + opts.map(function (o) { return '<option value="' + o[0] + '"' + (val === o[0] ? ' selected' : '') + '>' + o[1] + '</option>'; }).join('') + '</select></label>';
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
  var f = state.f;
  var meta = '<div class="b6-meta"><span><b>' + nfmt(state.total) + '</b> ' + plural(state.total, 'резултат', 'резултата') + (f.q ? ' за „' + esc(f.q) + '“' : '') + (state.items.length && state.items.length < state.total ? ' · показани ' + nfmt(state.items.length) : '') + '</span>' + sortSelectHTML(f.sort) + '</div>';
  if (!state.items.length) {
    var noData = state.stats && state.stats.total === 0;
    c.innerHTML = meta + (noData
      ? '<div class="b6-empty">' + I.empty + '<div class="b6-empty-t">Все още няма качени данни за справка.</div></div>'
      : '<div class="b6-empty">' + I.empty + '<div class="b6-empty-t">Няма резултати по тези критерии.</div><div>Провери изписването или премахни част от филтрите.</div></div>');
    bindSort(c);
    return;
  }
  var deepMiss = state.deepReg && !state.items.some(function (p) { return p.reg === state.deepReg; });
  var html = (deepMiss ? '<div class="b6-load-err" role="alert">Не е намерен запис с този регистрационен номер. Потърси продукта по име или фирма.</div>' : '') +
    meta + '<div class="b6-cards">' + state.items.map(function (p) { return cardHTML(p, state.openReg === p.reg); }).join('') + '</div>';
  if (state.items.length < state.total) {
    var left = state.total - state.items.length;
    html += '<div class="b6-more-wrap"><button class="b6-more" id="b6-more">Покажи още ' + Math.min(state.per, left) + '</button><div class="b6-left">' + (left === 1 ? 'Остава 1 резултат' : 'Остават ' + nfmt(left) + ' резултата') + '</div></div>';
  }
  c.innerHTML = html;
  bindSort(c);
  bindCards(c, function (reg) { state.openReg = state.openReg === reg ? null : reg; renderList(false); return state.openReg === reg; });
  var more = $('#b6-more');
  if (more) more.addEventListener('click', function () { more.disabled = true; more.textContent = 'Зареждане…'; state.page++; loadProducts(true); });
}
function bindSort(c) {
  var s = $('#b6-sort', c);
  if (s) s.addEventListener('change', function (e) { state.f.sort = e.target.value; state.page = 1; loadProducts(false); });
}
/* Общи обработчици за картите (главен списък и списъкът в профила на фирма) */
function bindCards(c, toggle) {
  $$('.b6-card', c).forEach(function (card) {
    card.addEventListener('keydown', function (e) { if ((e.key === 'Enter' || e.key === ' ') && e.target === card) { e.preventDefault(); card.click(); } });
    card.addEventListener('click', function (e) {
      if (e.target.closest('.b6-act') || e.target.closest('.b6-ing') || e.target.closest('.b6-orig') || e.target.closest('.b6-plink')) return;
      var reg = card.getAttribute('data-reg');
      if (toggle(reg)) {
        var el = $('.b6-card[data-reg="' + CSS.escape(reg) + '"]');
        if (el) setTimeout(function () { el.scrollIntoView({ behavior: 'smooth', block: 'nearest' }); }, 60);
      }
    });
  });
  $$('.b6-ing[data-ing]', c).forEach(function (el) {
    el.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); el.click(); } });
    el.addEventListener('click', function (e) {
      e.stopPropagation();
      gotoProducts({ q: el.getAttribute('data-ing') });
      var tq = $('#b6-topq'); if (tq) tq.value = state.f.q;
    });
  });
  $$('[data-share]', c).forEach(function (b) {
    b.addEventListener('click', function (e) {
      e.stopPropagation();
      var url = location.origin + location.pathname + '#p=' + encodeURIComponent(b.getAttribute('data-share'));
      var done = function () { var old = b.innerHTML; b.innerHTML = 'Линкът е копиран.'; b.setAttribute('aria-live', 'polite'); setTimeout(function () { b.innerHTML = old; }, 1800); };
      if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(url).then(done, function () { prompt('Копирай този линк:', url); });
      else prompt('Копирай този линк:', url);
    });
  });
}

function cardHTML(p, open) {
  var cat = p.cat || 'other';
  var col = CAT_COLORS[cat] || '#9AA0AB';
  var flags = (p.f || []).slice(0, 2).map(function (f) {
    return '<span class="b6-tag warn" title="Автоматична бележка: намерено „' + esc(f.term || f.label) + '“ в ' + esc(f.field_label || 'наличните данни') + '">' + I.warn + 'Бележка: ' + esc(f.label) + '</span>';
  }).join('');
  var traderFirm = p.tr && p.tk === 'firm';
  var producerFirm = p.p && p.pk === 'firm';
  var firm = traderFirm ? firstPart(p.tr) : (producerFirm ? firstPart(p.p) : firstPart(p.p || ''));
  var firmIcon = traderFirm ? I.store : (producerFirm ? I.factory : I.flag);
  var gone = p.x ? '<span class="b6-tag danger" title="Записът липсва в последното качване на регистъра">Заличен</span>' : '';
  return '<div class="b6-card' + ((p.f || []).length ? ' flagged' : '') + (open ? ' open' : '') + (p.x ? ' gone' : '') + '" data-reg="' + esc(p.reg) + '" role="button" tabindex="0" aria-expanded="' + (open ? 'true' : 'false') + '" aria-label="' + (open ? 'Скрий подробностите за ' : 'Покажи подробности за ') + esc(p.n) + '">' +
    '<div class="b6-ch">' +
      '<div class="b6-tile" style="background:' + col + '1C;color:' + col + '" title="' + esc(catLabel(cat, p.catl)) + '" aria-label="Автоматична категория: ' + esc(catLabel(cat, p.catl)) + '">' + (CAT_ICONS[cat] || CAT_ICONS.other) + '</div>' +
      '<div style="min-width:0"><div class="b6-cn">' + esc(p.n) + '</div>' +
        '<div class="b6-cm"><span class="b6-reg">' + esc(p.reg) + '</span>' +
        (firm ? '<span class="b6-firm">' + firmIcon + esc(firm) + '</span>' : '') +
        gone + flags + '</div>' +
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
  if (p.tr && p.tk === 'firm') rows += '<span class="l">Търговец</span><span class="v">' + partyLink('t', p.tn, firstPart(p.tr)) + (!p.ti && restPart(p.tr) ? '<div style="font-size:11.5px;color:var(--t3)">' + esc(restPart(p.tr)) + '</div>' : '') + '</span>';
  if (p.nd) rows += '<span class="l">Дата на уведомление</span><span class="v">' + fmtDate(p.nd) + '</span>';
  if (p.ld) rows += '<span class="l">Дата на пускане на пазара</span><span class="v">' + fmtDate(p.ld) + '</span>';
  if (p.o) rows += '<span class="l">Област според рег. №</span><span class="v">' + esc(p.o) + '</span>';
  if (p.st) rows += '<span class="l">Данни за съхранение</span><span class="v" style="font-size:12.5px">' + esc(p.st) + '</span>';
  if (p.x) rows += '<span class="l">Статус</span><span class="v" style="color:var(--red);font-weight:600">Заличен: записът липсва в последното качване на регистъра.</span>';
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
  return '<div class="b6-ptool">' +
      '<div class="b6-fq">' + I.search + '<input id="b6-pq-' + kind + '" type="text" placeholder="' + (isP ? 'Търси производител…' : 'Търси търговец…') + '" aria-label="' + (isP ? 'Търсене на производител' : 'Търсене на търговец') + '" value="' + esc(ps.q) + '" autocomplete="off"><button class="clr' + (ps.q ? ' show' : '') + '" id="b6-pqclr-' + kind + '" aria-label="Изчисти търсенето">' + I.x + '</button></div>' +
      '<button class="b6-chip' + (ps.all ? '' : ' on') + '" id="b6-pbg-' + kind + '" aria-pressed="' + (ps.all ? 'false' : 'true') + '" title="Определено по наименованието и адреса в регистъра">' + I.flag + (ps.all ? 'Всички, вкл. чуждестранни' : 'Само с българска регистрация') + '</button>' +
      '<label class="b6-sortl">Подреди: <select class="b6-sel" id="b6-psort-' + kind + '" aria-label="Подреждане">' +
        '<option value="products"' + (ps.sort === 'products' ? ' selected' : '') + '>Най-много продукти</option>' +
        '<option value="partners"' + (ps.sort === 'partners' ? ' selected' : '') + '>Най-много ' + (isP ? 'клиенти' : 'доставчици') + '</option>' +
        '<option value="flagged"' + (ps.sort === 'flagged' ? ' selected' : '') + '>Най-много за проверка</option>' +
        '<option value="newest"' + (ps.sort === 'newest' ? ' selected' : '') + '>Най-нови регистрации</option>' +
        '<option value="name"' + (ps.sort === 'name' ? ' selected' : '') + '>По име</option>' +
      '</select></label>' +
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
function ensureParties(kind) {
  var ps = state.plist[kind];
  if (ps.loaded) { renderPartyList(kind, false); return; }
  if (ps.loading) { renderPartyList(kind, true); return; }
  ps.page = 1; loadParties(kind, false);
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
  ps.kind = kind; ps.open = norm; ps.detail = null; ps.view = 'partners';
  ps.prod = { items: [], total: 0, page: 1, loading: false, openReg: null };
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
  window.scrollTo({ top: 0, behavior: 'smooth' });
}
function renderParties(c, kind) {
  var ps = state.party;
  if (ps.open && ps.kind === kind) { renderPartyDetail(c); return; }
  var isP = kind === 'p';
  c.innerHTML =
    '<div class="b6-head"><div>' +
      '<div class="b6-title">' + (isP ? 'Производители' : 'Търговци') + '</div>' +
      '<div class="b6-sub">' + (isP ? 'Отвори производител, за да видиш клиентите му (фирмите, за които произвежда), марките и всичките му продукти.' : 'Отвори търговец, за да видиш марките, които продава, производителите зад тях и всички продукти, регистрирани с него като търговец.') + ' По подразбиране са показани само дружества с българска регистрация и седалище (определено по наименованието и адреса в регистъра). Фирмите са групирани автоматично по името.</div>' +
    '</div><div class="b6-actions"><button class="b6-btn" id="b6-pexport">' + I.dl + '<span>CSV на списъка</span></button></div></div>' +
    partyToolbarHTML(kind) +
    '<div id="b6-plist-' + kind + '"></div>';
  bindPartyToolbar(kind);
  $('#b6-pexport').addEventListener('click', function () {
    var pl = state.plist[kind];
    var rows = [[isP ? 'Производител' : 'Търговец', 'Българска регистрация (автоматично, по име и адрес)', 'Продукти', isP ? 'Клиенти' : 'Доставчици', 'С бележки за проверка', 'От година', 'До година']];
    pl.items.forEach(function (it) { rows.push([it.name, it.bg ? 'да' : '', it.products, it.partners, it.flagged, it.y1 || '', it.y2 || '']); });
    downloadCSV(rows, (isP ? 'proizvoditeli' : 'targovci') + '.csv');
  });
  ensureParties(kind);
}
function yearsLabel(y1, y2) { if (!y1 && !y2) return '—'; if (!y1) return String(y2); if (!y2 || y1 === y2) return String(y1); return y1 + '–' + y2; }
function renderPartyList(kind, skeleton) {
  var ps = state.plist[kind], c = $('#b6-plist-' + kind);
  if (!c) return;
  var isP = kind === 'p';
  if (skeleton && ps.loading && !ps.items.length) {
    var sk = ''; for (var i = 0; i < 6; i++) sk += '<div class="b6-sk"><div class="b6-sk-line" style="width:' + (30 + i * 9) % 60 + '%"></div></div>';
    c.innerHTML = sk; return;
  }
  if (!ps.items.length) {
    c.innerHTML = '<div class="b6-empty">' + I.empty + '<div class="b6-empty-t">Няма съвпадения</div><div>' + (ps.all ? 'Опитай по-кратка дума.' : 'Опитай по-кратка дума или включи и чуждестранните фирми.') + '</div></div>';
    return;
  }
  var html = '<div class="b6-meta"><span><b>' + nfmt(ps.total) + '</b> ' + plural(ps.total, 'фирма', 'фирми') + (ps.all ? ' (вкл. чуждестранни)' : ' с българска регистрация') + (ps.q ? ' за „' + esc(ps.q) + '“' : '') + (ps.allN ? ' · общо в регистъра ' + nfmt(ps.allN) + ' (' + nfmt(ps.allBg) + ' български)' : '') + '</span><span>Показани ' + ps.items.length + '</span></div>' +
    '<div class="b6-tbl-wrap"><table class="b6-tbl"><thead><tr><th>#</th><th>Фирма</th><th class="num">Продукти</th><th class="num">' + (isP ? 'Клиенти' : 'Доставчици') + '</th><th class="num" title="Продукти с автоматична бележка за проверка">За проверка</th><th class="num" title="Години от регистрационните номера">Години</th></tr></thead><tbody>';
  ps.items.forEach(function (it, i) {
    html += '<tr class="b6-prow" data-party="' + esc(kind) + '|' + esc(it.norm) + '" tabindex="0" role="link" aria-label="Профил на ' + esc(it.name) + '">' +
      '<td class="idx">' + (i + 1) + '</td>' +
      '<td><div class="b6-pname">' + esc(it.name) + (it.bg ? ' <span class="b6-tag green" title="Българска регистрация — определено по наименованието и адреса в регистъра">' + I.flag + 'БГ</span>' : '') + '</div></td>' +
      '<td class="num"><b>' + nfmt(it.products) + '</b></td>' +
      '<td class="num">' + nfmt(it.partners) + '</td>' +
      '<td class="num">' + (it.flagged ? '<span class="b6-tag warn">' + nfmt(it.flagged) + '</span>' : '<span style="color:var(--t4)">0</span>') + '</td>' +
      '<td class="num" style="color:var(--t3)">' + yearsLabel(it.y1, it.y2) + '</td>' +
    '</tr>';
  });
  html += '</tbody></table></div>';
  if (ps.items.length < ps.total) html += '<div class="b6-more-wrap" style="margin-top:10px"><button class="b6-more" id="b6-pmore-' + kind + '">Покажи още ' + Math.min(ps.per, ps.total - ps.items.length) + '</button><div class="b6-left">' + nfmt(ps.total - ps.items.length) + ' остават</div></div>';
  c.innerHTML = html;
  var more = $('#b6-pmore-' + kind);
  if (more) more.addEventListener('click', function () { more.disabled = true; more.textContent = 'Зареждане…'; ps.page++; loadParties(kind, true); });
  $$('.b6-prow', c).forEach(function (tr) {
    tr.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); tr.click(); } });
  });
}

/* Профил: KPI + изгледи „Клиенти/Доставчици“ · „Марки“ · „Продукти“ */
function renderPartyDetail(c) {
  var ps = state.party, d = ps.detail, isP = ps.kind === 'p';
  var back = '<button class="b6-back" id="b6-pback">' + I.chev + 'Всички ' + (isP ? 'производители' : 'търговци') + '</button>';
  if (!d) {
    c.innerHTML = back + '<div class="b6-sk" style="margin-top:14px"><div class="b6-sk-line" style="width:40%"></div><div class="b6-sk-line" style="width:70%;margin-top:10px"></div></div>';
    $('#b6-pback').addEventListener('click', function () { ps.open = null; ps.detail = null; render(); });
    return;
  }
  var partnersLbl = isP ? 'Клиенти' : 'Доставчици';
  var self = {}; self[isP ? 'producer' : 'trader'] = d.norm; self[isP ? 'producerName' : 'traderName'] = d.name;
  var views = [['partners', partnersLbl + ' (' + nfmt(d.partners.length) + ')'], ['brands', 'Марки (' + nfmt((d.brands || []).length) + ')'], ['products', 'Продукти (' + nfmt(d.products) + ')']];
  c.innerHTML = back +
    '<div class="b6-head" style="margin-top:12px"><div>' +
      '<div class="b6-title" style="font-size:24px">' + (isP ? I.factory : I.store) + ' ' + esc(d.name) + (d.bg ? ' <span class="b6-tag green" title="Българска регистрация — определено по наименованието и адреса в регистъра">' + I.flag + 'БГ</span>' : ' <span class="b6-tag" style="background:var(--bg-soft);color:var(--t3)" title="Не е разпозната българска регистрация по наименованието и адреса">чуждестранна</span>') + '</div>' +
      '<div class="b6-sub">' + (d.full && d.full !== d.name ? esc(d.full) + ' · ' : '') + (isP ? 'производител' : 'търговец') + ' според регистъра' + (d.y1 || d.y2 ? ' · регистрации ' + yearsLabel(d.y1, d.y2) : '') + '</div>' +
    '</div><div class="b6-actions">' +
      '<button class="b6-btn" id="b6-pall">' + I.search + '<span>Отвори в „Продукти“</span></button>' +
      '<button class="b6-btn" id="b6-pcsv">' + I.dl + '<span>CSV ' + partnersLbl.toLowerCase() + '</span></button>' +
    '</div></div>' +
    '<div class="b6-kpis">' +
      '<div class="b6-bc link" data-view="products" role="button" tabindex="0"><div class="b6-bc-l">' + I.layers + 'Продукти</div><div class="b6-bc-n">' + nfmt(d.products) + '</div><div class="b6-bc-s">' + (d.deleted ? nfmt(d.deleted) + ' заличени отделно' : 'в наличните данни') + '</div></div>' +
      '<div class="b6-bc tint link" data-view="partners" role="button" tabindex="0"><div class="b6-bc-l">' + (isP ? I.store : I.factory) + partnersLbl + '</div><div class="b6-bc-n">' + nfmt(d.partners.length) + '</div><div class="b6-bc-s">' + (isP ? 'фирми, за които произвежда' : 'фирми, които произвеждат за него') + '</div></div>' +
      '<div class="b6-bc link" data-view="brands" role="button" tabindex="0"><div class="b6-bc-l">' + I.layers + 'Марки</div><div class="b6-bc-n">' + nfmt((d.brands || []).length) + '</div><div class="b6-bc-s">по първата дума в наименованията</div></div>' +
      '<div class="b6-bc link" data-own="1" role="button" tabindex="0"><div class="b6-bc-l">' + I.flag + (isP ? 'Без посочен търговец' : 'Без посочен производител') + '</div><div class="b6-bc-n">' + nfmt(d.own) + '</div><div class="b6-bc-s">' + (isP ? 'собствена марка или празно поле' : 'или производителят е държава') + '</div></div>' +
      '<div class="b6-bc link" data-flagged="1" role="button" tabindex="0"><div class="b6-bc-l">' + I.warn + 'За проверка</div><div class="b6-bc-n">' + nfmt(d.flagged) + '</div><div class="b6-bc-s">с автоматична бележка</div></div>' +
    '</div>' +
    '<div class="b6-seg" role="tablist">' + views.map(function (v) { return '<button role="tab" class="b6-seg-b' + (ps.view === v[0] ? ' on' : '') + '" data-view="' + v[0] + '" aria-selected="' + (ps.view === v[0] ? 'true' : 'false') + '">' + v[1] + '</button>'; }).join('') + '</div>' +
    '<div id="b6-pview"></div>';
  $('#b6-pback').addEventListener('click', function () { ps.open = null; ps.detail = null; render(); });
  $('#b6-pall').addEventListener('click', function () { gotoProducts(self); });
  $$('[data-view]', c).forEach(function (el) {
    var go = function () { ps.view = el.getAttribute('data-view'); $$('.b6-seg-b', c).forEach(function (b) { var on = b.getAttribute('data-view') === ps.view; b.classList.toggle('on', on); b.setAttribute('aria-selected', on ? 'true' : 'false'); }); renderPartyView(); };
    el.addEventListener('click', go);
    if (el.getAttribute('role') === 'button') el.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); go(); } });
  });
  var own = $('[data-own]', c); own.addEventListener('click', function () { var f = JSON.parse(JSON.stringify(self)); f.own = true; gotoProducts(f); });
  var fl = $('[data-flagged]', c); fl.addEventListener('click', function () { var f = JSON.parse(JSON.stringify(self)); f.status = 'flagged'; gotoProducts(f); });
  $('#b6-pcsv').addEventListener('click', function () {
    var rows = [[isP ? 'Производител' : 'Търговец', isP ? 'Клиент' : 'Доставчик', 'Продукти', 'Дял %', 'С бележки за проверка', 'Първо уведомление', 'Последно уведомление']];
    d.partners.forEach(function (x) { rows.push([d.name, x.name, x.count, x.share, x.flagged, x.first || '', x.last || '']); });
    var fn = d.norm.replace(/[^a-z0-9]+/gi, '-').replace(/^-+|-+$/g, '');
    downloadCSV(rows, (isP ? 'klienti-' : 'dostavchici-') + (fn || d.norm.length) + '.csv');
  });
  renderPartyView();
}
function renderPartyView() {
  var ps = state.party, d = ps.detail, isP = ps.kind === 'p', c = $('#b6-pview');
  if (!c || !d) return;
  var self = {}; self[isP ? 'producer' : 'trader'] = d.norm; self[isP ? 'producerName' : 'traderName'] = d.name;
  if (ps.view === 'partners') {
    var partnerKind = isP ? 't' : 'p', partnersLbl = isP ? 'клиенти' : 'доставчици';
    var maxC = 1; d.partners.forEach(function (x) { if (x.count > maxC) maxC = x.count; });
    var rows = d.partners.map(function (x, i) {
      return '<tr>' +
        '<td class="idx">' + (i + 1) + '</td>' +
        '<td><div class="b6-pname"><a href="#" class="b6-plink" data-party="' + esc(partnerKind) + '|' + esc(x.norm) + '">' + esc(x.name) + '</a></div>' +
          '<div class="b6-share"><i style="width:' + Math.max(2, Math.round(100 * x.count / maxC)) + '%"></i></div></td>' +
        '<td class="num"><a href="#" class="b6-plink num" data-products="' + esc(x.norm) + '" title="Покажи продуктите"><b>' + nfmt(x.count) + '</b></a></td>' +
        '<td class="num" style="color:var(--t3)">' + x.share + '%</td>' +
        '<td class="num">' + (x.flagged ? '<span class="b6-tag warn">' + nfmt(x.flagged) + '</span>' : '<span style="color:var(--t4)">0</span>') + '</td>' +
        '<td class="num" style="color:var(--t3);white-space:nowrap">' + (x.first ? fmtDate(x.first).slice(3) : '—') + (x.last && x.last !== x.first ? ' – ' + fmtDate(x.last).slice(3) : '') + '</td>' +
      '</tr>';
    }).join('');
    c.innerHTML = '<div class="b6-meta"><span><b>' + nfmt(d.partners.length) + '</b> ' + partnersLbl + ' според полето „' + (isP ? 'Търговец' : 'Производител') + '“ в регистъра · клик на числото показва продуктите</span></div>' +
      (d.partners.length
        ? '<div class="b6-tbl-wrap"><table class="b6-tbl"><thead><tr><th>#</th><th>' + (isP ? 'Клиент (търговец)' : 'Производител') + '</th><th class="num">Продукти</th><th class="num">Дял</th><th class="num" title="Продукти с автоматична бележка за проверка">За проверка</th><th class="num">Период</th></tr></thead><tbody>' + rows + '</tbody></table></div>'
        : '<div class="b6-empty">' + I.empty + '<div class="b6-empty-t">Няма ' + partnersLbl + '</div><div>Всички продукти са без насрещна фирма (собствена марка / без търговец).</div></div>');
    $$('[data-products]', c).forEach(function (a) {
      a.addEventListener('click', function (e) {
        e.preventDefault(); e.stopPropagation();
        var norm = a.getAttribute('data-products'), p = null;
        d.partners.forEach(function (x) { if (x.norm === norm) p = x; });
        var f = JSON.parse(JSON.stringify(self));
        f[isP ? 'trader' : 'producer'] = norm; f[isP ? 'traderName' : 'producerName'] = p ? p.name : '';
        gotoProducts(f);
      });
    });
    return;
  }
  if (ps.view === 'brands') {
    var b = d.brands || [];
    var cpLbl = isP ? 'Най-често с търговец' : 'Най-често от производител';
    c.innerHTML = '<div class="b6-meta"><span><b>' + nfmt(b.length) + '</b> ' + (b.length === 1 ? 'марка' : 'марки') + ' по първата дума в наименованията на продуктите · клик на числото показва продуктите</span></div>' +
      (b.length
        ? '<div class="b6-tbl-wrap"><table class="b6-tbl"><thead><tr><th>#</th><th>Марка</th><th class="num">Продукти</th><th>' + cpLbl + '</th></tr></thead><tbody>' +
          b.map(function (x, i) {
            return '<tr><td class="idx">' + (i + 1) + '</td>' +
              '<td><div class="b6-pname">' + esc(x.token.toUpperCase()) + '</div></td>' +
              '<td class="num"><a href="#" class="b6-plink num" data-brand="' + esc(x.token) + '" title="Покажи продуктите"><b>' + nfmt(x.count) + '</b></a></td>' +
              '<td>' + (x.match ? '<a href="#" class="b6-plink" data-party="' + esc(x.match.kind) + '|' + esc(x.match.norm) + '">' + esc(x.match.name) + '</a> <span style="color:var(--t4);font-size:11px">(' + nfmt(x.match.count) + ')</span>' : '<span style="color:var(--t4)">—</span>') + '</td></tr>';
          }).join('') + '</tbody></table></div>' +
          '<div class="b6-flagnote">Марките са определени автоматично по първата дума в наименованието на продукта. Това е предположение, не поле от регистъра на БАБХ.</div>'
        : '<div class="b6-empty">' + I.empty + '<div class="b6-empty-t">Няма разпознати марки</div></div>');
    $$('[data-brand]', c).forEach(function (a) {
      a.addEventListener('click', function (e) { e.preventDefault(); e.stopPropagation(); var f = JSON.parse(JSON.stringify(self)); f.brand = a.getAttribute('data-brand'); gotoProducts(f); });
    });
    return;
  }
  /* products: списък в профила */
  renderPartyProducts(true);
  if (!ps.prod.items.length && !ps.prod.loading) loadPartyProducts(false);
}
function loadPartyProducts(append) {
  var ps = state.party, pr = ps.prod, isP = ps.kind === 'p';
  pr.loading = true;
  var p = { page: pr.page, per: 20, sort: 'new' };
  p[isP ? 'producer' : 'trader'] = ps.open;
  return api('/products', p).then(function (r) {
    pr.total = r.total; pr.items = append ? pr.items.concat(r.items) : r.items; pr.loading = false;
    renderPartyProducts(false);
  }).catch(function (e) {
    pr.loading = false;
    var c = $('#b6-pview'); if (c) c.innerHTML = '<div class="b6-empty">' + I.empty + '<div class="b6-empty-t">Грешка при зареждане</div><div>' + esc(e.message) + '</div></div>';
  });
}
function renderPartyProducts(skeleton) {
  var ps = state.party, pr = ps.prod, c = $('#b6-pview');
  if (!c) return;
  if (skeleton && !pr.items.length) { c.innerHTML = '<div class="b6-sk"><div class="b6-sk-line" style="width:40%"></div><div class="b6-sk-line" style="width:75%;margin-top:10px"></div></div>'; return; }
  var html = '<div class="b6-meta"><span><b>' + nfmt(pr.total) + '</b> ' + plural(pr.total, 'продукт', 'продукта') + (ps.kind === 'p' ? ' с този производител' : ' с този търговец') + ' · по рег. №, най-новите първи</span><span><a href="#" class="b6-plink" id="b6-pview-all">Отвори с филтри в „Продукти“</a></span></div>' +
    '<div class="b6-cards">' + pr.items.map(function (p) { return cardHTML(p, pr.openReg === p.reg); }).join('') + '</div>';
  if (pr.items.length < pr.total) html += '<div class="b6-more-wrap"><button class="b6-more" id="b6-ppmore">Покажи още ' + Math.min(20, pr.total - pr.items.length) + '</button><div class="b6-left">Остават ' + nfmt(pr.total - pr.items.length) + '</div></div>';
  c.innerHTML = html;
  bindCards(c, function (reg) { pr.openReg = pr.openReg === reg ? null : reg; renderPartyProducts(false); return pr.openReg === reg; });
  var more = $('#b6-ppmore'); if (more) more.addEventListener('click', function () { more.disabled = true; pr.page++; loadPartyProducts(true); });
  $('#b6-pview-all').addEventListener('click', function (e) { e.preventDefault(); var f = {}; f[ps.kind === 'p' ? 'producer' : 'trader'] = ps.open; f[ps.kind === 'p' ? 'producerName' : 'traderName'] = ps.detail ? ps.detail.name : ''; gotoProducts(f); });
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
if (m) { state.tab = 'products'; state.f.q = decodeURIComponent(m[1]); state.deepReg = state.f.q; $('#b6-topq').value = state.f.q;
  $$('.b6-item').forEach(function (b) { b.classList.toggle('on', b.getAttribute('data-tab') === 'products'); });
  $$('.b6-bnav button[data-tab]').forEach(function (b) { b.classList.toggle('on', b.getAttribute('data-tab') === 'products'); }); }
render();
if (state.tab === 'products') loadProducts(false);
loadStats().then(function () {
  if (state.tab === 'overview') render();
  else if (state.tab === 'products') { renderFilterPanel(); renderActive(); }
  if (state.deepReg && state.tab === 'products') {
    loadProducts(false).then(function () {
      var hit = state.items.filter(function (p) { return p.reg === state.deepReg; })[0];
      if (hit) state.openReg = hit.reg;
      renderList(false);
    });
  }
}).catch(function () { render(); });
window.addEventListener('resize', function () { if (state.tab === 'products') { if (!isMobile()) { state.fpanel = false; if (state.draft) { state.draft = null; } } renderFilterPanel(); } });
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
