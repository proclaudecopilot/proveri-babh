/* БАБХ Регистър v6 — публичен фронтенд (vanilla JS, без зависимости) */
(function () {
'use strict';

var CFG = window.BABH6_CFG || { rest: '/wp-json/babh6/v1' };
var root = document.getElementById('babh6-app');
if (!root) return;

var $ = function (s, r) { return (r || root).querySelector(s); };
var $$ = function (s, r) { return Array.prototype.slice.call((r || root).querySelectorAll(s)); };

var state = {
  tab: 'register', q: '', flagged: false, bg: false, recent: false,
  cat: null, year: null, obl: null, sort: 'new',
  page: 1, per: 20, items: [], total: 0, loading: false,
  openReg: null, stats: null, deepReg: null, waitOk: false
};

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
function catLabel(code) {
  if (!state.stats) return code;
  var f = state.stats.cats.filter(function (c) { return c.code === code; });
  return f.length ? f[0].label : code;
}
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
  return fetch(CFG.rest + path + qs, { credentials: 'same-origin' }).then(function (r) {
    if (!r.ok) throw new Error('HTTP ' + r.status);
    return r.json();
  });
}
function currentParams() {
  return {
    q: state.q, flagged: state.flagged, bg: state.bg, recent: state.recent,
    cat: state.cat, year: state.year, obl: state.obl, sort: state.sort
  };
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
var ING_FLAGS = [
  { t: ['берберин', 'berberin', 'берберис'], l: 'warn' }, { t: ['мелатонин', 'melatonin'], l: 'warn' },
  { t: ['йохимб'], l: 'danger' }, { t: ['ефедр', 'ephedr'], l: 'danger' }, { t: ['dmaa', 'dmha'], l: 'danger' },
  { t: ['sarm', 'ostarine', 'lgd', 'rad-140'], l: 'danger' }, { t: ['кратом', 'kratom'], l: 'danger' },
  { t: ['cbd', 'канабидиол'], l: 'warn' }, { t: ['туркестерон', 'turkesterone'], l: 'warn' },
  { t: ['тонгкат', 'tongkat', 'eurycoma'], l: 'danger' }, { t: ['shilajit', 'шиладжит', 'мумио'], l: 'warn' },
  { t: ['ашваганда', 'ashwagandha'], l: 'warn' }, { t: ['синефрин', 'synephrine'], l: 'warn' }
];
function classifyIng(n) {
  var nl = n.toLowerCase();
  for (var i = 0; i < ING_FLAGS.length; i++) {
    for (var j = 0; j < ING_FLAGS[i].t.length; j++) {
      if (nl.indexOf(ING_FLAGS[i].t[j]) !== -1) return ING_FLAGS[i].l;
    }
  }
  return '';
}

/* ===== Shell ===== */
function renderShell() {
  root.innerHTML =
    '<div class="b6-layout">' +
      '<aside class="b6-sb" id="b6-sb">' +
        '<div class="b6-brand"><div class="b6-mark"></div><div><div class="b6-brand-n">БАБХ Регистър</div><div class="b6-brand-t">Хранителни добавки</div></div></div>' +
        '<div class="b6-sb-l">Меню</div>' +
        '<button class="b6-item on" data-tab="register">' + I.search + '<span class="lbl">Регистър</span><span class="count" id="b6-c-total">—</span></button>' +
        '<button class="b6-item alert" data-tab="flagged">' + I.warn + '<span class="lbl">Флагнати</span><span class="count" id="b6-c-flag">—</span></button>' +
        '<button class="b6-item" data-tab="producers">' + I.factory + '<span class="lbl">Производители</span></button>' +
        '<button class="b6-item" data-tab="traders">' + I.store + '<span class="lbl">Търговци</span></button>' +
        '<div class="b6-sb-l">Pro инструменти</div>' +
        '<button class="b6-item" data-tab="novel">' + I.flask + '<span class="lbl">Novel Food</span><span class="pro">PRO</span></button>' +
        '<button class="b6-item" data-tab="inspector">' + I.grid + '<span class="lbl">Инспектор</span><span class="pro">PRO</span></button>' +
        '<button class="b6-item" data-tab="watchlist">' + I.bell + '<span class="lbl">Абонаменти</span><span class="pro">PRO</span></button>' +
        '<div class="b6-sb-foot">Източник: официален регистър на БАБХ. Платформата е с информативен характер.</div>' +
      '</aside>' +
      '<div class="b6-main">' +
        '<div class="b6-top">' +
          '<button class="b6-burger" id="b6-burger" aria-label="Меню">' + I.menu + '</button>' +
          '<div class="b6-topq">' + I.search + '<input id="b6-topq" type="text" placeholder="Бързо търсене в целия регистър…" autocomplete="off"></div>' +
          '<div class="b6-live"><span class="b6-live-dot"></span><span id="b6-live-t">Зарежда…</span></div>' +
        '</div>' +
        '<div class="b6-content" id="b6-content"></div>' +
      '</div>' +
    '</div>' +
    '<div class="b6-scrim" id="b6-scrim"></div>' +
    '<nav class="b6-bnav">' +
      '<button data-tab="register" class="on">' + I.search + '<span>Регистър</span></button>' +
      '<button data-tab="flagged">' + I.warn + '<span>Флагнати</span></button>' +
      '<button data-tab="producers">' + I.factory + '<span>Производ.</span></button>' +
      '<button data-btn="menu">' + I.menu + '<span>Меню</span></button>' +
    '</nav>';

  $$('.b6-item').forEach(function (b) { b.addEventListener('click', function () { setTab(b.getAttribute('data-tab')); }); });
  $$('.b6-bnav button').forEach(function (b) {
    b.addEventListener('click', function () {
      if (b.getAttribute('data-btn') === 'menu') { $('#b6-sb').classList.add('open'); $('#b6-scrim').classList.add('show'); return; }
      setTab(b.getAttribute('data-tab'));
    });
  });
  $('#b6-burger').addEventListener('click', function () { $('#b6-sb').classList.add('open'); $('#b6-scrim').classList.add('show'); });
  $('#b6-scrim').addEventListener('click', closeSidebar);
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeSidebar(); });

  var tqT = null;
  $('#b6-topq').addEventListener('input', function (e) {
    clearTimeout(tqT);
    var v = e.target.value;
    tqT = setTimeout(function () {
      state.q = v; state.page = 1;
      if (state.tab !== 'register' && state.tab !== 'flagged') setTab('register'); else { loadProducts(false); }
    }, 350);
  });
}
function closeSidebar() { $('#b6-sb').classList.remove('open'); $('#b6-scrim').classList.remove('show'); }

function setTab(t) {
  state.tab = t; state.page = 1; state.openReg = null;
  state.flagged = (t === 'flagged');
  closeSidebar();
  $$('.b6-item').forEach(function (b) { b.classList.toggle('on', b.getAttribute('data-tab') === t); });
  $$('.b6-bnav button[data-tab]').forEach(function (b) { b.classList.toggle('on', b.getAttribute('data-tab') === t); });
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
    if (lv) lv.textContent = s.last_update ? 'Актуално: ' + s.last_update : 'Няма данни';
  });
}
function loadProducts(append) {
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
    if (c) c.innerHTML = '<div class="b6-empty">' + I.empty + '<div class="b6-empty-t">Грешка при зареждане</div><div>' + esc(e.message) + ' — опитай да презаредиш страницата.</div></div>';
  });
}

/* ===== Views ===== */
function render() {
  var c = $('#b6-content');
  c.classList.toggle('flag-mode', state.tab === 'flagged');
  switch (state.tab) {
    case 'register':
    case 'flagged': renderRegister(c); break;
    case 'producers': renderSoon(c, 'Производители', 'Пълни профили на фирмите — каталог, клиенти, история, справки за печат и CSV. Идва в следващото обновление на платформата.', 'producers', false); break;
    case 'traders': renderSoon(c, 'Търговци', 'Профили на търговците и вносителите — доставчици, внос по държави, каталози. Идва в следващото обновление на платформата.', 'traders', false); break;
    case 'novel': renderSoon(c, 'Novel Food търсачка', 'Проверка на съставки срещу EU Novel Food Catalogue и EFSA становища, bulk проверка от етикет, PDF доклад с цитати.', 'novel', true); break;
    case 'inspector': renderSoon(c, 'Инспектор', 'Diff между качванията на регистъра: нови регистрации, тихи заличавания, промени в състав, аномалии.', 'inspector', true); break;
    case 'watchlist': renderSoon(c, 'Абонаменти за промени', 'Email известие, когато следена фирма или съставка се появи в нова регистрация. Никой друг не го прави за БАБХ регистъра.', 'watchlist', true); break;
  }
}

function renderRegister(c) {
  var s = state.stats;
  var bento = '';
  if (s) {
    var max = 1, i;
    for (i = 0; i < s.monthly.length; i++) if (s.monthly[i].c > max) max = s.monthly[i].c;
    var names = ['яну', 'фев', 'мар', 'апр', 'май', 'юни', 'юли', 'авг', 'сеп', 'окт', 'ное', 'дек'];
    var bars = '', labels = '';
    for (i = 0; i < s.monthly.length; i++) {
      var mo = s.monthly[i];
      var lbl = names[parseInt(mo.m.slice(5), 10) - 1];
      bars += '<div class="b6-bar' + (i === s.monthly.length - 1 ? ' now' : '') + '" style="height:' + Math.max(7, Math.round(mo.c / max * 100)) + '%" title="' + lbl + ': ' + mo.c + ' нови"></div>';
      labels += '<span>' + lbl + '</span>';
    }
    bento =
      '<div class="b6-bento">' +
        '<div class="b6-bm"><div class="b6-bm-l">' + I.layers + 'Общо продукти в регистъра</div>' +
          '<div class="b6-bm-n">' + nfmt(s.total) + '</div>' +
          '<div class="b6-bm-d">↑ +' + nfmt(s.monthly[s.monthly.length - 1].c) + ' този месец · ' + nfmt(s.recent12) + ' за 12 месеца</div>' +
          '<div class="b6-chart">' + bars + '</div><div class="b6-chart-x">' + labels + '</div>' +
        '</div>' +
        '<div class="b6-side">' +
          '<div class="b6-bc tint"><div class="b6-bc-l">' + I.warn + 'Регулаторни флагове</div><div class="b6-bc-n">' + nfmt(s.flagged) + '</div><div class="b6-bc-s">съставки под внимание</div></div>' +
          '<div class="b6-bc"><div class="b6-bc-l">' + I.factory + 'БГ производители</div><div class="b6-bc-n">' + nfmt(s.producers_bg) + '</div><div class="b6-bc-s">активни фирми</div></div>' +
        '</div>' +
        '<div class="b6-side">' +
          '<div class="b6-bc"><div class="b6-bc-l">' + I.store + 'Търговци (БГ)</div><div class="b6-bc-n">' + nfmt(s.traders_bg) + '</div><div class="b6-bc-s">вносители и дистрибутори</div></div>' +
          '<div class="b6-bc"><div class="b6-bc-l">' + I.bolt + 'Нови регистрации</div><div class="b6-bc-n">' + nfmt(s.recent12) + '</div><div class="b6-bc-s">за последните 12 месеца</div></div>' +
        '</div>' +
      '</div>';
  }

  var years = s ? s.years : [];
  var obls = s ? s.oblasti : [];
  var cats = s ? s.cats.filter(function (x) { return x.count > 0; }) : [];

  c.innerHTML =
    '<div class="b6-head"><div>' +
      '<div class="b6-title">' + (state.tab === 'flagged' ? 'Флагнати продукти' : 'Регистър на хранителни добавки') + '</div>' +
      '<div class="b6-sub">' + (s && s.last_update ? 'Последно обновяване: <b>' + esc(s.last_update) + '</b> · ' : '') + 'Кликни продукт за пълни детайли, парсиран състав и регулаторни флагове.</div>' +
    '</div><div class="b6-actions"><button class="b6-btn" id="b6-export">' + I.dl + '<span>Експорт CSV</span></button></div></div>' +
    bento +
    '<div class="b6-fbar">' +
      '<div class="b6-fq">' + I.search + '<input id="b6-q" type="text" placeholder="Търси име, рег. №, съставка…" value="' + esc(state.q) + '" autocomplete="off"><button class="clr' + (state.q ? ' show' : '') + '" id="b6-qclr">' + I.x + '</button></div>' +
      '<button class="b6-chip' + (state.flagged ? ' on danger' : '') + '" data-f="flagged">' + I.warn + 'Само флагнати' + (s ? '<span class="c">' + nfmt(s.flagged) + '</span>' : '') + '</button>' +
      '<button class="b6-chip' + (state.bg ? ' on' : '') + '" data-f="bg">' + I.flag + 'БГ производство</button>' +
      '<button class="b6-chip' + (state.recent ? ' on' : '') + '" data-f="recent">Нови (12 мес)</button>' +
      '<select class="b6-sel" id="b6-year"><option value="">Година</option>' + years.map(function (y) { return '<option value="' + y + '"' + (state.year === y ? ' selected' : '') + '>' + y + '</option>'; }).join('') + '</select>' +
      '<select class="b6-sel" id="b6-obl"><option value="">Област</option>' + obls.map(function (o) { return '<option value="' + esc(o) + '"' + (state.obl === o ? ' selected' : '') + '>' + esc(o) + '</option>'; }).join('') + '</select>' +
      '<select class="b6-sel" id="b6-sort">' +
        '<option value="new"' + (state.sort === 'new' ? ' selected' : '') + '>Най-нови</option>' +
        '<option value="date"' + (state.sort === 'date' ? ' selected' : '') + '>По дата</option>' +
        '<option value="old"' + (state.sort === 'old' ? ' selected' : '') + '>Най-стари</option>' +
        '<option value="name"' + (state.sort === 'name' ? ' selected' : '') + '>По име</option>' +
        '<option value="flagged"' + (state.sort === 'flagged' ? ' selected' : '') + '>Флагнати първи</option>' +
      '</select>' +
    '</div>' +
    '<div class="b6-cats">' + cats.map(function (ct) {
      return '<button class="b6-cat' + (state.cat === ct.code ? ' on' : '') + '" data-cat="' + esc(ct.code) + '"><span class="dot" style="background:' + (CAT_COLORS[ct.code] || '#999') + '"></span>' + esc(ct.label) + '<span class="c">' + nfmt(ct.count) + '</span></button>';
    }).join('') + '</div>' +
    '<div class="b6-legend"><span><i style="background:var(--red)"></i>Забранена съставка</span><span><i style="background:#D97706"></i>Спорна / Novel Food</span><span><i style="background:var(--blue)"></i>Информативен флаг</span></div>' +
    '<div id="b6-list"></div>';

  var qi = $('#b6-q'), qT = null;
  qi.addEventListener('input', function (e) {
    clearTimeout(qT);
    var v = e.target.value;
    $('#b6-qclr').classList.toggle('show', !!v);
    qT = setTimeout(function () { state.q = v; state.page = 1; loadProducts(false); }, 350);
  });
  $('#b6-qclr').addEventListener('click', function () { state.q = ''; state.page = 1; qi.value = ''; this.classList.remove('show'); loadProducts(false); });
  $$('.b6-chip[data-f]').forEach(function (b) {
    b.addEventListener('click', function () {
      var f = b.getAttribute('data-f');
      state[f] = !state[f];
      state.page = 1;
      if (f === 'flagged') { setTab(state.flagged ? 'flagged' : 'register'); return; }
      b.classList.toggle('on');
      loadProducts(false);
    });
  });
  $('#b6-year').addEventListener('change', function (e) { state.year = e.target.value ? parseInt(e.target.value, 10) : null; state.page = 1; loadProducts(false); });
  $('#b6-obl').addEventListener('change', function (e) { state.obl = e.target.value || null; state.page = 1; loadProducts(false); });
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
    var p = currentParams(); p.page = null; p.per = null;
    var parts = [];
    Object.keys(p).forEach(function (k) {
      var v = p[k];
      if (v === null || v === undefined || v === '' || v === false) return;
      parts.push(encodeURIComponent(k) + '=' + encodeURIComponent(v === true ? 1 : v));
    });
    window.location.href = CFG.rest + '/export' + (parts.length ? '?' + parts.join('&') : '');
  });

  renderList(true);
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
    c.innerHTML = '<div class="b6-empty">' + I.empty + '<div class="b6-empty-t">Няма съвпадения</div><div>Опитай по-кратка дума, премахни филтър или провери правописа.</div></div>';
    return;
  }
  var html = '<div class="b6-meta"><span><b>' + nfmt(state.total) + '</b> ' + plural(state.total, 'резултат', 'резултата') + (state.q ? ' за „' + esc(state.q) + '"' : '') + '</span><span>Показани ' + state.items.length + '</span></div><div class="b6-cards">';
  state.items.forEach(function (p) { html += cardHTML(p); });
  html += '</div>';
  if (state.items.length < state.total) {
    var left = state.total - state.items.length;
    html += '<button class="b6-more" id="b6-more">Зареди още ' + Math.min(state.per, left) + ' · ' + nfmt(left) + ' остават</button>';
  }
  c.innerHTML = html;

  $$('.b6-card').forEach(function (card) {
    card.addEventListener('click', function (e) {
      if (e.target.closest('.b6-act') || e.target.closest('.b6-ing')) return;
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
  if (more) more.addEventListener('click', function () { more.disabled = true; more.textContent = 'Зареждам…'; state.page++; loadProducts(true); });
  $$('.b6-ing[data-ing]').forEach(function (el) {
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
      var done = function () { var old = b.innerHTML; b.innerHTML = '✓ Копирано'; setTimeout(function () { b.innerHTML = old; }, 1500); };
      if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(url).then(done, function () { prompt('Копирай линка:', url); });
      else prompt('Копирай линка:', url);
    });
  });
}

function cardHTML(p) {
  var open = state.openReg === p.reg;
  var cat = p.cat || 'other';
  var col = CAT_COLORS[cat] || '#9AA0AB';
  var flags = (p.f || []).slice(0, 2).map(function (f) {
    var cls = f.sev === 'high' ? 'danger' : f.sev === 'med' ? 'warn' : 'info';
    return '<span class="b6-tag ' + cls + '">' + I.warn + esc(f.label) + '</span>';
  }).join('');
  var traderFirm = p.tr && p.tk === 'firm';
  var producerFirm = p.p && p.pk === 'firm';
  var firm = traderFirm ? firstPart(p.tr) : (producerFirm ? firstPart(p.p) : firstPart(p.p || ''));
  var firmIcon = traderFirm ? I.store : (producerFirm ? I.factory : I.flag);
  return '<div class="b6-card' + ((p.f || []).length ? ' flagged' : '') + (open ? ' open' : '') + '" data-reg="' + esc(p.reg) + '">' +
    '<div class="b6-ch">' +
      '<div class="b6-tile" style="background:' + col + '1C;color:' + col + '" title="' + esc(catLabel(cat)) + '">' + (CAT_ICONS[cat] || CAT_ICONS.other) + '</div>' +
      '<div style="min-width:0"><div class="b6-cn">' + esc(p.n) + '</div>' +
        '<div class="b6-cm"><span class="b6-reg">' + esc(p.reg) + '</span>' +
        (firm ? '<span class="b6-firm">' + firmIcon + esc(firm) + '</span>' : '') +
        (producerFirm ? '<span class="b6-tag green">' + I.flag + 'БГ</span>' : '') +
        flags + '</div>' +
      '</div>' +
      '<div class="b6-cd">' + (p.nd ? '<span class="b6-date">' + fmtDate(p.nd) + '</span>' : '') + '<span class="b6-chev">' + I.chev + '</span></div>' +
    '</div>' +
    (open ? bodyHTML(p) : '') +
  '</div>';
}

function bodyHTML(p) {
  var ings = parseComp(p.c);
  var ingsH = ings.map(function (i) {
    var cls = classifyIng(i.name);
    return '<span class="b6-ing' + (cls ? ' ' + cls : '') + '" data-ing="' + esc(i.name) + '" title="Покажи всички продукти с тази съставка">' + esc(i.name) + (i.amount ? ' <span class="amt">' + esc(i.amount) + ' ' + esc(i.unit) + '</span>' : '') + '</span>';
  }).join('');
  var flag = (p.f || [])[0];
  var flagH = flag ? '<div class="b6-flagbox' + (flag.sev === 'high' ? ' danger' : '') + '">' + I.warn + '<div><b>' + esc(flag.label) + (flag.sev === 'high' ? ' — не е разрешен' : ' — под внимание') + '</b>' + esc(flag.note || '') + ((p.f || []).length > 1 ? ' · Още флагове: ' + p.f.slice(1).map(function (f) { return esc(f.label); }).join(', ') : '') + '</div></div>' : '';

  var rows = '';
  if (p.p) {
    if (p.pk === 'country') rows += '<span class="l">Произход</span><span class="v">' + esc(p.p) + ' <span style="color:var(--t4)">(държава на произход)</span></span>';
    else rows += '<span class="l">Производител</span><span class="v">' + esc(firstPart(p.p)) + (restPart(p.p) ? '<div style="font-size:11.5px;color:var(--t3)">' + esc(restPart(p.p)) + '</div>' : '') + '</span>';
  }
  if (p.tr && p.tk === 'firm') rows += '<span class="l">Търговец</span><span class="v">' + esc(firstPart(p.tr)) + (restPart(p.tr) ? '<div style="font-size:11.5px;color:var(--t3)">' + esc(restPart(p.tr)) + '</div>' : '') + '</span>';
  if (p.nd) rows += '<span class="l">Уведомление</span><span class="v">' + fmtDate(p.nd) + '</span>';
  if (p.ld) rows += '<span class="l">Пускане на пазара</span><span class="v">' + fmtDate(p.ld) + '</span>';
  if (p.o) rows += '<span class="l">Областна дирекция</span><span class="v">' + esc(p.o) + '</span>';
  if (p.st) rows += '<span class="l">Складиране</span><span class="v" style="font-size:12.5px">' + esc(p.st) + '</span>';
  if (p.del) rows += '<span class="l">Статус</span><span class="v" style="color:var(--red);font-weight:600">Заличен от регистъра</span>';

  return '<div class="b6-body"><div>' +
    flagH +
    '<div class="b6-sec"><div class="b6-sec-l">Информация</div><div class="b6-grid">' + rows + '</div></div>' +
    (p.pp ? '<div class="b6-sec"><div class="b6-sec-l">Предназначение</div><div class="b6-purpose">' + esc(p.pp) + '</div></div>' : '') +
    (ings.length ? '<div class="b6-sec"><div class="b6-sec-l"><span>Състав</span><span class="count">' + ings.length + ' ' + plural(ings.length, 'съставка', 'съставки') + '</span></div><div class="b6-ings">' + ingsH + '</div></div>' : '') +
  '</div><div class="b6-aside">' +
    '<div class="b6-acard"><div class="b6-sec-l" style="margin-bottom:9px">Действия</div><div class="b6-acts">' +
      '<button class="b6-act" data-share="' + esc(p.reg) + '">' + I.share + 'Сподели</button>' +
      '<button class="b6-act" data-goto-pro="watchlist">' + I.bell + 'Следи</button>' +
    '</div></div>' +
  '</div></div>';
}

function renderSoon(c, title, desc, source, isPro) {
  c.innerHTML =
    '<div class="b6-head"><div><div class="b6-title">' + esc(title) + '</div></div></div>' +
    '<div class="b6-soon">' +
      '<span class="b6-soon-badge"><i></i>' + (isPro ? 'Pro инструмент · Скоро' : 'Скоро') + '</span>' +
      '<h3>' + esc(title) + '</h3><p>' + esc(desc) + '</p>' +
      (state.waitOk
        ? '<div class="b6-wait-ok">✓ Записахме те. Ще ти пишем при старта.</div>'
        : '<div class="b6-wait"><input type="email" id="b6-wl-email" placeholder="твоят@имейл.бг" autocomplete="email"><input type="text" id="b6-wl-hp" class="b6-hp" tabindex="-1" autocomplete="off"><button id="b6-wl-go">' + I.bell + ' Уведоми ме</button></div>') +
      '<div class="b6-soon-note">Без спам — само едно писмо при старта. Първите в списъка получават отстъпка за ранен достъп.</div>' +
    '</div>';
  var go = $('#b6-wl-go');
  if (go) go.addEventListener('click', function () {
    var em = ($('#b6-wl-email').value || '').trim();
    if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(em)) { $('#b6-wl-email').style.borderColor = 'var(--red)'; $('#b6-wl-email').focus(); return; }
    go.disabled = true;
    fetch(CFG.rest + '/waitlist', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify({ email: em, source: source, website: $('#b6-wl-hp').value || '' })
    }).then(function (r) { return r.json(); }).then(function () {
      state.waitOk = true; render();
    }).catch(function () { go.disabled = false; alert('Грешка при записване — опитай пак.'); });
  });
}

/* ===== Init ===== */
renderShell();
var m = location.hash.match(/#p=([^&]+)/);
if (m) { state.q = decodeURIComponent(m[1]); state.deepReg = state.q; }
render();
loadStats().then(function () { if (state.tab === 'register') render(); loadProducts(false).then(function () {
  if (state.deepReg && state.items.length) { state.openReg = state.items[0].reg; renderList(false); }
}); }).catch(function () {
  loadProducts(false);
});
document.addEventListener('click', function (e) {
  var b = e.target.closest ? e.target.closest('[data-goto-pro]') : null;
  if (b && root.contains(b)) { e.stopPropagation(); setTab(b.getAttribute('data-goto-pro')); }
}, true);

})();
