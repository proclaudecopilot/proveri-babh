/* Регистър на добавките v6 — публичен фронтенд (vanilla JS, без зависимости)
   v6.6 (по одита от 30.09.2026): само последната заявка променя екрана, изчистването спира
   чакащото търсене, споделен линк отваря точния запис, достъпни диалози, отделни понятия за
   „липсва в последния файл“ / „бележка за заличаване“ / „автоматично предположение“. */
(function () {
'use strict';

var CFG = window.BABH6_CFG || { rest: '/wp-json/babh6/v1' };
var root = document.getElementById('babh6-app');
if (!root) return;

var $ = function (s, r) { return (r || root).querySelector(s); };
var $$ = function (s, r) { return Array.prototype.slice.call((r || root).querySelectorAll(s)); };

/* Филтри на „Продукти“.
   status: '' наличен в последните данни | deleted — липсва в последния пълен файл | delnote — бележка за заличаване в източника | all
   flagged: независим checkbox „само с автоматична бележка“ (PR-06/OV-05)
   recent: '' | '12m' (по дата на уведомление) · year: '' | ГГГГ (по рег. №) — отделни контроли (PR-07) */
function newFilters() {
  return { q: '', status: '', flagged: false, recent: '', year: '', obl: '', rt: '', cats: [], sort: 'rel',
    producer: null, producerName: '', trader: null, traderName: '', own: false, brand: null };
}
function newPList() { return { q: '', sort: 'products', all: false, page: 1, per: 24, items: [], total: 0, allN: 0, allBg: 0, loading: false, loaded: false, err: '' }; }
var state = {
  tab: 'overview',
  f: newFilters(),           /* приложени филтри */
  draft: null,               /* чернова в панела на телефон (до „Приложи“) */
  fpanel: false, catsAll: false,
  page: 1, per: 20, items: [], total: 0, loading: false, updating: false, openReg: null,
  deepReg: null, deepErr: '', /* споделен линк #p= (PR-10) */
  stats: null, statsErr: false, waitOk: {}, waitEmail: '',
  plist: { p: newPList(), t: newPList() },
  party: { kind: 'p', open: null, detail: null, err: '', view: 'partners', prod: { items: [], total: 0, page: 1, loading: false, openReg: null, err: '' } },
  sbOpener: null,
  cols: 3,                   /* колони в мрежата на „Продукти“ (2/3/4), помни се в браузъра */
  pview: 'cards'             /* изглед на списъка с фирми: cards | table */
};
/* Пореден номер на заявка за всеки изглед: само отговорът на последната заявка променя екрана (PR-01, CO-01, AI-03) */
var seq = { products: 0, parties: { p: 0, t: 0 }, party: 0, pprod: 0, novel: 0 };
/* Чакащи таймери на търсачките — отменят се при X, смяна на екран и програмна смяна (PR-02) */
var timers = { q: null, topq: null, pq: { p: null, t: null } };
function clearTimers() {
  clearTimeout(timers.q); clearTimeout(timers.topq); clearTimeout(timers.pq.p); clearTimeout(timers.pq.t);
  timers.q = timers.topq = timers.pq.p = timers.pq.t = null;
}
var PRO = String(CFG.pro) === '1';
var mq = window.matchMedia ? window.matchMedia('(max-width: 900px)') : null;
var isMobile = function () { return !!(mq && mq.matches); };

/* ===== Icons ===== */
var I = {
  search: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>',
  x: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>',
  warn: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m21.7 16.7-8-13.4a2 2 0 0 0-3.4 0l-8 13.4A2 2 0 0 0 4 20h16a2 2 0 0 0 1.7-3.3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>',
  chev: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>',
  store: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m2 7 4.4-4.4A2 2 0 0 1 7.8 2h8.4a2 2 0 0 1 1.4.6L22 7"/><path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8"/><path d="M2 7h20"/></svg>',
  factory: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 20a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V8l-7 5V8l-7 5V4a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2Z"/></svg>',
  flag: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1Z"/><line x1="4" x2="4" y1="22" y2="15"/></svg>',
  bell: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg>',
  dl: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/></svg>',
  share: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8"/><polyline points="16 6 12 2 8 6"/><line x1="12" x2="12" y1="2" y2="15"/></svg>',
  flask: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 3h6v6l4 8a2 2 0 0 1-2 3H7a2 2 0 0 1-2-3l4-8V3Z"/></svg>',
  grid: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 3h18v18H3z"/><path d="M3 9h18M9 21V9"/></svg>',
  layers: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polygon points="12 2 2 7 12 12 22 7 12 2"/><polyline points="2 17 12 22 22 17"/><polyline points="2 12 12 17 22 12"/></svg>',
  bolt: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M13 2 3 14h9l-1 8 10-12h-9l1-8z"/></svg>',
  filter: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polygon points="22 3 2 3 10 12.5 10 19 14 21 14 12.5 22 3"/></svg>',
  menu: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M3 12h18"/><path d="M3 6h18"/><path d="M3 18h18"/></svg>',
  empty: '<svg width="120" height="88" viewBox="0 0 130 96" fill="none" aria-hidden="true"><rect x="18" y="26" width="66" height="46" rx="8" stroke="#CECEC8" stroke-width="2"/><path d="M18 40h66" stroke="#CECEC8" stroke-width="2"/><path d="M30 52h24M30 60h32" stroke="#E4E4E0" stroke-width="2.5" stroke-linecap="round"/><circle cx="92" cy="60" r="16" stroke="#E66A3D" stroke-width="3"/><path d="m103 71 12 12" stroke="#E66A3D" stroke-width="3.5" stroke-linecap="round"/></svg>'
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
var MONTHS = ['яну', 'фев', 'мар', 'апр', 'май', 'юни', 'юли', 'авг', 'сеп', 'окт', 'ное', 'дек'];
function monthLabel(m) { return MONTHS[parseInt(m.slice(5), 10) - 1] + ' ' + m.slice(0, 4); }
function pluralN(n, one, many) { return nfmt(n) + ' ' + (n === 1 ? one : many); }
function focusEl(sel) { var el = typeof sel === 'string' ? $(sel) : sel; if (el && el.focus) { try { el.focus({ preventScroll: true }); } catch (e) { el.focus(); } } }

/* Общ API клиент за GET и POST (AU-03): nonce, същия резервен REST адрес, разбираеми грешки.
   404 за конкретен ресурс (babh6_*) не сменя глобално адреса на API-то. */
function buildQS(params) {
  if (!params) return '';
  var parts = [];
  Object.keys(params).forEach(function (k) {
    var v = params[k];
    if (v === null || v === undefined || v === '' || v === false) return;
    parts.push(encodeURIComponent(k) + '=' + encodeURIComponent(v === true ? 1 : v));
  });
  return parts.length ? '?' + parts.join('&') : '';
}
function apiError(r, d, suffix) {
  var e = new Error((d && d.message) ? d.message : 'HTTP ' + r.status + (suffix || ''));
  e.status = r.status; e.code = d && d.code ? d.code : ''; e.data = d;
  return e;
}
function api(path, params, opts) {
  opts = opts || {};
  var qs = buildQS(params);
  var init = { credentials: 'same-origin', headers: {}, method: opts.method || 'GET' };
  if (CFG.nonce) init.headers['X-WP-Nonce'] = CFG.nonce;
  if (opts.body) { init.headers['Content-Type'] = 'application/json'; init.body = JSON.stringify(opts.body); }
  if (opts.signal) init.signal = opts.signal;
  var parse = function (r) { return r.text().then(function (t) { try { return t ? JSON.parse(t) : {}; } catch (e) { return { _raw: t }; } }); };
  var run = function () { return fetch(restUrl(path, qs), init); };
  return run().then(function (r) {
    return parse(r).then(function (d) {
      if (r.status === 401 && d && d.code === 'babh6_locked') { location.reload(); throw new Error('locked'); }
      var ownError = d && typeof d.code === 'string' && d.code.indexOf('babh6_') === 0;
      if (r.status === 404 && CFG.rest2 && !CFG._alt && !ownError) {
        /* /wp-json/ пътят е блокиран или пренаписването не работи → резервен ?rest_route= */
        CFG._alt = true;
        try { sessionStorage.setItem('babh6_rest_alt', '1'); } catch (e) {}
        return run().then(function (r2) {
          return parse(r2).then(function (d2) {
            if (!r2.ok) throw apiError(r2, d2, ' (и през ?rest_route=)');
            return d2;
          });
        });
      }
      if (!r.ok) throw apiError(r, d);
      if (d && d._raw !== undefined) throw new Error('Невалиден отговор от сървъра.');
      return d;
    });
  });
}
function restUrl(path, qs) {
  var base = (CFG._alt && CFG.rest2) ? CFG.rest2 : CFG.rest;
  if (base.indexOf('?') !== -1) return base + path + (qs ? '&' + qs.slice(1) : '');
  return base + path + qs;
}
try { if (sessionStorage.getItem('babh6_rest_alt') === '1' && CFG.rest2) CFG._alt = true; } catch (e) {}

/* Параметри за /products и /export от филтрите — един и същ договор за списък и CSV (EX-01) */
function filterParams(f) {
  return {
    q: f.q, status: f.status, flagged: !!f.flagged,
    recent: f.recent === '12m', year: /^\d{4}$/.test(f.year) ? f.year : null,
    obl: f.obl, rt: f.rt, cat: f.cats.length ? f.cats.join(',') : null, sort: f.sort,
    producer: f.producer, trader: f.trader, own: f.own, brand: f.brand
  };
}
/* Отваря „Продукти“ с чисти филтри + подадените (от профили и обобщението) */
function gotoProducts(f) {
  var nf = newFilters();
  Object.keys(f || {}).forEach(function (k) { nf[k] = f[k]; });
  clearTimers();
  state.f = nf; state.draft = null; state.page = 1; state.openReg = null; state.deepReg = null; state.deepErr = '';
  setTab('products');
}
function partyLink(kind, norm, name) {
  if (!PRO || !norm) return esc(name);
  return '<a href="#" class="b6-plink" data-party="' + esc(kind) + '|' + esc(norm) + '" title="Профил на фирмата">' + esc(name) + '</a>';
}

/* ===== Composition parsing (PR-12) =====
   Консервативно: разделяне само по „;“, известни единици на кирилица и латиница; неразпознат
   текст остава цял. При много дълъг състав без разделител се показва текстът, не се измислят части. */
var UNIT_RE = /^(.+?)\s+([\d]+(?:[.,]\d+)?)\s*(мг|мкг|µg|μg|г|gr|g|mg|mcg|ug|iu|i\.u\.|ме|ie|ml|мл|л|l|млрд|млн|cfu|кое|%)\.?$/i;
function parseComp(raw) {
  if (!raw) return [];
  var parts = raw.split(/;/g).map(function (s) { return s.trim(); }).filter(Boolean);
  if (parts.length === 1 && parts[0].length > 400) return [{ name: parts[0], amount: '', unit: '', raw: true }];
  return parts.map(function (p) {
    var m = p.match(UNIT_RE);
    if (m) return { name: m[1].replace(/[\-–—:\s]+$/, '').trim(), amount: m[2].replace(',', '.'), unit: m[3].toLowerCase() };
    return { name: p, amount: '', unit: '' };
  });
}
/* Съставка с автоматична бележка: само бележки, намерени в полето „състав“, и съвпадение в самия текст на съставката. */
function classifyIng(n, flags) {
  var nl = n.toLowerCase();
  for (var i = 0; i < (flags || []).length; i++) {
    if (flags[i].field && flags[i].field !== 'composition') continue;
    if (flags[i].term && nl.indexOf(flags[i].term) !== -1) return 'warn';
  }
  return '';
}

/* ===== Password gate (AU-04) ===== */
function renderGate() {
  root.innerHTML =
    '<div class="b6-gate"><form class="b6-gate-card" id="b6-gate-form" novalidate>' +
      '<div class="b6-mark" style="margin:0 auto 14px"></div>' +
      '<h1 class="b6-gate-t">Регистър на добавките</h1>' +
      '<div class="b6-gate-s">Достъпът е ограничен. Въведи парола.</div>' +
      '<label for="b6-gate-pw" class="b6-wl-label" style="color:#9CA3AF;text-align:left">Парола</label>' +
      '<input type="password" id="b6-gate-pw" autocomplete="current-password" aria-describedby="b6-gate-err">' +
      '<label style="display:block;font-size:12px;color:#9CA3AF;margin:-2px 0 10px;text-align:left"><input type="checkbox" id="b6-gate-show" style="width:auto;margin:0 6px 0 0;vertical-align:middle"> Покажи паролата</label>' +
      '<button type="submit" id="b6-gate-go">Влез</button>' +
      '<div class="b6-gate-err" id="b6-gate-err" role="alert"></div>' +
    '</form></div>';
  var inp = $('#b6-gate-pw'), go = $('#b6-gate-go'), err = $('#b6-gate-err'), busy = false;
  $('#b6-gate-show').addEventListener('change', function () { inp.type = this.checked ? 'text' : 'password'; });
  $('#b6-gate-form').addEventListener('submit', function (e) {
    e.preventDefault();
    if (busy) return; /* Enter не изпраща повторно */
    var pw = inp.value;
    if (!pw) { err.textContent = 'Въведи парола.'; inp.focus(); return; }
    busy = true; go.disabled = true; go.textContent = 'Проверка…'; err.textContent = '';
    api('/auth', null, { method: 'POST', body: { password: pw } }).then(function () { location.reload(); })
      .catch(function (e2) {
        busy = false; go.disabled = false; go.textContent = 'Влез';
        if (e2.status === 403) err.textContent = 'Грешна парола.';
        else if (e2.status === 429) err.textContent = e2.message;
        else err.textContent = 'Няма връзка със сървъра (' + e2.message + '). Паролата не е проверена — опитай отново.';
        inp.focus();
      });
  });
  setTimeout(function () { inp.focus(); }, 100);
}

/* ===== Shell ===== */
function renderShell() {
  root.innerHTML =
    '<div class="b6-layout">' +
      '<aside class="b6-sb" id="b6-sb" aria-label="Меню">' +
        '<button type="button" class="b6-sb-close" id="b6-sb-close" aria-label="Затвори менюто">' + I.x + '</button>' +
        '<div class="b6-brand"><div class="b6-mark"></div><div><div class="b6-brand-n">Регистър на добавките</div><div class="b6-brand-t">Данни от БАБХ</div></div></div>' +
        '<div class="b6-sb-l">Меню</div>' +
        '<button type="button" class="b6-item on" data-tab="overview" aria-current="page">' + I.grid + '<span class="lbl">Регистър</span></button>' +
        '<button type="button" class="b6-item" data-tab="products">' + I.search + '<span class="lbl">Продукти</span><span class="count" id="b6-c-total">—</span></button>' +
        '<button type="button" class="b6-item" data-tab="producers">' + I.factory + '<span class="lbl">Производители</span>' + (PRO ? '' : '<span class="pro">PRO</span>') + '</button>' +
        '<button type="button" class="b6-item" data-tab="traders">' + I.store + '<span class="lbl">Търговци</span>' + (PRO ? '' : '<span class="pro">PRO</span>') + '</button>' +
        '<div class="b6-sb-l">Инструменти</div>' +
        '<button type="button" class="b6-item" data-tab="novel">' + I.flask + '<span class="lbl">Проверка на съставки</span>' + (CFG.hasAI == 1 ? '' : '<span class="pro soon">В подготовка</span>') + '</button>' +
        '<button type="button" class="b6-item" data-tab="inspector">' + I.layers + '<span class="lbl">Промени в регистъра</span><span class="pro soon">В подготовка</span></button>' +
        '<button type="button" class="b6-item" data-tab="watchlist">' + I.bell + '<span class="lbl">Известия</span><span class="pro soon">В подготовка</span></button>' +
        '<div class="b6-sb-foot"><span id="b6-live-t" aria-live="polite">Зареждане…</span>Данните са от регистъра на БАБХ. Сайт за справки по данни от регистъра на БАБХ.' +
          (String(CFG.pw) === '1' ? '<br><button type="button" class="b6-sb-logout" id="b6-logout">Изход</button>' : '') + '</div>' +
      '</aside>' +
      '<div class="b6-main">' +
        '<div class="b6-top">' +
          '<button type="button" class="b6-burger" id="b6-burger" aria-label="Отвори менюто" aria-expanded="false" aria-controls="b6-sb">' + I.menu + '</button>' +
          '<div class="b6-crumb" id="b6-crumb" aria-hidden="true">Регистър</div>' +
          '<form class="b6-minq" id="b6-minq" role="search">' + I.search + '<input id="b6-minq-i" type="search" autocomplete="off"><button type="button" class="clr" id="b6-minq-x" aria-label="Изчисти търсенето">' + I.x + '</button></form>' +
          '<div class="b6-live none" id="b6-live" title="Актуалност на данните"><span class="b6-live-dot" aria-hidden="true"></span><span id="b6-live-t2">Зареждане…</span></div>' +
        '</div>' +
        '<div class="b6-content" id="b6-content"></div>' +
      '</div>' +
    '</div>' +
    '<div class="b6-scrim" id="b6-scrim"></div>' +
    '<div class="b6-dw-wrap" id="b6-dw" aria-hidden="true"><div class="b6-dw-scrim" id="b6-dw-scrim"></div><div class="b6-dw" id="b6-dw-p" role="dialog" aria-modal="true"></div></div>' +
    '<nav class="b6-bnav" aria-label="Долна навигация">' +
      '<button type="button" data-tab="overview" class="on" aria-current="page">' + I.grid + '<span>Регистър</span></button>' +
      '<button type="button" data-tab="products">' + I.search + '<span>Продукти</span></button>' +
      '<button type="button" data-tab="producers">' + I.factory + '<span>Производители</span></button>' +
      '<button type="button" data-tab="traders">' + I.store + '<span>Търговци</span></button>' +
      '<button type="button" data-btn="menu" id="b6-bnav-menu" aria-expanded="false" aria-controls="b6-sb">' + I.menu + '<span>Меню</span></button>' +
    '</nav>';

  $$('.b6-item').forEach(function (b) { b.addEventListener('click', function () { setTab(b.getAttribute('data-tab')); }); });
  $$('.b6-bnav button').forEach(function (b) {
    b.addEventListener('click', function () {
      if (b.getAttribute('data-btn') === 'menu') { openSidebar(b); return; }
      setTab(b.getAttribute('data-tab'));
    });
  });
  $('#b6-burger').addEventListener('click', function () { openSidebar(this); });
  $('#b6-sb-close').addEventListener('click', closeSidebar);
  $('#b6-scrim').addEventListener('click', closeSidebar);
  /* Фокусът остава в отвореното меню (UX-06) */
  $('#b6-sb').addEventListener('keydown', function (e) {
    if (e.key !== 'Tab' || !$('#b6-sb').classList.contains('open')) return;
    var f = $$('button:not([disabled]), a[href]', $('#b6-sb')).filter(function (el) { return el.offsetParent !== null; });
    if (!f.length) return;
    if (e.shiftKey && document.activeElement === f[0]) { e.preventDefault(); f[f.length - 1].focus(); }
    else if (!e.shiftKey && document.activeElement === f[f.length - 1]) { e.preventDefault(); f[0].focus(); }
  });
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    if (dw.length) { dwClose(false); return; }
    if ($('#b6-sb') && $('#b6-sb').classList.contains('open')) { closeSidebar(); return; }
    if (state.fpanel) { closeFilterPanel(); }
  });
  var lo = $('#b6-logout');
  if (lo) lo.addEventListener('click', function () { lo.disabled = true; api('/logout', null, { method: 'POST' }).then(function () { location.reload(); }, function () { lo.disabled = false; alert('Изходът не е потвърден от сървъра. Опитай отново.'); }); });

  /* v6.7: горната търсачка е премахната — всеки раздел има една собствена търсачка;
     компактно копие се показва в лентата, когато голямата излезе от екрана (v6.7.1) */
  bindMini();
  $('#b6-dw-scrim').addEventListener('click', function () { dwClose(true); });
  $('#b6-dw-p').addEventListener('keydown', function (e) {
    if (e.key !== 'Tab') return;
    var f = $$('button:not([disabled]), a[href], input:not([disabled]), select:not([disabled]), [tabindex="0"]', $('#b6-dw-p')).filter(function (el) { return el.offsetParent !== null; });
    if (!f.length) return;
    if (e.shiftKey && document.activeElement === f[0]) { e.preventDefault(); f[f.length - 1].focus(); }
    else if (!e.shiftKey && document.activeElement === f[f.length - 1]) { e.preventDefault(); f[0].focus(); }
  });
}
var TAB_NAMES = { overview: 'Регистър', products: 'Продукти', producers: 'Производители', traders: 'Търговци', novel: 'Проверка на съставки', inspector: 'Промени в регистъра', watchlist: 'Известия' };
function setCrumb() {
  var el = $('#b6-crumb'); if (!el) return;
  el.innerHTML = state.tab === 'overview' ? '<b>Регистър</b>' : 'Регистър <span class="sep">/</span> <b>' + esc(TAB_NAMES[state.tab] || '') + '</b>';
}
/* Предпочитание на посетителя (брой колони, карти/таблица) — само удобство, без значение за данните */
function pref(k, v) {
  try {
    if (v === undefined) return localStorage.getItem('babh6_' + k);
    localStorage.setItem('babh6_' + k, String(v));
  } catch (e) {}
  return null;
}
function openSidebar(opener) {
  var sb = $('#b6-sb'); if (!sb) return;
  state.sbOpener = opener || null;
  sb.classList.add('open'); $('#b6-scrim').classList.add('show');
  sb.setAttribute('role', 'dialog'); sb.setAttribute('aria-modal', 'true');
  $$('#b6-burger, #b6-bnav-menu').forEach(function (b) { b.setAttribute('aria-expanded', 'true'); });
  document.body.classList.add('b6-noscroll');
  focusEl('#b6-sb-close');
}
function closeSidebar() {
  var sb = $('#b6-sb'); if (!sb) return;
  var was = sb.classList.contains('open');
  sb.classList.remove('open'); $('#b6-scrim').classList.remove('show');
  sb.removeAttribute('role'); sb.removeAttribute('aria-modal');
  $$('#b6-burger, #b6-bnav-menu').forEach(function (b) { b.setAttribute('aria-expanded', 'false'); });
  if (!state.fpanel) document.body.classList.remove('b6-noscroll');
  if (was && state.sbOpener) { focusEl(state.sbOpener); }
  state.sbOpener = null;
}

function setTab(t) {
  clearTimers();
  if (dw.length) dwClose(true);
  var same = state.tab === t;
  state.tab = t; state.page = 1; state.openReg = null; state.fpanel = false; state.draft = null;
  if (t !== 'products') { state.deepReg = null; state.deepErr = ''; }
  document.body.classList.remove('b6-noscroll');
  closeSidebar();
  $$('.b6-item').forEach(function (b) { var on = b.getAttribute('data-tab') === t; b.classList.toggle('on', on); if (on) b.setAttribute('aria-current', 'page'); else b.removeAttribute('aria-current'); });
  $$('.b6-bnav button[data-tab]').forEach(function (b) { var on = b.getAttribute('data-tab') === t; b.classList.toggle('on', on); if (on) b.setAttribute('aria-current', 'page'); else b.removeAttribute('aria-current'); });
  if (t === 'producers' || t === 'traders') { state.party.open = null; state.party.detail = null; state.party.err = ''; state.party.kind = t === 'producers' ? 'p' : 't'; }
  render();
  if (t === 'products') loadProducts(false);
  if (!same) window.scrollTo({ top: 0, behavior: 'auto' });
}

/* ===== Data ===== */
function liveText(s) {
  if (!s.last_update) return { txt: 'Още няма качен регистър.', cls: 'none', title: '' };
  var txt = 'Данни от ' + s.last_update;
  var cls = 'ok', title = 'Последен пълен импорт: ' + (s.last_update_at || s.last_update);
  if (s.source_date) title += ' · дата на източника: ' + s.source_date;
  if (s.checked_at) { txt += ', проверени на ' + s.checked_at; }
  if (s.last_partial) { cls = 'warn'; title += ' · последният импорт не е приет за пълна версия'; }
  if (s.sync_result === 'error') { cls = 'err'; title += ' · последната проверка за обновяване е неуспешна'; }
  else if (s.sync_result === 'partial') { cls = 'warn'; title += ' · последната автоматична версия не е пълна'; }
  else if (s.check_overdue) { cls = 'warn'; title += ' · проверката за обновяване закъснява'; }
  else if (s.sync_result === 'running' || s.sync_result === 'queued' || s.sync_result === 'started') { cls = 'warn'; title += ' · в момента се проверява за нова версия'; }
  return { txt: txt, cls: cls, title: title };
}
function loadStats() {
  return api('/stats').then(function (s) {
    state.stats = s; state.statsErr = false;
    var ct = $('#b6-c-total');
    if (ct) ct.textContent = nfmt(s.total);
    var lt = liveText(s);
    $$('#b6-live-t, #b6-live-t2').forEach(function (el) { el.textContent = lt.txt; });
    var live = $('#b6-live'); if (live) { live.className = 'b6-live ' + lt.cls; live.title = lt.title; }
  }).catch(function (e) {
    state.statsErr = true;
    $$('#b6-live-t, #b6-live-t2').forEach(function (el) { el.textContent = 'Статистиката временно не е достъпна.'; });
    var live = $('#b6-live'); if (live) { live.className = 'b6-live err'; live.title = e.message; }
    throw e;
  });
}
function loadProducts(append) {
  var c0 = $('#b6-list');
  var id = ++seq.products;
  if (!append) { state.deepErr = ''; if (state.deepReg && state.f.q !== '') state.deepReg = null; }
  if (state.f.q && state.f.q.trim().length === 1) {
    /* Кратка заявка: старите резултати не остават под новото поле, CSV е недостъпен (PR-05) */
    state.loading = false; state.items = []; state.total = 0; state.updating = false;
    if (c0) c0.innerHTML = '<div class="b6-empty"><div class="b6-empty-t">Въведи поне 2 знака.</div><div>Търсенето започва от втория знак.</div></div>';
    syncExportBtn();
    return Promise.resolve();
  }
  state.loading = true;
  if (!append) { state.updating = state.items.length > 0; renderList(true); }
  var p = filterParams(state.f);
  p.page = state.page; p.per = state.per;
  return api('/products', p).then(function (r) {
    if (id !== seq.products) return; /* по-нова заявка вече е изпратена — този отговор не променя екрана */
    state.total = r.total;
    state.items = append ? state.items.concat(r.items) : r.items;
    state.loading = false; state.updating = false;
    renderList(false);
  }).catch(function (e) {
    if (id !== seq.products) return;
    state.loading = false; state.updating = false;
    var c = $('#b6-list');
    if (!c) return;
    if (append) {
      state.page--;
      var more = $('#b6-more');
      if (more) { more.disabled = false; more.textContent = 'Покажи още'; }
      var err = document.createElement('div'); err.className = 'b6-load-err'; err.setAttribute('role', 'alert');
      err.innerHTML = 'Не успяхме да заредим следващите резултати. <button type="button" class="b6-retry" id="b6-retry-more">Опитай отново</button>';
      var old = $('.b6-load-err', c); if (old) old.remove();
      c.appendChild(err);
      $('#b6-retry-more').addEventListener('click', function () { err.remove(); state.page++; loadProducts(true); });
      return;
    }
    state.items = []; state.total = 0;
    c.innerHTML = '<div class="b6-empty" role="alert">' + I.empty + '<div class="b6-empty-t">' + (e.status === 400 ? esc(e.message) : 'Не успяхме да заредим резултатите.') + '</div><div>' + (e.status === 400 ? 'Промени търсенето.' : 'Търсенето и филтрите са запазени. Опитай отново; ако проблемът продължи, презареди страницата.') + '</div>' +
      '<div style="margin-top:12px"><button type="button" class="b6-retry" id="b6-retry">Опитай отново</button></div>' +
      '<details class="b6-errdet"><summary>Технически подробности</summary><code>' + esc(e.message) + '</code></details></div>';
    syncExportBtn();
    $('#b6-retry').addEventListener('click', function () { loadProducts(false); });
  });
}

/* ===== Views ===== */
function render() {
  var c = $('#b6-content');
  if (!c) return;
  setCrumb();
  setTimeout(setupMini, 0);
  switch (state.tab) {
    case 'overview': renderOverview(c); break;
    case 'products': renderProducts(c); break;
    case 'producers': if (PRO) { state.party.kind = 'p'; renderParties(c, 'p'); } else { renderSoon(c, 'Производители', 'Разделът показва производителите от регистъра, свързаните с тях търговци и всички продукти, в които са посочени. Достъпен е за потребители с Pro достъп.', 'producers', true); } break;
    case 'traders': if (PRO) { state.party.kind = 't'; renderParties(c, 't'); } else { renderSoon(c, 'Търговци', 'Разделът показва търговците от регистъра, свързаните с тях производители и всички продукти, регистрирани с тях като търговец. Достъпен е за потребители с Pro достъп.', 'traders', true); } break;
    case 'novel': if (CFG.hasAI == 1) { renderNovel(c); } else { renderSoon(c, 'Проверка на съставки', 'Подготвяме справка за съставки с връзки към използваните документи. Функцията още не е достъпна.', 'novel', false); } break;
    case 'inspector': renderSoon(c, 'Промени в регистъра', 'Инструментът ще сравнява публикувани версии на регистъра и ще показва добавени, променени и липсващи записи по полета. Функцията още не е достъпна.', 'inspector', false); break;
    case 'watchlist': renderSoon(c, 'Известия', 'Инструментът ще позволява следене на избрани продукти, фирми или съставки с известие по имейл при нов или променен запис. Функцията още не е достъпна.', 'watchlist', false); break;
  }
}

/* ===== Регистър: обобщение ===== */
function renderOverview(c) {
  var s = state.stats;
  if (!s) {
    c.innerHTML = '<div class="b6-head"><div><h1 class="b6-title">Регистър на хранителните добавки</h1><div class="b6-sub">' + (state.statsErr ? 'Статистиката временно не е достъпна. Продуктите се търсят в раздел „Продукти“. <button type="button" class="b6-link" id="b6-stats-retry">Опитай отново</button>' : 'Зареждане на обобщението…') + '</div></div></div>';
    var rb = $('#b6-stats-retry'); if (rb) rb.addEventListener('click', function () { rb.disabled = true; loadStats().then(function () { if (state.tab === 'overview') render(); }, function () { if (state.tab === 'overview') render(); }); });
    return;
  }
  var max = 1, i;
  for (i = 0; i < s.monthly.length; i++) if (s.monthly[i].c > max) max = s.monthly[i].c;
  var bars = '', labels = '', srText = [];
  for (i = 0; i < s.monthly.length; i++) {
    var mo = s.monthly[i];
    var full = monthLabel(mo.m) + ': ' + pluralN(mo.c, 'уведомление', 'уведомления');
    srText.push(full);
    /* Нула е нула (OV-04): без минимална височина; последният месец е непълен */
    bars += '<div class="b6-bar' + (i === s.monthly.length - 1 ? ' now' : '') + (mo.c === 0 ? ' zero' : '') + '" style="height:' + (mo.c === 0 ? 0 : Math.max(2, Math.round(mo.c / max * 100))) + '%" title="' + esc(full) + '"></div>';
    labels += '<span>' + MONTHS[parseInt(mo.m.slice(5), 10) - 1] + '</span>';
  }
  var period = s.monthly.length ? monthLabel(s.monthly[0].m) + ' – ' + monthLabel(s.monthly[s.monthly.length - 1].m) : '';
  var thisM = s.monthly.length ? s.monthly[s.monthly.length - 1].c : 0;
  var cats = s.cats.filter(function (x) { return x.count > 0; });
  var cmax = 1; cats.forEach(function (x) { if (x.count > cmax) cmax = x.count; });
  var other = cats.filter(function (x) { return x.code === 'other'; });
  cats = cats.filter(function (x) { return x.code !== 'other'; }).concat(other);
  var kpi = function (id, icon, label, n, sub, cls) {
    return '<button type="button" class="b6-bc link' + (cls ? ' ' + cls : '') + '" data-go="' + id + '" style="text-align:left;width:100%"><div class="b6-bc-l">' + icon + label + '</div><div class="b6-bc-n">' + nfmt(n) + '</div><div class="b6-bc-s">' + sub + '</div></button>';
  };
  var lt = liveText(s);
  c.innerHTML =
    '<div class="b6-head"><div>' +
      '<h1 class="b6-title">Регистър на хранителните добавки</h1>' +
      '<div class="b6-sub">' + esc(lt.txt) + (s.source_date ? ' · дата на източника ' + esc(s.source_date) : '') + '. Търсенето е в раздел „Продукти“, фирмите — в „Производители“ и „Търговци“.</div>' +
    '</div></div>' +
    '<div class="b6-kpis' + (PRO ? ' six' : '') + '">' +
      kpi('products', I.layers, 'Записи в последните данни', s.total, 'наличен регистрационен номер') +
      (PRO ? kpi('flagged', I.warn, 'За проверка', s.flagged, 'с автоматична бележка по дума', 'tint') : '') +
      kpi('deleted', I.x, 'Липсват в последния файл', s.deleted, (s.delnote ? 'отделно: ' + nfmt(s.delnote) + ' с бележка за заличаване' : 'не са в последния пълен файл')) +
      kpi('producers', I.factory, 'Производители, определени като български', s.producers_bg, (s.producers > s.producers_bg ? 'още ' + nfmt(s.producers - s.producers_bg) + ' с неопределена или чужда държава' : 'по наличните данни')) +
      kpi('traders', I.store, 'Търговци, определени като български', s.traders_bg, (s.traders > s.traders_bg ? 'още ' + nfmt(s.traders - s.traders_bg) + ' с неопределена или чужда държава' : 'по наличните данни')) +
      kpi('recent', I.bolt, 'Уведомления за 12 месеца', s.recent12, esc(period)) +
    '</div>' +
    '<div class="b6-kpi-def">Какво показват данните: един запис = един регистрационен номер в регистъра на БАБХ (не непременно отделен търговски продукт). Фирмите са групирани автоматично по името, категориите' + (PRO ? ' и бележките са автоматични' : ' са автоматични') + ' по думи в наименованието и състава. „Липсва в последния файл“ е локално сравнение между качвания, не официално заличаване.</div>' +
    '<div class="b6-ov">' +
      '<div class="b6-bm"><div class="b6-bm-l">' + I.bolt + 'Уведомления по месеци (по дата на уведомление)</div>' +
        '<div class="b6-bm-n">' + nfmt(thisM) + '</div>' +
        '<div class="b6-bm-d">' + plural(thisM, 'уведомление', 'уведомления') + ' през текущия месец (непълен период) · ' + nfmt(s.recent12) + ' за ' + esc(period) + '</div>' +
        '<div class="b6-chart" role="img" aria-label="Уведомления по месеци: ' + esc(srText.join('; ')) + '">' + bars + '</div><div class="b6-chart-x" aria-hidden="true">' + labels + '</div>' +
        '<div class="b6-ov-note">Периодът е ' + esc(fmtDate(s.recent_from)) + ' – ' + esc(fmtDate(s.recent_to)) + ' (без последната дата); същият интервал е зад картата „Уведомления за 12 месеца“.</div>' +
      '</div>' +
      '<div class="b6-ovc"><div class="b6-sec-l">Категории (автоматично по наименование и състав)</div>' +
        cats.map(function (ct) {
          var w = Math.max(1, Math.round(100 * ct.count / cmax));
          return '<a href="#" class="b6-ovcat' + (ct.code === 'other' ? ' muted' : '') + '" data-cat="' + esc(ct.code) + '"><span class="n">' + esc(ct.code === 'other' ? 'Без определена категория' : ct.label) + '</span><span class="bar" aria-hidden="true"><i style="width:' + w + '%"></i></span><span class="c">' + nfmt(ct.count) + '</span></a>';
        }).join('') +
      '</div>' +
    '</div>';
  $$('[data-go]', c).forEach(function (el) {
    el.addEventListener('click', function () {
      var id = el.getAttribute('data-go');
      if (id === 'products') gotoProducts({});
      else if (id === 'flagged') gotoProducts({ flagged: true });
      else if (id === 'deleted') gotoProducts({ status: 'deleted' });
      else if (id === 'recent') gotoProducts({ recent: '12m' });
      else setTab(id);
    });
  });
  $$('.b6-ovcat', c).forEach(function (a) { a.addEventListener('click', function (e) { e.preventDefault(); gotoProducts({ cats: [a.getAttribute('data-cat')] }); }); });
}

/* ===== Продукти ===== */
var STATUS_OPTS = [['', 'Наличен в последните данни'], ['deleted', 'Липсва в последния пълен файл'], ['delnote', 'С бележка за заличаване в източника'], ['all', 'Всички записи']];
var RT_OPTS = [['', 'Всички рег. номера'], ['П', 'Започващи с „П“'], ['Т', 'Започващи с „Т“']];
var RECENT_OPTS = [['', 'Целият период'], ['12m', 'Последните 12 месеца']];
function yearOpts() {
  var out = [['', 'Всички години']];
  (state.stats ? state.stats.years : []).forEach(function (y) { out.push([String(y), String(y)]); });
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
  ['status', 'recent', 'year', 'obl', 'rt'].forEach(function (k) { if (f[k]) n++; });
  if (f.flagged) n++;
  if (f.producer) n++; if (f.trader) n++; if (f.brand) n++; if (f.own) n++;
  return n;
}
function applyFilters(rerenderPanel) {
  clearTimers();
  state.page = 1; state.openReg = null; state.deepReg = null;
  loadProducts(false); renderActive();
  if (rerenderPanel) renderFilterPanel(); else syncFilterPanel();
  var b = $('#b6-fbtn'); if (b) b.innerHTML = I.filter + 'Филтри' + (activeCount(state.f) ? ' · ' + activeCount(state.f) : '');
}
function onFilterChange() {
  if (isMobile()) { syncFilterPanel(); return; }
  applyFilters(false);
}
function closeFilterPanel() {
  state.fpanel = false; state.draft = null;
  renderFilterPanel();
  focusEl('#b6-fbtn');
}
function syncExportBtn() {
  var b = $('#b6-export'); if (!b) return;
  var n = state.total || 0;
  var lim = 5000;
  b.disabled = !n || state.loading;
  b.innerHTML = I.dl + '<span>CSV</span>';
  b.title = n > lim ? 'Изтегли първите ' + nfmt(lim) + ' от ' + nfmt(n) + ' резултата като CSV' : (n ? 'Изтегли ' + nfmt(n) + ' ' + plural(n, 'резултат', 'резултата') + ' като CSV' : 'Няма резултати за изтегляне');
}

var SUGGEST = ['Ашваганда', 'Магнезий', 'Колаген', 'Мелатонин', 'Витамин D3'];
function heroStatsHTML() {
  var s = state.stats;
  if (!s) return '';
  var nc = s.cats.filter(function (x) { return x.count > 0 && x.code !== 'other'; }).length;
  return '<div class="b6-hs"><b>' + nfmt(s.total) + '</b><span>записа в последните данни</span></div>' +
    '<div class="b6-hs"><b>' + nfmt(nc) + '</b><span>категории</span></div>';
}
function colsSegHTML() {
  var ic = {
    2: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="4" width="7.5" height="16" rx="1.5"/><rect x="13.5" y="4" width="7.5" height="16" rx="1.5"/></svg>',
    3: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="2.5" y="4" width="5" height="16" rx="1.2"/><rect x="9.5" y="4" width="5" height="16" rx="1.2"/><rect x="16.5" y="4" width="5" height="16" rx="1.2"/></svg>',
    4: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="2" y="4" width="3.6" height="16" rx="1"/><rect x="7.5" y="4" width="3.6" height="16" rx="1"/><rect x="13" y="4" width="3.6" height="16" rx="1"/><rect x="18.5" y="4" width="3.6" height="16" rx="1"/></svg>'
  };
  return '<div class="b6-vseg b6-colseg" role="group" aria-label="Колони">' + [2, 3, 4].map(function (n) {
    return '<button type="button" data-cols="' + n + '" class="' + (state.cols === n ? 'on' : '') + '" aria-pressed="' + (state.cols === n ? 'true' : 'false') + '" aria-label="' + n + ' колони">' + ic[n] + '</button>';
  }).join('') + '</div>';
}

function renderProducts(c) {
  var f = state.f;
  c.innerHTML =
    '<section class="b6-hero">' +
      '<div class="b6-hero-top"><div>' +
        '<h1 class="b6-hero-t">Продукти</h1>' +
        '<p class="b6-hero-s">Търси по наименование, съставка, фирма или регистрационен номер. Кирилица и латиница се приравняват автоматично.</p>' +
      '</div><div class="b6-hero-stats" id="b6-hero-stats">' + heroStatsHTML() + '</div></div>' +
      '<form class="b6-hq" id="b6-hq" role="search">' + I.search +
        '<label for="b6-q" class="b6-vh">Търсене в продуктите</label>' +
        '<input id="b6-q" type="search" placeholder="Напр. ашваганда, фирма или рег. №" value="' + esc(f.q) + '" autocomplete="off">' +
        '<button type="button" class="clr' + (f.q ? ' show' : '') + '" id="b6-qclr" aria-label="Изчисти търсенето">' + I.x + '</button>' +
        '<button type="submit" class="b6-hq-go">Търси</button>' +
      '</form>' +
      '<div class="b6-hero-sug"><span>Често търсени:</span>' + SUGGEST.map(function (t) { return '<button type="button" data-sug="' + esc(t) + '">' + esc(t) + '</button>'; }).join('') + '</div>' +
    '</section>' +
    '<div class="b6-pbody">' +
      '<aside class="b6-fpanel" id="b6-fpanel" aria-label="Филтри"></aside>' +
      '<section class="b6-pres">' +
        '<div class="b6-rbar">' +
          '<div class="b6-rcount" id="b6-rcount" aria-live="polite"></div>' +
          '<div class="b6-rtools">' +
            '<button type="button" class="b6-btn b6-fbtn" id="b6-fbtn" aria-controls="b6-fpanel" aria-expanded="false">' + I.filter + 'Филтри' + (activeCount(f) ? ' · ' + activeCount(f) : '') + '</button>' +
            sortSelectHTML(f.sort) +
            colsSegHTML() +
            '<button type="button" class="b6-btn" id="b6-export" title="Същите резултати и подреждане като в списъка; максимум 5000 реда">' + I.dl + '<span>CSV</span></button>' +
          '</div>' +
        '</div>' +
        '<div class="b6-active" id="b6-active"></div>' +
        '<div id="b6-list" aria-live="polite" aria-busy="false"></div>' +
        '<div class="b6-legend">Категориите са определени автоматично по наименованието и състава' + (PRO ? '; бележките за проверка са автоматични съвпадения по думи в наличните данни, не становище на БАБХ' : '') + '.</div>' +
      '</section>' +
    '</div>';

  var qi = $('#b6-q');
  var runQ = function (v) { clearTimeout(timers.q); timers.q = null; state.f.q = v; state.page = 1; state.deepReg = null; loadProducts(false); renderActive(); };
  qi.addEventListener('input', function (e) {
    clearTimeout(timers.q);
    var v = e.target.value;
    $('#b6-qclr').classList.toggle('show', !!v);
    timers.q = setTimeout(function () { runQ(v); }, 350);
  });
  $('#b6-hq').addEventListener('submit', function (e) { e.preventDefault(); runQ(qi.value); });
  $('#b6-qclr').addEventListener('click', function () {
    /* X спира чакащото търсене (PR-02) */
    clearTimeout(timers.q); timers.q = null;
    qi.value = ''; this.classList.remove('show');
    runQ(''); qi.focus();
  });
  $$('[data-sug]', c).forEach(function (b) {
    b.addEventListener('click', function () { var v = b.getAttribute('data-sug'); qi.value = v; $('#b6-qclr').classList.add('show'); runQ(v); });
  });
  $('#b6-fbtn').addEventListener('click', function () { state.fpanel = !state.fpanel; if (state.fpanel) state.draft = null; renderFilterPanel(); if (state.fpanel && isMobile()) focusEl('#b6-fclose'); });
  $('#b6-export').addEventListener('click', function () {
    if (!state.total || state.loading) return;
    if (state.total > 5000 && !confirm('Ще се изтеглят първите 5000 от ' + nfmt(state.total) + ' резултата според избраното подреждане; обхватът е отбелязан и в самия файл. Стесни търсенето, за да включиш всички нужни записи. Да продължим ли?')) return;
    var p = filterParams(state.f);
    if (CFG.nonce) p._wpnonce = CFG.nonce;
    window.location.href = restUrl('/export', buildQS(p));
  });
  $$('[data-cols]', c).forEach(function (b) {
    b.addEventListener('click', function () {
      state.cols = parseInt(b.getAttribute('data-cols'), 10); pref('cols', state.cols);
      $$('[data-cols]', c).forEach(function (x) { var on = x === b; x.classList.toggle('on', on); x.setAttribute('aria-pressed', on ? 'true' : 'false'); });
      var g = $('#b6-list .b6-cards'); if (g) g.style.setProperty('--cols', state.cols);
    });
  });
  bindSort(c);
  renderFilterPanel();
  renderActive();
  renderList(true);
  syncExportBtn();
}
function updateHeroStats() { var h = $('#b6-hero-stats'); if (h) h.innerHTML = heroStatsHTML(); }

var STATUS_SHORT = [['', 'Налични в последните данни'], ['deleted', 'Липсват в последния файл'], ['delnote', 'С бележка за заличаване'], ['all', 'Всички записи']];
var RT_SEG = [['', 'Всички'], ['П', 'П · произв.'], ['Т', 'Т · търг.']];
function renderFilterPanel() {
  var panel = $('#b6-fpanel');
  if (!panel) return;
  var mobile = isMobile(), f = fsrc();
  var open = !mobile || state.fpanel;
  panel.classList.toggle('sheet', mobile);
  panel.classList.toggle('open', open);
  document.body.classList.toggle('b6-noscroll', mobile && open);
  var btn = $('#b6-fbtn'); if (btn) btn.setAttribute('aria-expanded', open ? 'true' : 'false');
  if (!open) { panel.innerHTML = ''; panel.removeAttribute('role'); return; }
  if (mobile) { panel.setAttribute('role', 'dialog'); panel.setAttribute('aria-modal', 'true'); } else { panel.removeAttribute('role'); panel.removeAttribute('aria-modal'); }

  var sel = function (id, label, opts, val) {
    return '<label class="b6-fld"><span class="b6-fld-l">' + label + '</span><select class="b6-sel" data-fk="' + id + '">' +
      opts.map(function (o) { return '<option value="' + esc(o[0]) + '"' + (String(val) === o[0] ? ' selected' : '') + '>' + esc(o[1]) + '</option>'; }).join('') + '</select></label>';
  };
  var radios = function (id, opts, val, cls) {
    return '<div class="' + cls + '" role="radiogroup">' + opts.map(function (o) {
      var on = String(val || '') === o[0];
      return '<label class="' + (on ? 'on' : '') + '"><input type="radio" name="b6-r-' + id + '" data-fk="' + id + '" value="' + esc(o[0]) + '"' + (on ? ' checked' : '') + '><span>' + esc(o[1]) + '</span></label>';
    }).join('') + '</div>';
  };
  var obls = [['', 'Всички области']].concat((state.stats ? state.stats.oblasti : []).map(function (o) { return [o, o]; }));
  var cl = catsList();
  /* Избраните категории са винаги видими; сгъването е предвидимо (PR-08) */
  var showAll = state.catsAll || cl.main.length <= 9;
  var visible = showAll ? cl.main : cl.main.filter(function (ct, idx) { return idx < 8 || f.cats.indexOf(ct.code) !== -1; });
  var catRow = function (ct) {
    var on = f.cats.indexOf(ct.code) !== -1;
    var col = CAT_COLORS[ct.code] || '#9AA0AB';
    return '<label class="b6-crow' + (on ? ' on' : '') + (ct.code === 'other' ? ' other' : '') + '"><input type="checkbox" data-cat="' + esc(ct.code) + '"' + (on ? ' checked' : '') + '><i style="background:' + col + '"></i><span class="n">' + esc(ct.label) + '</span><span class="c" title="Общо в наличните данни, независимо от търсенето">' + nfmt(ct.count) + '</span></label>';
  };
  var n = activeCount(f);
  panel.innerHTML =
    '<div class="b6-fp-h"><span class="b6-fp-t">' + I.filter + 'Филтри' + (n ? ' <span class="b6-fp-n">' + n + '</span>' : '') + '</span>' +
      (mobile ? '<button type="button" class="b6-iconbtn" id="b6-fclose" aria-label="Затвори филтрите">' + I.x + '</button>' : (n ? '<button type="button" class="b6-link" id="b6-fp-clear">Изчисти</button>' : '')) + '</div>' +
    '<div class="b6-fp-s"><div class="b6-fld-l">Наличност</div>' + radios('status', STATUS_SHORT, f.status, 'b6-rlist') + '</div>' +
    '<div class="b6-fp-s"><div class="b6-fld-l">Тип рег. номер</div>' + radios('rt', RT_SEG, f.rt, 'b6-rseg') + '</div>' +
    '<div class="b6-fp-s b6-fcats"><div class="b6-fld-l">Категории <span class="b6-fld-h">' + (f.cats.length ? 'избрани ' + f.cats.length : 'избери няколко') + '</span></div>' +
      '<div class="b6-clist">' + visible.map(catRow).join('') + '</div>' +
      (cl.main.length > 9 ? '<button type="button" class="b6-link" id="b6-catsall" aria-expanded="' + (showAll ? 'true' : 'false') + '">' + (showAll ? 'Покажи по-малко' : '+ Още ' + (cl.main.length - visible.length) + ' категории') + '</button>' : '') +
      (cl.other ? '<div class="b6-clist sep">' + catRow({ code: 'other', label: 'Без определена категория', count: cl.other.count }) + '</div>' : '') +
    '</div>' +
    '<div class="b6-fp-s">' +
      sel('recent', 'Дата на уведомление', RECENT_OPTS, f.recent) +
      '<div class="b6-fp-2">' + sel('year', 'Година', yearOpts(), f.year) + sel('obl', 'Област', obls, f.obl) + '</div>' +
      '<div class="b6-fp-note">Годината и областта се извличат от рег. №</div>' +
    '</div>' +
    (PRO ? '<div class="b6-fp-s"><label class="b6-fld-ck"><input type="checkbox" data-fk="flagged"' + (f.flagged ? ' checked' : '') + '> Само с автоматична бележка за проверка <span class="b6-pro-mini">PRO</span></label></div>' : '') +
    (mobile ? '<div class="b6-sheet-f"><button type="button" class="b6-btn" id="b6-fdraftclear">Изчисти</button><button type="button" class="b6-btn primary" id="b6-fapply">Приложи филтрите' + (n ? ' · ' + n : '') + '</button></div>' : '');

  $$('select[data-fk]', panel).forEach(function (s) {
    s.addEventListener('change', function () { fsrc()[s.getAttribute('data-fk')] = s.value; onFilterChange(); });
  });
  $$('input[type="radio"][data-fk]', panel).forEach(function (r) {
    r.addEventListener('change', function () { if (r.checked) { fsrc()[r.getAttribute('data-fk')] = r.value; onFilterChange(); } });
  });
  var fc = $('input[data-fk="flagged"]', panel);
  if (fc) fc.addEventListener('change', function () { fsrc().flagged = fc.checked; onFilterChange(); });
  $$('input[data-cat]', panel).forEach(function (cb) {
    cb.addEventListener('change', function () {
      var src = fsrc(), code = cb.getAttribute('data-cat'), i = src.cats.indexOf(code);
      if (cb.checked && i === -1) src.cats.push(code); else if (!cb.checked && i !== -1) src.cats.splice(i, 1);
      onFilterChange();
    });
  });
  var all = $('#b6-catsall'); if (all) all.addEventListener('click', function () { state.catsAll = !state.catsAll; renderFilterPanel(); focusEl('#b6-catsall'); });
  var cls = $('#b6-fclose'); if (cls) cls.addEventListener('click', closeFilterPanel);
  var fpc = $('#b6-fp-clear'); if (fpc) fpc.addEventListener('click', function () { var q = f.q, sort = f.sort; state.f = newFilters(); state.f.q = q; state.f.sort = sort; state.draft = null; state.catsAll = false; applyFilters(true); focusEl('#b6-q'); });
  var ap = $('#b6-fapply'); if (ap) ap.addEventListener('click', function () { if (state.draft) state.f = state.draft; state.draft = null; state.fpanel = false; applyFilters(true); focusEl('#b6-fbtn'); });
  var dc = $('#b6-fdraftclear'); if (dc) dc.addEventListener('click', function () { var q = fsrc().q; state.draft = newFilters(); state.draft.q = q; state.draft.sort = state.f.sort; renderFilterPanel(); focusEl('#b6-fdraftclear'); });
  panel.addEventListener('keydown', function (e) {
    if (!mobile || e.key !== 'Tab') return;
    var fl = $$('button:not([disabled]), input:not([disabled]), select:not([disabled])', panel).filter(function (el) { return el.offsetParent !== null; });
    if (!fl.length) return;
    if (e.shiftKey && document.activeElement === fl[0]) { e.preventDefault(); fl[fl.length - 1].focus(); }
    else if (!e.shiftKey && document.activeElement === fl[fl.length - 1]) { e.preventDefault(); fl[0].focus(); }
  });
}
/* Обновява съществуващите контроли без прерисуване — фокусът остава (UX-07) */
function syncFilterPanel() {
  var panel = $('#b6-fpanel'); if (!panel || !panel.innerHTML) return;
  var f = fsrc();
  $$('select[data-fk]', panel).forEach(function (s) { var k = s.getAttribute('data-fk'); if (s.value !== String(f[k] || '')) s.value = String(f[k] || ''); });
  $$('input[type="radio"][data-fk]', panel).forEach(function (r) { var on = String(f[r.getAttribute('data-fk')] || '') === r.value; r.checked = on; r.parentNode.classList.toggle('on', on); });
  var fc = $('input[data-fk="flagged"]', panel); if (fc) fc.checked = !!f.flagged;
  $$('input[data-cat]', panel).forEach(function (cb) { var on = f.cats.indexOf(cb.getAttribute('data-cat')) !== -1; cb.checked = on; cb.closest('.b6-crow').classList.toggle('on', on); });
  var h = $('.b6-fcats .b6-fld-h', panel); if (h) h.textContent = f.cats.length ? 'избрани ' + f.cats.length : 'избери няколко';
  var n = activeCount(f);
  var t = $('.b6-fp-t', panel); if (t) t.innerHTML = I.filter + 'Филтри' + (n ? ' <span class="b6-fp-n">' + n + '</span>' : '');
  if (!isMobile()) {
    var hd = $('.b6-fp-h', panel), cb2 = $('#b6-fp-clear', panel);
    if (n && !cb2 && hd) { hd.insertAdjacentHTML('beforeend', '<button type="button" class="b6-link" id="b6-fp-clear">Изчисти</button>'); $('#b6-fp-clear', panel).addEventListener('click', function () { var q = state.f.q, sort = state.f.sort; state.f = newFilters(); state.f.q = q; state.f.sort = sort; state.draft = null; state.catsAll = false; applyFilters(true); focusEl('#b6-q'); }); }
    else if (!n && cb2) cb2.remove();
  }
  var ap = $('#b6-fapply', panel); if (ap) ap.textContent = 'Приложи филтрите' + (n ? ' · ' + n : '');
}

/* Ред с активните филтри: всеки се маха с ×, плюс „Изчисти филтрите“ */
function renderActive() {
  var el = $('#b6-active');
  if (!el) return;
  var f = state.f, chips = [];
  var chip = function (key, icon, text, val) { chips.push('<button type="button" class="b6-chip on" data-rm="' + key + '"' + (val ? ' data-val="' + esc(val) + '"' : '') + ' aria-label="Премахни филтъра: ' + esc(text) + '">' + (icon || '') + esc(text) + I.x + '</button>'); };
  if (f.status) chip('status', null, optLabel(STATUS_SHORT, f.status));
  if (f.flagged && PRO) chip('flagged', I.warn, 'Само с автоматична бележка');
  if (f.recent) chip('recent', I.bolt, 'Последните 12 месеца');
  if (f.year) chip('year', null, 'Година: ' + f.year);
  if (f.obl) chip('obl', null, 'Област: ' + f.obl);
  if (f.rt) chip('rt', null, 'Рег. № с „' + f.rt + '“');
  f.cats.forEach(function (code) { chip('cat', null, code === 'other' ? 'Без определена категория' : catLabel(code), code); });
  if (f.producer) chip('producer', I.factory, 'Производител: ' + (f.producerName || f.producer));
  if (f.trader) chip('trader', I.store, 'Търговец: ' + (f.traderName || f.trader));
  if (f.own) chip('own', null, f.producer ? 'Без посочен търговец' : 'Без посочен производител');
  if (f.brand) chip('brand', I.layers, 'Начало на наименованието: ' + f.brand);
  var fb = $('#b6-fbtn'); if (fb) fb.innerHTML = I.filter + 'Филтри' + (activeCount(f) ? ' · ' + activeCount(f) : '');
  if (!chips.length) { el.innerHTML = ''; el.classList.remove('has'); return; }
  el.classList.add('has');
  el.innerHTML = chips.join('') + '<button type="button" class="b6-link" id="b6-fclear" title="Търсенето и подреждането се запазват">Изчисти всички</button>';
  $$('[data-rm]', el).forEach(function (b) {
    b.addEventListener('click', function () {
      var k = b.getAttribute('data-rm');
      if (k === 'cat') { var i = f.cats.indexOf(b.getAttribute('data-val')); if (i !== -1) f.cats.splice(i, 1); }
      else if (k === 'producer' || k === 'trader') { f[k] = null; f[k + 'Name'] = ''; if (!f.producer && !f.trader) f.own = false; }
      else if (k === 'own' || k === 'flagged') f[k] = false;
      else if (k === 'brand') f.brand = null;
      else f[k] = '';
      state.draft = null; applyFilters(false); focusEl('#b6-q');
    });
  });
  $('#b6-fclear').addEventListener('click', function () { var q = f.q, sort = f.sort; state.f = newFilters(); state.f.q = q; state.f.sort = sort; state.draft = null; state.catsAll = false; applyFilters(true); focusEl('#b6-q'); });
}

function sortSelectHTML(val) {
  var opts = [['rel', 'По съвпадение'], ['new', 'Най-нови (по рег. №)'], ['date', 'По дата на уведомление'], ['old', 'Най-стари (по рег. №)'], ['name', 'По име: А–Я']];
  if (PRO) opts.push(['flagged', 'С най-много бележки']);
  return '<select class="b6-sel" id="b6-sort" aria-label="Подреждане">' + opts.map(function (o) { return '<option value="' + o[0] + '"' + (val === o[0] ? ' selected' : '') + '>' + o[1] + '</option>'; }).join('') + '</select>';
}

function setCount() {
  var el = $('#b6-rcount'); if (!el) return;
  var f = state.f;
  el.innerHTML = '<b>' + nfmt(state.total) + '</b> <span>' + plural(state.total, 'резултат', 'резултата') + (f.q ? ' за „' + esc(f.q) + '“' : '') + '</span>' +
    (state.items.length && state.items.length < state.total ? '<span class="b6-rc-sub">показани ' + nfmt(state.items.length) + '</span>' : '');
}
function renderList(skeleton) {
  var c = $('#b6-list');
  if (!c) return;
  c.setAttribute('aria-busy', state.loading ? 'true' : 'false');
  if (skeleton && state.loading !== false) {
    if (state.items.length) { c.classList.add('b6-list-updating'); var rc = $('#b6-rcount'); if (rc && !$('.b6-upd', rc)) rc.insertAdjacentHTML('beforeend', '<span class="b6-upd">обновяване…</span>'); syncExportBtn(); return; }
    var sk = '';
    for (var i = 0; i < state.cols * 2; i++) sk += '<div class="b6-sk b6-skc"><div class="b6-sk-line" style="width:40px;height:40px;border-radius:12px"></div><div class="b6-sk-line" style="width:' + (55 + (i * 13) % 35) + '%;margin-top:16px"></div><div class="b6-sk-line" style="width:60%;margin-top:10px"></div><div class="b6-sk-line" style="width:45%;margin-top:18px"></div></div>';
    c.innerHTML = '<div class="b6-cards grid" style="--cols:' + state.cols + '">' + sk + '</div>';
    syncExportBtn();
    return;
  }
  c.classList.remove('b6-list-updating');
  var f = state.f;
  setCount();
  var deep = state.deepReg ? '<div class="b6-deep" role="status">' + (state.deepErr ? esc(state.deepErr) : 'Показан е записът от споделения линк.') + ' <button type="button" class="b6-link" id="b6-deep-clear">Покажи всички продукти</button></div>' : '';
  if (!state.items.length) {
    var noData = state.stats && state.stats.total === 0;
    c.innerHTML = deep + (noData
      ? '<div class="b6-empty">' + I.empty + '<div class="b6-empty-t">Все още няма качени данни за справка.</div></div>'
      : '<div class="b6-empty">' + I.empty + '<div class="b6-empty-t">Няма резултати по тези критерии.</div><div>Провери изписването или премахни част от филтрите.</div>' +
        '<div style="margin-top:12px;display:flex;gap:8px;justify-content:center;flex-wrap:wrap">' + (f.q ? '<button type="button" class="b6-retry" id="b6-empty-q">Изчисти търсенето</button>' : '') + (activeCount(f) ? '<button type="button" class="b6-retry" id="b6-empty-f">Премахни филтрите</button>' : '') + '</div></div>');
    bindDeep(c);
    var eq = $('#b6-empty-q'); if (eq) eq.addEventListener('click', function () { var b = $('#b6-qclr'); if (b) b.click(); });
    var ef = $('#b6-empty-f'); if (ef) ef.addEventListener('click', function () { var q = f.q, sort = f.sort; state.f = newFilters(); state.f.q = q; state.f.sort = sort; state.draft = null; applyFilters(true); });
    syncExportBtn();
    return;
  }
  var html = deep + '<div class="b6-cards grid" style="--cols:' + state.cols + '">' + state.items.map(function (p) { return cardHTML(p); }).join('') + '</div>';
  if (state.items.length < state.total) {
    var left = state.total - state.items.length;
    html += '<div class="b6-more-wrap"><button type="button" class="b6-more" id="b6-more">Покажи още ' + Math.min(state.per, left) + '</button><div class="b6-left">' + (left === 1 ? 'Остава 1 резултат' : 'Остават ' + nfmt(left) + ' резултата') + '</div></div>';
  }
  c.innerHTML = html;
  bindDeep(c);
  bindCards(c, state.items);
  var tp = dw[dw.length - 1]; if (tp && tp.type === 'product') markActiveCard(tp.item.reg);
  var more = $('#b6-more');
  if (more) more.addEventListener('click', function () { more.disabled = true; more.textContent = 'Зареждане…'; state.page++; loadProducts(true); });
  syncExportBtn();
}
function bindDeep(c) {
  var b = $('#b6-deep-clear', c);
  if (b) b.addEventListener('click', function () { state.deepReg = null; state.deepErr = ''; state.f.q = ''; var qi = $('#b6-q'); if (qi) qi.value = ''; var cl = $('#b6-qclr'); if (cl) cl.classList.remove('show'); if (history.replaceState) history.replaceState(null, '', location.pathname + location.search); loadProducts(false); });
}
function bindSort(c) {
  var s = $('#b6-sort', c);
  if (s) s.addEventListener('change', function (e) { state.f.sort = e.target.value; state.page = 1; state.deepReg = null; loadProducts(false); });
}
/* ===== v6.7.1: страничен панел (drawer) за продукт и фирма; на телефон — панел отдолу нагоре =====
   Стек: всеки отворен продукт/фирма е слой; „Назад“ връща предишния, × затваря всички. */
var dw = [];
function dwLabel(e) { return e.type === 'product' ? 'Продукт' : (e.kind === 'p' ? 'Производител' : 'Търговец'); }
function dwOpen(entry, opener) {
  if (!dw.length) entry.opener = opener || document.activeElement;
  dw.push(entry);
  dwRender(true);
}
function dwClose(all) {
  if (!dw.length) return;
  if (all) dw = []; else dw.pop();
  if (!dw.length) {
    var wrap = $('#b6-dw');
    if (wrap) { wrap.classList.remove('open'); wrap.setAttribute('aria-hidden', 'true'); setTimeout(function () { if (!dw.length) $('#b6-dw-p').innerHTML = ''; }, 260); }
    state.party.open = null; state.party.detail = null; ++seq.party; ++seq.pprod;
    if (!state.fpanel) document.body.classList.remove('b6-noscroll');
    markActiveCard(null);
    var op = dwOpener; dwOpener = null;
    if (op && document.contains(op)) focusEl(op);
    return;
  }
  dwRender(false);
}
var dwOpener = null;
function markActiveCard(reg) {
  $$('.b6-card.active').forEach(function (c) { c.classList.remove('active'); });
  if (reg) $$('.b6-card[data-reg="' + CSS.escape(reg) + '"]').forEach(function (c) { c.classList.add('active'); });
}
function dwRender(isNew) {
  var wrap = $('#b6-dw'), panel = $('#b6-dw-p');
  if (!wrap || !dw.length) return;
  var top = dw[dw.length - 1];
  if (top.opener) { dwOpener = top.opener; top.opener = null; }
  var wasOpen = wrap.classList.contains('open');
  wrap.classList.add('open'); wrap.removeAttribute('aria-hidden');
  document.body.classList.add('b6-noscroll');
  panel.setAttribute('aria-label', dwLabel(top));
  panel.innerHTML =
    '<div class="b6-dw-h">' +
      (dw.length > 1 ? '<button type="button" class="b6-dw-back" id="b6-dw-back">' + I.chev + 'Назад</button>' : '<span class="b6-dw-l">' + dwLabel(top) + '</span>') +
      '<button type="button" class="b6-iconbtn b6-dw-x" id="b6-dw-x" aria-label="Затвори">' + I.x + '</button>' +
    '</div>' +
    '<div class="b6-dw-b" id="b6-dw-b"></div>';
  $('#b6-dw-x').addEventListener('click', function () { dwClose(true); });
  var bk = $('#b6-dw-back'); if (bk) bk.addEventListener('click', function () { dwClose(false); });
  var body = $('#b6-dw-b');
  if (top.type === 'product') {
    markActiveCard(top.item.reg);
    body.innerHTML = productHeadHTML(top.item) + bodyHTML(top.item);
    bindDetail(body);
  } else {
    markActiveCard(null);
    var ps = state.party;
    ps.kind = top.kind; ps.open = top.norm; ps.detail = top.detail || null; ps.err = top.err || ''; ps.view = top.view || 'partners';
    if (!top.prod) top.prod = { items: [], total: 0, page: 1, loading: false, openReg: null, err: '' };
    ps.prod = top.prod;
    renderPartyDetail(body);
  }
  panel.scrollTop = 0; body.scrollTop = 0;
  if (isNew || !wasOpen) setTimeout(function () { focusEl('#b6-dw-x'); }, wasOpen ? 0 : 60);
}
function productHeadHTML(p) {
  var cat = p.cat || 'other', col = CAT_COLORS[cat] || '#9AA0AB';
  return '<div class="b6-dw-ph"><div class="b6-tile" style="background:' + col + '1C;color:' + col + '" aria-hidden="true">' + (CAT_ICONS[cat] || CAT_ICONS.other) + '</div>' +
    '<div><div class="b6-ccat" style="color:color-mix(in srgb,' + col + ' 58%,#0E1116)">' + esc(cat === 'other' ? 'Без определена категория' : catLabel(cat, p.catl)) + '</div>' +
    '<div class="b6-dw-reg"><span class="b6-reg">' + esc(p.reg) + '</span>' + (p.nd ? '<span class="b6-date">уведомен ' + fmtDate(p.nd) + '</span>' : '') + '</div></div></div>';
}
function openProduct(item, opener) {
  if (!item) return;
  var top = dw[dw.length - 1];
  if (top && top.type === 'product' && top.item.reg === item.reg) return;
  dwOpen({ type: 'product', item: item }, opener);
}
/* Обработчици в детайла на продукт: съставки → търсене, копиране на линк */
function bindDetail(c) {
  $$('.b6-ing[data-ing]', c).forEach(function (el) {
    el.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); el.click(); } });
    el.addEventListener('click', function (e) { e.stopPropagation(); gotoProducts({ q: el.getAttribute('data-ing') }); });
  });
  $$('[data-share]', c).forEach(function (b) {
    b.addEventListener('click', function (e) {
      e.stopPropagation();
      var url = location.origin + location.pathname + location.search + '#p=' + encodeURIComponent(b.getAttribute('data-share'));
      var done = function () { var old = b.innerHTML; b.innerHTML = 'Линкът е копиран.'; b.setAttribute('aria-live', 'polite'); setTimeout(function () { b.innerHTML = old; }, 1800); };
      if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(url).then(done, function () { prompt('Копирай този линк:', url); });
      else prompt('Копирай този линк:', url);
    });
  });
}
/* Картите отварят панела с детайли (не се разгъват в мрежата) */
function bindCards(c, items) {
  $$('.b6-card', c).forEach(function (card) {
    var head = $('.b6-ch', card);
    head.addEventListener('keydown', function (e) { if ((e.key === 'Enter' || e.key === ' ') && e.target === head) { e.preventDefault(); head.click(); } });
    head.addEventListener('click', function () {
      var sel = window.getSelection ? String(window.getSelection()) : '';
      if (sel && sel.length) return; /* маркиране на текст не отваря панела (PR-14) */
      var reg = card.getAttribute('data-reg'), item = null;
      (items || []).forEach(function (x) { if (x.reg === reg) item = x; });
      openProduct(item, head);
    });
  });
}

/* ===== v6.7.1: компактна търсачка в горната лента, когато голямата излезе от екрана ===== */
var miniObs = null;
function setupMini() {
  var top = $('.b6-top'), mi = $('#b6-minq-i');
  if (!top || !mi) return;
  if (miniObs) { miniObs.disconnect(); miniObs = null; }
  top.classList.remove('mini');
  var hero = $('#b6-content .b6-hero'), hq = hero ? $('.b6-hq input', hero) : null;
  if (!hero || !hq || !('IntersectionObserver' in window)) return;
  mi.placeholder = hq.placeholder; mi.value = hq.value;
  mi.setAttribute('aria-label', hq.previousElementSibling ? hq.previousElementSibling.textContent : 'Търсене');
  hq.addEventListener('input', function () { if (mi.value !== hq.value) mi.value = hq.value; });
  miniObs = new IntersectionObserver(function (en) {
    var hidden = !en[0].isIntersecting;
    top.classList.toggle('mini', hidden);
  }, { rootMargin: '-64px 0px 0px 0px', threshold: 0 });
  miniObs.observe($('.b6-hq', hero));
}
function bindMini() {
  var mi = $('#b6-minq-i'), f = $('#b6-minq');
  if (!mi) return;
  var target = function () { return $('#b6-content .b6-hq input'); };
  mi.addEventListener('input', function () {
    var h = target(); if (!h) return;
    h.value = mi.value; h.dispatchEvent(new Event('input', { bubbles: true }));
  });
  f.addEventListener('submit', function (e) {
    e.preventDefault();
    var h = target(); if (!h) return;
    h.value = mi.value;
    var hf = h.form; if (hf) { if (hf.requestSubmit) hf.requestSubmit(); else hf.dispatchEvent(new Event('submit', { cancelable: true })); }
  });
  var clr = $('#b6-minq-x');
  if (clr) clr.addEventListener('click', function () { mi.value = ''; var h = target(); if (!h) return; var x = h.form && $('.clr', h.form); if (x) x.click(); mi.focus(); });
}

function cardHTML(p) {
  var cat = p.cat || 'other';
  var col = CAT_COLORS[cat] || '#9AA0AB';
  var catName = catLabel(cat, p.catl);
  var fl = PRO ? (p.f || []) : [];
  var nf = fl.length;
  var flags = fl.slice(0, 1).map(function (f) {
    return '<span class="b6-tag warn" title="Автоматична бележка: намерено „' + esc(f.term || f.label) + '“ в ' + esc(f.field_label || 'наличните данни') + '">' + I.warn + 'Бележка: ' + esc(f.label) + (nf > 1 ? ' +' + (nf - 1) : '') + '</span>';
  }).join('');
  var traderFirm = p.tr && p.tk === 'firm';
  var producerFirm = p.p && p.pk === 'firm';
  var firm = producerFirm ? firstPart(p.p) : (traderFirm ? firstPart(p.tr) : firstPart(p.p || ''));
  var firmIcon = producerFirm ? I.factory : (traderFirm ? I.store : I.flag);
  var rtype = p.t === 'П' ? 'П · производител' : (p.t === 'Т' ? 'Т · търговец' : '');
  var gone = p.x ? '<span class="b6-tag danger" title="Записът липсва в последния пълен файл на регистъра (локално сравнение, не официално заличаване)">Липсва в последния файл</span>' : '';
  var dnote = p.del ? '<span class="b6-tag muted" title="В източника има бележка за заличаване">Бележка за заличаване</span>' : '';
  return '<div class="b6-card' + (nf ? ' flagged' : '') + (p.x ? ' gone' : '') + '" data-reg="' + esc(p.reg) + '">' +
    '<div class="b6-ch" role="button" tabindex="0" aria-haspopup="dialog" aria-label="Подробности за ' + esc(p.n) + '">' +
      '<div class="b6-ct">' +
        '<div class="b6-tile" style="background:' + col + '1C;color:' + col + '" aria-hidden="true">' + (CAT_ICONS[cat] || CAT_ICONS.other) + '</div>' +
        '<span class="b6-ccat" style="color:color-mix(in srgb,' + col + ' 58%,#0E1116)" title="Автоматична категория">' + esc(cat === 'other' ? 'Без категория' : catName) + '</span>' +
        (p.nd ? '<span class="b6-date" title="Дата на уведомление" aria-label="Дата на уведомление: ' + fmtDate(p.nd) + '">' + fmtDate(p.nd) + '</span>' : '') +
      '</div>' +
      '<div class="b6-cn">' + esc(p.n) + '</div>' +
      (firm ? '<div class="b6-firm">' + firmIcon + '<span>' + esc(firm) + '</span></div>' : '') +
      '<div class="b6-cm"><span class="b6-reg">' + esc(p.reg) + '</span>' + (rtype ? '<span class="b6-rt">' + rtype + '</span>' : '') + gone + dnote + '</div>' +
      flags +
      '<div class="b6-cf"><span class="b6-cmore">Детайли и състав</span><svg class="b6-carr" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14m-5-5 5 5-5 5"/></svg></div>' +
    '</div>' +
  '</div>';
}

function bodyHTML(p) {
  var ings = parseComp(p.c);
  var hasAmt = false;
  var ingsH = ings.map(function (i) {
    if (i.raw) return '<span class="b6-ing raw">' + esc(i.name) + '</span>';
    var cls = PRO ? classifyIng(i.name, p.f) : '';
    if (i.amount) hasAmt = true;
    return '<span class="b6-ing' + (cls ? ' ' + cls : '') + '" data-ing="' + esc(i.name) + '" role="button" tabindex="0" title="Потърси това наименование">' + esc(i.name) + (i.amount ? ' <span class="amt">' + esc(i.amount) + ' ' + esc(i.unit) + '</span>' : '') + '</span>';
  }).join('');
  var flagH = '';
  if (PRO && (p.f || []).length) {
    flagH = '<div class="b6-flagbox">' + I.warn + '<div>' + p.f.map(function (f) {
      return '<b>Бележка за „' + esc(f.term || f.label) + '“</b>' +
        (f.excerpt ? 'Намерено в ' + esc(f.field_label || 'наличните данни') + ': „' + esc(f.excerpt) + '“.' : 'В наличните данни е намерен термин от списъка за проверка.');
    }).join('<div style="height:6px"></div>') +
    '<div class="b6-flagnote">Това е автоматично съвпадение по текст, а не становище за продукта. Бележката не определя статуса на продукта.</div></div></div>';
  }
  var row = function (l, v, cls) { return '<span class="l">' + l + '</span><span class="v' + (cls ? ' ' + cls : '') + '">' + v + '</span>'; };
  var na = '<span class="v na">Не е посочено в източника</span>';
  var rows = '';
  /* Наличност: три отделни понятия (OV-05) */
  if (p.x) rows += row('Наличност', '<span style="color:var(--red);font-weight:600">Липсва в последния пълен файл' + (p.xd ? ' (от ' + fmtDate(p.xd) + ')' : '') + '</span><div class="sub">Локално сравнение между качвания на регистъра, не официално заличаване.</div>');
  else rows += row('Наличност', 'Наличен в последните данни');
  if (p.del) rows += row('Бележка за заличаване в източника', '<span style="color:var(--red);font-weight:600">' + (p.dn ? esc(p.dn) : 'В източника има бележка за заличаване.') + '</span>');
  if (p.p) {
    if (p.pk === 'country') rows += row('Държава в полето „Производител“', esc(p.p) + '<div class="sub">В регистъра е посочена държава, а не фирма.</div>');
    else rows += row('Производител', partyLink('p', p.pn, firstPart(p.p)) + (restPart(p.p) ? '<div class="sub">' + esc(restPart(p.p)) + '</div>' : ''));
  } else rows += '<span class="l">Производител</span>' + na;
  if (p.tr) {
    if (p.tk === 'country') rows += row('Държава в полето „Търговец“', esc(p.tr));
    else rows += row('Посочен търговец', partyLink('t', p.tn, firstPart(p.tr)) + (restPart(p.tr) ? '<div class="sub">' + esc(restPart(p.tr)) + '</div>' : ''));
  } else rows += '<span class="l">Посочен търговец</span>' + na;
  /* Предположението е отделен ред, никога под „Данни от регистъра“ като факт (PR-11) */
  var infRow = p.ti ? row('Възможна връзка по наименование', partyLink('t', p.tinn, p.tin) + '<span class="b6-inf">автоматично предположение</span><div class="sub">Определено по началото на наименованието на продукта спрямо други записи. Не е поле на БАБХ; посоченото в регистъра поле „Търговец“ е показано отделно.</div>') : '';
  if (p.nn) rows += row('Номер на уведомление', esc(p.nn));
  rows += p.nd ? row('Дата на уведомление', fmtDate(p.nd)) : '<span class="l">Дата на уведомление</span>' + na;
  if (p.ed) rows += row('Дата на вписване', fmtDate(p.ed));
  if (p.ld) rows += row('Дата на пускане на пазара', fmtDate(p.ld));
  if (p.o) rows += row('Област според рег. №', esc(p.o));
  if (p.st) rows += row('Данни за съхранение', '<span style="font-size:12.5px;white-space:pre-wrap">' + esc(p.st) + '</span>'); else rows += '<span class="l">Данни за съхранение</span>' + na;

  return '<div class="b6-body">' +
    '<div class="b6-body-bar"><div class="b6-cn-full">' + esc(p.n) + '</div><div class="b6-acts"><button type="button" class="b6-act" data-share="' + esc(p.reg) + '">' + I.share + 'Копирай линк</button></div></div>' +
    flagH +
    '<div class="b6-sec"><div class="b6-sec-l">Данни от регистъра</div><div class="b6-grid">' + rows + '</div></div>' +
    (infRow ? '<div class="b6-sec"><div class="b6-sec-l">Автоматично предположение</div><div class="b6-grid">' + infRow + '</div></div>' : '') +
    (p.pp ? '<div class="b6-sec"><div class="b6-sec-l">Предназначение според регистъра</div><div class="b6-purpose">' + esc(p.pp) + '</div></div>' : '') +
    (ings.length ? '<div class="b6-sec"><div class="b6-sec-l"><span>Състав според регистъра</span></div><div class="b6-ings">' + ingsH + '</div>' +
      (hasAmt ? '<div class="b6-flagnote">Количествата са показани според наличния текст, разделен по „;“. Проверявай в източника за каква доза се отнасят; неразпознати части остават като текст.</div>' : '') +
      '<details class="b6-orig"><summary>Покажи текста на състава от регистъра</summary><div>' + esc(p.c) + '</div></details></div>' : '<div class="b6-sec"><div class="b6-sec-l">Състав според регистъра</div><div class="b6-grid"><span class="l">Състав</span>' + na + '</div></div>') +
  '</div>';
}

/* ===== Проверка на съставки ===== */
var NV_STATUS = {
  banned:  { cls: 'danger', bg: 'var(--red-bg)',   bd: 'var(--red-line)',   fg: 'var(--red)' },
  ok:      { cls: 'ok',     bg: 'var(--green-bg)', bd: '#BFE3CC',           fg: 'var(--green)' },
  caution: { cls: 'warn',   bg: 'var(--amber-bg)', bd: 'var(--amber-line)', fg: 'var(--amber)' },
  pending: { cls: 'info',   bg: 'var(--blue-bg)',  bd: '#C3D8F5',           fg: 'var(--blue)' },
  unknown: { cls: 'unknown', bg: 'var(--bg-soft)', bd: 'var(--bd-hover)', fg: 'var(--t2)' }
};
var NV_CONF = { high: 'висока', medium: 'средна', low: 'ниска' };
var nvHistory = [];
var nvInFlight = false;
function renderNovel(c) {
  c.innerHTML =
    '<div class="b6-head"><div>' +
      '<h1 class="b6-title">Проверка на съставки</h1>' +
      '<div class="b6-sub">Справка за една съставка по EU Novel Food Catalogue и Union List с помощта на AI и търсене в интернет. Резултатът е ориентировъчен, показва намерените документи и се пази 7 дни.</div>' +
    '</div></div>' +
    '<div class="b6-nv">' +
      '<form class="b6-nv-bar" id="b6-nv-form" novalidate>' +
        '<div class="b6-fq"><label for="b6-nv-q" class="b6-vh">Съставка</label>' + I.search + '<input id="b6-nv-q" type="text" placeholder="Една съставка, напр. туркестерон, NMN, berberine…" autocomplete="off" maxlength="120" aria-describedby="b6-nv-help"></div>' +
        '<button type="submit" class="b6-nv-go" id="b6-nv-go">' + I.flask + ' Провери</button>' +
      '</form>' +
      '<div class="b6-nv-help" id="b6-nv-help">Една съставка на проверка, 2–120 знака. За смес или готов продукт провери всяка съставка поотделно.</div>' +
      '<div id="b6-nv-res" aria-live="polite"></div>' +
      '<div id="b6-nv-hist"></div>' +
      '<div class="b6-nv-note">Инструментът е информативен и не замества правна консултация. Източници: EU Novel Food Catalogue, Union List (Reg. 2017/2470) — при всеки резултат са показани намерените документи. Резултатите се пазят в тази страница до презареждане.</div>' +
    '</div>';
  var inp = $('#b6-nv-q'), go = $('#b6-nv-go'), res = $('#b6-nv-res');
  function renderHist() {
    var h = $('#b6-nv-hist');
    if (!h) return; /* екранът е сменен (AI-03) */
    if (!nvHistory.length) { h.innerHTML = ''; return; }
    h.innerHTML = '<div class="b6-sec-l" style="margin:18px 0 8px">Последни проверки в тази сесия</div>' +
      nvHistory.map(function (r, i) {
        var st = NV_STATUS[r.status] || NV_STATUS.caution;
        return '<button type="button" class="b6-nv-hrow" data-h="' + i + '"><span class="b6-nv-dot" style="background:' + st.fg + '"></span><span class="b6-nv-hn">' + esc(r.ingredient) + '</span><span class="b6-nv-hl" style="color:' + st.fg + '">' + esc(r.label_bg) + '</span></button>';
      }).join('');
    $$('.b6-nv-hrow', h).forEach(function (b) {
      b.addEventListener('click', function () { var r = nvHistory[parseInt(b.getAttribute('data-h'), 10)]; if (inp) inp.value = r.ingredient; showResult(r); });
    });
  }
  function showResult(r) {
    if (!$('#b6-nv-res')) return;
    var st = NV_STATUS[r.status] || NV_STATUS.caution;
    var srcs = (r.sources || []).map(function (s) { return '<li><a href="' + esc(s.url) + '" target="_blank" rel="noopener noreferrer">' + esc(s.title || s.url) + '</a></li>'; }).join('');
    res.innerHTML =
      '<div class="b6-nv-card ' + st.cls + '" style="background:' + st.bg + ';border-color:' + st.bd + '">' +
        '<div class="b6-nv-top"><span class="b6-nv-badge" style="background:' + st.fg + '">' + esc(r.label_bg) + '</span><span class="b6-nv-ing">' + esc(r.ingredient) + '</span>' + (r.checked_at ? '<span class="b6-nv-cache">проверено на ' + esc(r.checked_at) + (r.cached ? ' (запазен резултат)' : '') + '</span>' : '') + '</div>' +
        '<div class="b6-nv-sum" style="color:' + st.fg + '">' + esc(r.summary_bg) + '</div>' +
        (srcs ? '<ul class="b6-nv-src">' + srcs + '</ul>' : '<div class="b6-nv-meta">Не са намерени проверими документи за това твърдение — приемай резултата само като насока.</div>') +
        '<div class="b6-nv-meta">' + (r.source && !srcs ? 'Посочен източник: ' + esc(r.source) + ' · ' : '') + 'Оценка на AI за сигурност: ' + esc(NV_CONF[r.confidence] || '—') + ' (самооценка на модела, не измерена надеждност).</div>' +
      '</div>';
  }
  function check() {
    if (nvInFlight) return; /* многократно Enter = една заявка (AI-03) */
    var q = (inp.value || '').trim();
    if (q.length < 2) { res.innerHTML = '<div class="b6-nv-card" style="background:var(--amber-bg);border-color:var(--amber-line)"><div class="b6-nv-sum" style="color:var(--amber)">Въведи съставка с поне 2 знака.</div></div>'; inp.focus(); return; }
    if (q.length > 120) { res.innerHTML = '<div class="b6-nv-card" style="background:var(--amber-bg);border-color:var(--amber-line)"><div class="b6-nv-sum" style="color:var(--amber)">Максимум 120 знака — въведи една съставка.</div></div>'; inp.focus(); return; }
    var id = ++seq.novel;
    nvInFlight = true;
    go.disabled = true; go.innerHTML = 'Проверявам…';
    res.innerHTML = '<div class="b6-sk" role="status"><div class="b6-sk-line" style="width:35%"></div><div class="b6-sk-line" style="width:85%;margin-top:10px"></div><div class="b6-sk-line" style="width:60%;margin-top:8px"></div></div><div class="b6-nv-meta">Проверка на „' + esc(q) + '“ — търсене в документите, обикновено до 30 секунди…</div>';
    var restore = function () { nvInFlight = false; var g = $('#b6-nv-go'); if (g) { g.disabled = false; g.innerHTML = I.flask + ' Провери'; } };
    api('/novel-check', null, { method: 'POST', body: { ingredient: q } }).then(function (d) {
      restore();
      if (id !== seq.novel || state.tab !== 'novel' || !$('#b6-nv-res')) return;
      showResult(d);
      nvHistory = [d].concat(nvHistory.filter(function (h) { return h.ingredient !== d.ingredient; })).slice(0, 8);
      renderHist();
    }).catch(function (e) {
      restore();
      if (id !== seq.novel || state.tab !== 'novel' || !$('#b6-nv-res')) return;
      var msg = e.message || 'Грешка при проверката.';
      if (!e.status) msg = 'Няма връзка със сървъра. Провери интернет връзката и опитай отново.';
      res.innerHTML = '<div class="b6-nv-card" style="background:var(--red-bg);border-color:var(--red-line)"><div class="b6-nv-sum" style="color:var(--red)">' + esc(msg) + '</div>' +
        (e.status === 429 ? '' : '<div class="b6-nv-meta"><button type="button" class="b6-retry" id="b6-nv-retry">Опитай отново</button></div>') + '</div>';
      var rb = $('#b6-nv-retry'); if (rb) rb.addEventListener('click', check);
    });
  }
  $('#b6-nv-form').addEventListener('submit', function (e) { e.preventDefault(); check(); });
  renderHist();
  setTimeout(function () { if (state.tab === 'novel') inp.focus(); }, 100);
}

/* ===== Pro: Производители / Търговци ===== */
var FIRM_GRADS = [['#E66A3D', '#F2A93B'], ['#2F8F8A', '#5CC3A0'], ['#3D7BD9', '#8A63D2'], ['#C4577E', '#E66A3D'], ['#3E9B5F', '#A8C94A'], ['#4F5BD5', '#3D9BD9']];
var LEGAL_FORMS = ['ООД', 'ЕООД', 'АД', 'ЕАД', 'ЕТ', 'СД', 'КД', 'LTD', 'GMBH', 'INC', 'SRL', 'S.R.L.', 'SP.', 'Z', 'O.O.', 'KFT', 'S.A.', 'SA', 'BV', 'B.V.'];
function firmInitials(name) {
  var w = String(name || '').replace(/["„“”'«»()]/g, ' ').split(/\s+/).filter(function (x) { return x && LEGAL_FORMS.indexOf(x.toUpperCase()) === -1; });
  if (!w.length) return '?';
  var a = w[0].charAt(0);
  var b = w[1] && /^[A-Za-zА-Яа-я]/.test(w[1]) ? w[1].charAt(0) : w[0].charAt(1);
  return (a + (b || '')).toUpperCase();
}
function firmGrad(norm) {
  var h = 0, s = String(norm || '');
  for (var i = 0; i < s.length; i++) h = (h * 31 + s.charCodeAt(i)) >>> 0;
  var g = FIRM_GRADS[h % FIRM_GRADS.length];
  return 'linear-gradient(135deg,' + g[0] + ',' + g[1] + ')';
}
function partyHeroStats(kind) {
  var ps = state.plist[kind];
  if (!ps.loaded || !ps.allN) return '';
  return '<div class="b6-hs"><b>' + nfmt(ps.allBg) + '</b><span>определени като български</span></div>' +
    '<div class="b6-hs"><b>' + nfmt(ps.allN) + '</b><span>общо в данните</span></div>';
}
function partyScopeHTML(kind) {
  var ps = state.plist[kind];
  var cnt = function (n) { return ps.loaded && ps.allN ? '<span class="c">' + nfmt(n) + '</span>' : ''; };
  return '<div class="b6-scope" role="group" aria-label="Кои фирми">' +
    '<button type="button" data-scope="bg" class="' + (ps.all ? '' : 'on') + '" aria-pressed="' + (ps.all ? 'false' : 'true') + '" title="Определено по наименованието и адреса в регистъра; без положително основание държавата остава неопределена">Български' + cnt(ps.allBg) + '</button>' +
    '<button type="button" data-scope="all" class="' + (ps.all ? 'on' : '') + '" aria-pressed="' + (ps.all ? 'true' : 'false') + '">Всички' + cnt(ps.allN) + '</button>' +
  '</div>';
}
function partyToolbarHTML(kind) {
  var ps = state.plist[kind], isP = kind === 'p';
  var viewIc = {
    cards: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="3" width="7.5" height="7.5" rx="1.5"/><rect x="13.5" y="3" width="7.5" height="7.5" rx="1.5"/><rect x="3" y="13.5" width="7.5" height="7.5" rx="1.5"/><rect x="13.5" y="13.5" width="7.5" height="7.5" rx="1.5"/></svg>',
    table: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16"/></svg>'
  };
  return '<div class="b6-rbar">' +
      '<div id="b6-pscope-' + kind + '">' + partyScopeHTML(kind) + '</div>' +
      '<div class="b6-rtools">' +
        '<select class="b6-sel" id="b6-psort-' + kind + '" aria-label="Подреждане">' +
          '<option value="products"' + (ps.sort === 'products' ? ' selected' : '') + '>Най-много продукти</option>' +
          '<option value="partners"' + (ps.sort === 'partners' ? ' selected' : '') + '>Най-много свързани ' + (isP ? 'търговци' : 'производители') + '</option>' +
          (PRO ? '<option value="flagged"' + (ps.sort === 'flagged' ? ' selected' : '') + '>Най-много за проверка</option>' : '') +
          '<option value="newest"' + (ps.sort === 'newest' ? ' selected' : '') + '>Най-нови регистрации</option>' +
          '<option value="name"' + (ps.sort === 'name' ? ' selected' : '') + '>По име</option>' +
        '</select>' +
        '<div class="b6-vseg" role="group" aria-label="Изглед">' + ['cards', 'table'].map(function (v) {
          return '<button type="button" data-pview="' + v + '" class="' + (state.pview === v ? 'on' : '') + '" aria-pressed="' + (state.pview === v ? 'true' : 'false') + '" aria-label="' + (v === 'cards' ? 'Карти' : 'Таблица') + '">' + viewIc[v] + '</button>';
        }).join('') + '</div>' +
        '<button type="button" class="b6-btn" id="b6-pexport" title="Изнасят се само вече заредените редове от списъка">' + I.dl + '<span>CSV</span></button>' +
      '</div>' +
    '</div>';
}
function bindPartyToolbar(kind) {
  var ps = state.plist[kind];
  var qi = $('#b6-pq-' + kind);
  if (!qi) return;
  var run = function (v) { clearTimeout(timers.pq[kind]); timers.pq[kind] = null; ps.q = v; ps.page = 1; loadParties(kind, false); };
  qi.addEventListener('input', function (e) {
    clearTimeout(timers.pq[kind]); var v = e.target.value; $('#b6-pqclr-' + kind).classList.toggle('show', !!v);
    timers.pq[kind] = setTimeout(function () { run(v); }, 350);
  });
  $('#b6-phq-' + kind).addEventListener('submit', function (e) { e.preventDefault(); run(qi.value); });
  $('#b6-pqclr-' + kind).addEventListener('click', function () { qi.value = ''; this.classList.remove('show'); run(''); qi.focus(); });
  var scope = $('#b6-pscope-' + kind);
  scope.addEventListener('click', function (e) {
    var b = e.target.closest('[data-scope]'); if (!b) return;
    var all = b.getAttribute('data-scope') === 'all';
    if (all === ps.all) return;
    ps.all = all; ps.page = 1;
    scope.innerHTML = partyScopeHTML(kind);
    loadParties(kind, false);
  });
  $('#b6-psort-' + kind).addEventListener('change', function (e) { ps.sort = e.target.value; ps.page = 1; loadParties(kind, false); });
  $$('[data-pview]').forEach(function (b) {
    b.addEventListener('click', function () {
      state.pview = b.getAttribute('data-pview'); pref('pview', state.pview);
      $$('[data-pview]').forEach(function (x) { var on = x === b; x.classList.toggle('on', on); x.setAttribute('aria-pressed', on ? 'true' : 'false'); });
      renderPartyList(kind, false);
    });
  });
}
function ensureParties(kind) {
  var ps = state.plist[kind];
  if (ps.loaded) { renderPartyList(kind, false); return; }
  if (ps.loading) { renderPartyList(kind, true); return; }
  ps.page = 1; loadParties(kind, false);
}
/* Странициран списък: страницата се потвърждава след успех; при отказ старите редове остават (CO-02) */
function loadParties(kind, append) {
  var ps = state.plist[kind];
  var id = ++seq.parties[kind];
  var page = append ? ps.page + 1 : 1;
  ps.loading = true; ps.err = '';
  if (!append) renderPartyList(kind, true);
  return api('/parties', { kind: kind, q: ps.q, sort: ps.sort, all: ps.all, page: page, per: ps.per }).then(function (r) {
    if (id !== seq.parties[kind]) return;
    ps.total = r.total; ps.allN = r.all; ps.allBg = r.all_bg; ps.page = page;
    ps.items = append ? ps.items.concat(r.items) : r.items;
    ps.loading = false; ps.loaded = true;
    var hs = $('#b6-phs-' + kind); if (hs) hs.innerHTML = partyHeroStats(kind);
    var sc = $('#b6-pscope-' + kind); if (sc) sc.innerHTML = partyScopeHTML(kind);
    renderPartyList(kind, false);
  }).catch(function (e) {
    if (id !== seq.parties[kind]) return;
    ps.loading = false;
    var c = $('#b6-plist-' + kind);
    if (!c) return;
    if (append) {
      var more = $('#b6-pmore-' + kind); if (more) { more.disabled = false; more.textContent = 'Покажи още'; }
      var old = $('.b6-load-err', c); if (old) old.remove();
      var err = document.createElement('div'); err.className = 'b6-load-err'; err.setAttribute('role', 'alert');
      err.innerHTML = 'Не успяхме да заредим следващите фирми. <button type="button" class="b6-retry" id="b6-pretry-' + kind + '">Опитай отново</button>';
      c.appendChild(err);
      $('#b6-pretry-' + kind).addEventListener('click', function () { err.remove(); loadParties(kind, true); });
      return;
    }
    ps.items = []; ps.total = 0; ps.err = e.message;
    c.innerHTML = '<div class="b6-empty" role="alert">' + I.empty + '<div class="b6-empty-t">Не успяхме да заредим фирмите.</div><div>Търсенето и подреждането са запазени.</div><div style="margin-top:12px"><button type="button" class="b6-retry" id="b6-pretry-' + kind + '">Опитай отново</button></div><details class="b6-errdet"><summary>Технически подробности</summary><code>' + esc(e.message) + '</code></details></div>';
    $('#b6-pretry-' + kind).addEventListener('click', function () { loadParties(kind, false); });
  });
}
function curParty() { var t = dw[dw.length - 1]; return t && t.type === 'party' ? t : null; }
function openParty(kind, norm) {
  if (!PRO || !norm) return;
  var top = curParty();
  if (top && top.kind === kind && top.norm === norm) return;
  clearTimers();
  var id = ++seq.party; ++seq.pprod;
  var entry = { type: 'party', kind: kind, norm: norm, detail: null, err: '', view: 'partners' };
  dwOpen(entry, document.activeElement);
  api('/party', { kind: kind, norm: norm }).then(function (d) {
    /* Отговорът се прилага само ако профилът е още отворен (CO-01) */
    entry.detail = d;
    if (id === seq.party && dw[dw.length - 1] === entry) dwRender(false);
  }).catch(function (e) {
    entry.err = e.status === 404 ? 'Фирмата не е намерена в наличните данни.' : e.message;
    if (id === seq.party && dw[dw.length - 1] === entry) dwRender(false);
  });
}
function renderParties(c, kind) {
  var isP = kind === 'p', pl = state.plist[kind];
  c.innerHTML =
    '<section class="b6-hero">' +
      '<div class="b6-hero-top"><div>' +
        '<h1 class="b6-hero-t">' + (isP ? 'Производители' : 'Търговци') + '</h1>' +
        '<p class="b6-hero-s">' + (isP ? 'Отвори производител, за да видиш свързаните търговци, началата на наименованията и всички негови продукти.' : 'Отвори търговец, за да видиш свързаните производители, началата на наименованията и всички продукти, регистрирани с него.') + '</p>' +
      '</div><div class="b6-hero-stats" id="b6-phs-' + kind + '">' + partyHeroStats(kind) + '</div></div>' +
      '<form class="b6-hq" id="b6-phq-' + kind + '" role="search">' + I.search +
        '<label for="b6-pq-' + kind + '" class="b6-vh">' + (isP ? 'Търсене на производител' : 'Търсене на търговец') + '</label>' +
        '<input id="b6-pq-' + kind + '" type="search" placeholder="' + (isP ? 'Търси производител…' : 'Търси търговец…') + '" value="' + esc(pl.q) + '" autocomplete="off">' +
        '<button type="button" class="clr' + (pl.q ? ' show' : '') + '" id="b6-pqclr-' + kind + '" aria-label="Изчисти търсенето">' + I.x + '</button>' +
        '<button type="submit" class="b6-hq-go">Търси</button>' +
      '</form>' +
      '<div class="b6-hero-note"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/></svg>Фирмите са групирани автоматично по името, без проверен фирмен идентификатор. „Български“ = по наименованието и адреса в регистъра.</div>' +
    '</section>' +
    partyToolbarHTML(kind) +
    '<div id="b6-plist-' + kind + '" aria-live="polite"></div>';
  bindPartyToolbar(kind);
  $('#b6-pexport').addEventListener('click', function () {
    if (!pl.items.length) return;
    var head = [isP ? 'Производител' : 'Търговец', 'Определена като българска (автоматично, по име и адрес)', 'Продукти', isP ? 'Свързани търговци' : 'Свързани производители'];
    if (PRO) head.push('С бележки за проверка');
    head.push('От година', 'До година');
    var rows = [head];
    pl.items.forEach(function (it) { var r = [it.name, it.bg ? 'да' : 'не е определено', it.products, it.partners]; if (PRO) r.push(it.flagged); r.push(it.y1 || '', it.y2 || ''); rows.push(r); });
    rows.push(['# Заредени ' + pl.items.length + ' от ' + pl.total + ' фирми според текущите търсене и подреждане; генерирано на ' + new Date().toLocaleString('bg')]);
    downloadCSV(rows, (isP ? 'proizvoditeli' : 'targovci') + '-' + new Date().toISOString().slice(0, 10) + '.csv');
  });
  ensureParties(kind);
}
function yearsLabel(y1, y2) { if (!y1 && !y2) return '—'; if (!y1) return String(y2); if (!y2 || y1 === y2) return String(y1); return y1 + '–' + y2; }
function renderPartyList(kind, skeleton) {
  var ps = state.plist[kind], c = $('#b6-plist-' + kind);
  if (!c) return;
  var isP = kind === 'p';
  if (skeleton && ps.loading && !ps.items.length) {
    var sk = ''; for (var i = 0; i < 6; i++) sk += '<div class="b6-sk b6-skc"><div class="b6-sk-line" style="width:44px;height:44px;border-radius:12px"></div><div class="b6-sk-line" style="width:' + (50 + i * 7 % 30) + '%;margin-top:16px"></div><div class="b6-sk-line" style="width:35%;height:22px;margin-top:14px"></div></div>';
    c.innerHTML = '<div class="b6-fgrid3">' + sk + '</div>'; return;
  }
  if (skeleton && ps.loading) { c.classList.add('b6-list-updating'); return; }
  c.classList.remove('b6-list-updating');
  var pe = $('#b6-pexport'); if (pe) { pe.disabled = !ps.items.length; pe.innerHTML = I.dl + '<span>' + (ps.items.length ? 'CSV · ' + nfmt(ps.items.length) + ' реда' : 'CSV') + '</span>'; }
  if (!ps.items.length) {
    c.innerHTML = '<div class="b6-empty">' + I.empty + '<div class="b6-empty-t">Няма съвпадения</div><div>' + (ps.q && ps.q.trim().length === 1 ? 'Въведи поне 2 знака.' : (ps.all ? 'Опитай по-кратка дума.' : 'Опитай по-кратка дума или включи всички фирми.')) + '</div></div>';
    return;
  }
  var maxP = 1; ps.items.forEach(function (it) { if (it.products > maxP) maxP = it.products; });
  var partnersLbl = isP ? 'Търговци' : 'Производители';
  var meta = '<div class="b6-meta"><span><b>' + nfmt(ps.total) + '</b> ' + plural(ps.total, 'фирма', 'фирми') + (ps.all ? ' (всички)' : ', определени като български') + (ps.q ? ' за „' + esc(ps.q) + '“' : '') + '</span><span>Показани ' + ps.items.length + '</span></div>';
  var html;
  if (state.pview === 'table') {
    html = meta + '<div class="b6-tbl-wrap"><table class="b6-tbl b6-ftbl"><thead><tr><th>#</th><th>Фирма</th><th>Продукти</th><th class="num" title="Различни насрещни фирми по записите в регистъра, вкл. автоматично предположени">' + partnersLbl + '</th>' + (PRO ? '<th class="num" title="Продукти с автоматична бележка за проверка">За проверка</th>' : '') + '<th class="num" title="Години от регистрационните номера">Години</th></tr></thead><tbody>';
    ps.items.forEach(function (it, i) {
      html += '<tr class="b6-prow" data-party="' + esc(kind) + '|' + esc(it.norm) + '" tabindex="0" role="link" aria-label="Профил на ' + esc(it.name) + '">' +
        '<td class="idx">' + (i + 1) + '</td>' +
        '<td><div class="b6-pname"><span class="b6-av sm" style="background:' + firmGrad(it.norm) + '" aria-hidden="true">' + esc(firmInitials(it.name)) + '</span>' + esc(it.name) + (it.bg ? ' <span class="b6-tag green" title="Определена като българска по наименованието и адреса в регистъра">БГ</span>' : '') + '</div></td>' +
        '<td class="b6-pbar"><b class="mono">' + nfmt(it.products) + '</b><span class="b6-share" aria-hidden="true"><i style="width:' + Math.max(2, Math.round(100 * it.products / maxP)) + '%"></i></span></td>' +
        '<td class="num">' + nfmt(it.partners) + '</td>' +
        (PRO ? '<td class="num">' + (it.flagged ? '<span class="b6-tag warn">' + nfmt(it.flagged) + '</span>' : '<span style="color:var(--t4)">0</span>') + '</td>' : '') +
        '<td class="num" style="color:var(--t3)">' + yearsLabel(it.y1, it.y2) + '</td>' +
      '</tr>';
    });
    html += '</tbody></table></div>';
  } else {
    html = meta + '<div class="b6-fgrid3">' + ps.items.map(function (it, i) {
      var pct = Math.max(2, Math.round(100 * it.products / maxP));
      return '<a href="#" class="b6-fcard" data-party="' + esc(kind) + '|' + esc(it.norm) + '" aria-label="Профил на ' + esc(it.name) + '">' +
        '<div class="b6-fc-h"><span class="b6-av" style="background:' + firmGrad(it.norm) + '" aria-hidden="true">' + esc(firmInitials(it.name)) + '</span>' +
          '<div class="b6-fc-n"><div class="b6-fc-name">' + esc(it.name) + '</div><div class="b6-fc-sub">' + (it.bg ? '<span class="b6-tag green">БГ</span>' : '<span class="b6-tag muted">държава не е определена</span>') + '<span class="mono">' + yearsLabel(it.y1, it.y2) + '</span></div></div>' +
          '<span class="b6-rank' + (i < 3 && ps.sort === 'products' && !ps.q ? ' top' : '') + '">#' + (i + 1) + '</span></div>' +
        '<div class="b6-fc-big"><b>' + nfmt(it.products) + '</b><span>' + plural(it.products, 'продукт', 'продукта') + '</span></div>' +
        '<div class="b6-fc-bar" aria-hidden="true"><i style="width:' + pct + '%"></i></div>' +
        '<div class="b6-fc-stats">' +
          '<div><span>' + partnersLbl + '</span><b>' + nfmt(it.partners) + '</b></div>' +
          (PRO ? '<div class="' + (it.flagged ? 'warn' : '') + '"><span>За проверка</span><b>' + nfmt(it.flagged) + '</b></div>' : '') +
        '</div>' +
        '<div class="b6-fc-f">Отвори профил<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14m-5-5 5 5-5 5"/></svg></div>' +
      '</a>';
    }).join('') + '</div>';
  }
  if (ps.items.length < ps.total) html += '<div class="b6-more-wrap" style="margin-top:14px"><button type="button" class="b6-more" id="b6-pmore-' + kind + '">Покажи още ' + Math.min(ps.per, ps.total - ps.items.length) + '</button><div class="b6-left">' + nfmt(ps.total - ps.items.length) + ' остават</div></div>';
  c.innerHTML = html;
  var more = $('#b6-pmore-' + kind);
  if (more) more.addEventListener('click', function () { more.disabled = true; more.textContent = 'Зареждане…'; loadParties(kind, true); });
  $$('.b6-prow', c).forEach(function (tr) {
    tr.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); tr.click(); } });
  });
}

/* Профил: KPI + изгледи „Свързани фирми“ · „Начала на наименованията“ · „Продукти“ */
function renderPartyDetail(c) {
  var ps = state.party, d = ps.detail, isP = ps.kind === 'p';
  var back = '';
  if (ps.err) {
    c.innerHTML = '<div class="b6-empty" role="alert">' + I.empty + '<div class="b6-empty-t">' + esc(ps.err) + '</div><div style="margin-top:12px"><button type="button" class="b6-retry" id="b6-pretry">Опитай отново</button></div></div>';
    $('#b6-pretry').addEventListener('click', function () { var k = ps.kind, n = ps.open; dwClose(false); openParty(k, n); });
    return;
  }
  if (!d) {
    c.innerHTML = '<div class="b6-sk" role="status" aria-label="Зареждане на профила"><div class="b6-sk-line" style="width:40%"></div><div class="b6-sk-line" style="width:70%;margin-top:10px"></div><div class="b6-sk-line" style="width:55%;margin-top:10px"></div></div>';
    return;
  }
  var partnersLbl = isP ? 'Свързани търговци' : 'Свързани производители';
  var pt = d.partners_total != null ? d.partners_total : d.partners.length;
  var bt = d.brands_total != null ? d.brands_total : (d.brands || []).length;
  var self = {}; self[isP ? 'producer' : 'trader'] = d.norm; self[isP ? 'producerName' : 'traderName'] = d.name;
  var views = [['partners', partnersLbl + ' (' + nfmt(pt) + ')'], ['brands', 'Начала на наименованията (' + nfmt(bt) + ')'], ['products', 'Продукти (' + nfmt(d.products) + ')']];
  c.innerHTML = back +
    '<div class="b6-head b6-dw-head"><div>' +
      '<h2 class="b6-title" style="font-size:22px">' + (isP ? I.factory : I.store) + ' ' + esc(d.name) + (d.bg ? ' <span class="b6-tag green" title="Определена като българска по наименованието и адреса в регистъра">' + I.flag + 'БГ</span>' : ' <span class="b6-tag muted" title="Няма положително основание за българска регистрация по наименованието и адреса">държавата не е определена</span>') + '</h2>' +
      '<div class="b6-sub">' + (d.full && d.full !== d.name ? 'Най-пълно изписване в регистъра: ' + esc(d.full) + ' · ' : '') + (isP ? 'производител' : 'търговец') + ' според регистъра' + (d.y1 || d.y2 ? ' · регистрации ' + yearsLabel(d.y1, d.y2) : '') + ' · групиране по името, без проверен фирмен идентификатор</div>' +
    '</div><div class="b6-actions">' +
      '<button type="button" class="b6-btn" id="b6-pall">' + I.search + '<span>Отвори в „Продукти“</span></button>' +
      '<button type="button" class="b6-btn" id="b6-pcsv" title="Изнасят се показаните свързани фирми (до 500)">' + I.dl + '<span>Свързани фирми (CSV)</span></button>' +
    '</div></div>' +
    '<div class="b6-kpis">' +
      '<button type="button" class="b6-bc link" data-view="products" style="text-align:left"><div class="b6-bc-l">' + I.layers + 'Продукти</div><div class="b6-bc-n">' + nfmt(d.products) + '</div><div class="b6-bc-s">' + (d.deleted ? nfmt(d.deleted) + ' липсват в последния файл (отделно)' : 'в наличните данни') + '</div></button>' +
      '<button type="button" class="b6-bc tint link" data-view="partners" style="text-align:left"><div class="b6-bc-l">' + (isP ? I.store : I.factory) + partnersLbl + '</div><div class="b6-bc-n">' + nfmt(pt) + '</div><div class="b6-bc-s">' + (d.partners_src != null && d.partners_src < pt ? nfmt(d.partners_src) + ' посочени в регистъра, останалите предположени' : 'по записите в регистъра') + '</div></button>' +
      '<button type="button" class="b6-bc link" data-view="brands" style="text-align:left"><div class="b6-bc-l">' + I.layers + 'Начала на наименованията</div><div class="b6-bc-n">' + nfmt(bt) + '</div><div class="b6-bc-s">автоматично групиране, не поле „марка“</div></button>' +
      '<button type="button" class="b6-bc link" data-own="1" style="text-align:left"><div class="b6-bc-l">' + I.flag + 'Без посочена насрещна фирма</div><div class="b6-bc-n">' + nfmt(d.own) + '</div><div class="b6-bc-s">' + (isP ? 'без посочен търговец и без предположение' : 'без посочен производител (или е държава)') + '</div></button>' +
      '<button type="button" class="b6-bc link" data-flagged="1" style="text-align:left"><div class="b6-bc-l">' + I.warn + 'За проверка</div><div class="b6-bc-n">' + nfmt(d.flagged) + '</div><div class="b6-bc-s">с автоматична бележка</div></button>' +
    '</div>' +
    '<div class="b6-seg" role="tablist" aria-label="Изгледи на профила">' + views.map(function (v) { return '<button type="button" role="tab" id="b6-tab-' + v[0] + '" aria-controls="b6-pview" class="b6-seg-b' + (ps.view === v[0] ? ' on' : '') + '" data-view="' + v[0] + '" aria-selected="' + (ps.view === v[0] ? 'true' : 'false') + '" tabindex="' + (ps.view === v[0] ? '0' : '-1') + '">' + v[1] + '</button>'; }).join('') + '</div>' +
    '<div id="b6-pview" role="tabpanel" aria-labelledby="b6-tab-' + ps.view + '"></div>';
  $('#b6-pall').addEventListener('click', function () { gotoProducts(self); });
  var setView = function (v) {
    ps.view = v; var ce = curParty(); if (ce) ce.view = v;
    $$('.b6-seg-b', c).forEach(function (b) { var on = b.getAttribute('data-view') === v; b.classList.toggle('on', on); b.setAttribute('aria-selected', on ? 'true' : 'false'); b.setAttribute('tabindex', on ? '0' : '-1'); });
    var pv = $('#b6-pview'); if (pv) pv.setAttribute('aria-labelledby', 'b6-tab-' + v);
    renderPartyView();
  };
  $$('[data-view]', c).forEach(function (el) { el.addEventListener('click', function () { setView(el.getAttribute('data-view')); }); });
  $('.b6-seg', c).addEventListener('keydown', function (e) {
    if (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft') return;
    var keys = views.map(function (v) { return v[0]; }), i = keys.indexOf(ps.view);
    var n = keys[(i + (e.key === 'ArrowRight' ? 1 : keys.length - 1)) % keys.length];
    e.preventDefault(); setView(n); focusEl('#b6-tab-' + n);
  });
  $('[data-own]', c).addEventListener('click', function () { var f = JSON.parse(JSON.stringify(self)); f.own = true; gotoProducts(f); });
  $('[data-flagged]', c).addEventListener('click', function () { var f = JSON.parse(JSON.stringify(self)); f.flagged = true; gotoProducts(f); });
  $('#b6-pcsv').addEventListener('click', function () {
    var rows = [[isP ? 'Производител' : 'Търговец', isP ? 'Свързан търговец' : 'Свързан производител', 'Общи продукти', 'Дял от продуктите на фирмата %', 'От тях с автоматично предположен търговец', 'С бележки за проверка', 'Първо уведомление', 'Последно уведомление']];
    d.partners.forEach(function (x) { rows.push([d.name, x.name, x.count, x.share, x.inferred || 0, x.flagged, x.first || '', x.last || '']); });
    rows.push(['# ' + d.partners.length + ' от общо ' + pt + ' свързани фирми; връзка = общ запис в регистъра, не доказано търговско отношение; генерирано на ' + new Date().toLocaleString('bg')]);
    var fn = d.norm.replace(/[^a-z0-9а-я]+/gi, '-').replace(/^-+|-+$/g, '');
    downloadCSV(rows, 'svarzani-firmi-' + (fn || d.norm.length) + '-' + new Date().toISOString().slice(0, 10) + '.csv');
  });
  renderPartyView();
}
function renderPartyView() {
  var ps = state.party, d = ps.detail, isP = ps.kind === 'p', c = $('#b6-pview');
  if (!c || !d) return;
  var self = {}; self[isP ? 'producer' : 'trader'] = d.norm; self[isP ? 'producerName' : 'traderName'] = d.name;
  if (ps.view === 'partners') {
    var partnerKind = isP ? 't' : 'p', partnersLbl = isP ? 'свързани търговци' : 'свързани производители';
    var pt = d.partners_total != null ? d.partners_total : d.partners.length;
    var maxC = 1; d.partners.forEach(function (x) { if (x.count > maxC) maxC = x.count; });
    var rows = d.partners.map(function (x, i) {
      return '<tr>' +
        '<td class="idx">' + (i + 1) + '</td>' +
        '<td><div class="b6-pname"><a href="#" class="b6-plink" data-party="' + esc(partnerKind) + '|' + esc(x.norm) + '">' + esc(x.name) + '</a>' + (x.inferred ? '<span class="b6-inf" title="Част от общите записи са с автоматично предположен търговец по наименованието">' + nfmt(x.inferred) + ' предположени</span>' : '') + '</div>' +
          '<div class="b6-share" aria-hidden="true"><i style="width:' + Math.max(2, Math.round(100 * x.count / maxC)) + '%"></i></div></td>' +
        '<td class="num"><a href="#" class="b6-plink num" data-products="' + esc(x.norm) + '" title="Покажи общите продукти"><b>' + nfmt(x.count) + '</b></a></td>' +
        '<td class="num" style="color:var(--t3)" title="Дял от всички продукти на фирмата в наличните данни">' + x.share + '%</td>' +
        '<td class="num">' + (x.flagged ? '<span class="b6-tag warn">' + nfmt(x.flagged) + '</span>' : '<span style="color:var(--t4)">0</span>') + '</td>' +
        '<td class="num" style="color:var(--t3);white-space:nowrap" title="Дати на уведомление на общите продукти">' + (x.first ? fmtDate(x.first).slice(3) : '—') + (x.last && x.last !== x.first ? ' – ' + fmtDate(x.last).slice(3) : '') + '</td>' +
      '</tr>';
    }).join('');
    c.innerHTML = '<div class="b6-meta"><span><b>' + nfmt(pt) + '</b> ' + partnersLbl + ' по записите в регистъра' + (d.partners.length < pt ? ' · показани първите ' + nfmt(d.partners.length) + ' по брой продукти' : '') + ' · клик на числото показва общите продукти</span></div>' +
      (d.partners.length
        ? '<div class="b6-tbl-wrap"><table class="b6-tbl"><thead><tr><th>#</th><th>' + (isP ? 'Свързан търговец' : 'Свързан производител') + '</th><th class="num">Общи продукти</th><th class="num">Дял</th><th class="num" title="Продукти с автоматична бележка за проверка">За проверка</th><th class="num">Период</th></tr></thead><tbody>' + rows + '</tbody></table></div>' +
          '<div class="b6-tbl-note">Връзка = общ запис, в който двете фирми са посочени (или търговецът е предположен по наименованието). Това не е доказателство за търговско отношение.</div>'
        : '<div class="b6-empty">' + I.empty + '<div class="b6-empty-t">Няма ' + partnersLbl + '</div><div>Всички продукти са без посочена насрещна фирма.</div></div>');
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
    var bt = d.brands_total != null ? d.brands_total : b.length;
    var cpLbl = isP ? 'Най-често със свързан търговец' : 'Най-често от производител';
    c.innerHTML = '<div class="b6-meta"><span><b>' + nfmt(bt) + '</b> ' + (bt === 1 ? 'начало на наименование' : 'начала на наименования') + (b.length < bt ? ' · показани първите ' + nfmt(b.length) : '') + ' · клик на числото показва продуктите</span></div>' +
      (b.length
        ? '<div class="b6-tbl-wrap"><table class="b6-tbl"><thead><tr><th>#</th><th>Начало на наименованието</th><th class="num">Продукти</th><th>' + cpLbl + '</th></tr></thead><tbody>' +
          b.map(function (x, i) {
            return '<tr><td class="idx">' + (i + 1) + '</td>' +
              '<td><div class="b6-pname">' + esc(x.token.toUpperCase()) + '</div></td>' +
              '<td class="num"><a href="#" class="b6-plink num" data-brand="' + esc(x.token) + '" title="Покажи продуктите с това начало"><b>' + nfmt(x.count) + '</b></a></td>' +
              '<td>' + (x.match ? '<a href="#" class="b6-plink" data-party="' + esc(x.match.kind) + '|' + esc(x.match.norm) + '">' + esc(x.match.name) + '</a> <span style="color:var(--t4);font-size:11px">(' + nfmt(x.match.count) + ')</span>' : '<span style="color:var(--t4)">—</span>') + '</td></tr>';
          }).join('') + '</tbody></table></div>' +
          '<div class="b6-flagnote">Групиране по първата дума в наименованието на продукта (кирилица и латиница се приравняват). Това е автоматично предположение за марка, не поле от регистъра на БАБХ; при клик филтърът търси наименования, започващи с думата.</div>'
        : '<div class="b6-empty">' + I.empty + '<div class="b6-empty-t">Няма групи по наименование</div></div>');
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
  var id = ++seq.pprod, norm = ps.open, kind = ps.kind;
  var page = append ? pr.page + 1 : 1;
  pr.loading = true;
  var p = { page: page, per: 20, sort: 'new' };
  p[isP ? 'producer' : 'trader'] = norm;
  return api('/products', p).then(function (r) {
    /* само ако профилът и изгледът са същите (CO-01) */
    if (id !== seq.pprod || ps.open !== norm || ps.kind !== kind) return;
    pr.total = r.total; pr.items = append ? pr.items.concat(r.items) : r.items; pr.loading = false; pr.page = page;
    if (ps.view === 'products') renderPartyProducts(false);
  }).catch(function (e) {
    if (id !== seq.pprod || ps.open !== norm || ps.kind !== kind) return;
    pr.loading = false;
    if (ps.view !== 'products') return;
    var c = $('#b6-pview'); if (!c) return;
    if (append) {
      var more = $('#b6-ppmore'); if (more) { more.disabled = false; more.textContent = 'Покажи още'; }
      var err = document.createElement('div'); err.className = 'b6-load-err'; err.setAttribute('role', 'alert');
      err.innerHTML = 'Не успяхме да заредим следващите продукти. <button type="button" class="b6-retry" id="b6-ppretry">Опитай отново</button>';
      c.appendChild(err); $('#b6-ppretry').addEventListener('click', function () { err.remove(); loadPartyProducts(true); });
      return;
    }
    c.innerHTML = '<div class="b6-empty" role="alert">' + I.empty + '<div class="b6-empty-t">Не успяхме да заредим продуктите.</div><div>' + esc(e.message) + '</div><div style="margin-top:12px"><button type="button" class="b6-retry" id="b6-ppretry">Опитай отново</button></div></div>';
    $('#b6-ppretry').addEventListener('click', function () { loadPartyProducts(false); });
  });
}
function renderPartyProducts(skeleton) {
  var ps = state.party, pr = ps.prod, c = $('#b6-pview');
  if (!c) return;
  if (skeleton && !pr.items.length) { c.innerHTML = '<div class="b6-sk" role="status"><div class="b6-sk-line" style="width:40%"></div><div class="b6-sk-line" style="width:75%;margin-top:10px"></div></div>'; return; }
  var html = '<div class="b6-meta"><span><b>' + nfmt(pr.total) + '</b> ' + plural(pr.total, 'продукт', 'продукта') + (ps.kind === 'p' ? ' с този производител' : ' с този търговец (посочен или предположен)') + ' · по рег. №, най-новите първи</span><span><a href="#" class="b6-plink" id="b6-pview-all">Отвори с филтри в „Продукти“</a></span></div>' +
    '<div class="b6-cards grid" style="--cols:2">' + pr.items.map(function (p) { return cardHTML(p); }).join('') + '</div>';
  if (pr.items.length < pr.total) html += '<div class="b6-more-wrap"><button type="button" class="b6-more" id="b6-ppmore">Покажи още ' + Math.min(20, pr.total - pr.items.length) + '</button><div class="b6-left">Остават ' + nfmt(pr.total - pr.items.length) + '</div></div>';
  c.innerHTML = html;
  bindCards(c, pr.items);
  var more = $('#b6-ppmore'); if (more) more.addEventListener('click', function () { more.disabled = true; more.textContent = 'Зареждане…'; loadPartyProducts(true); });
  $('#b6-pview-all').addEventListener('click', function (e) { e.preventDefault(); var f = {}; f[ps.kind === 'p' ? 'producer' : 'trader'] = ps.open; f[ps.kind === 'p' ? 'producerName' : 'traderName'] = ps.detail ? ps.detail.name : ''; gotoProducts(f); });
}

/* CSV клетка, безопасна за spreadsheet (EX-02): формулен префикс става текст, CR/LF/;/" се цитират */
function csvCell(v) {
  v = String(v == null ? '' : v).replace(/\r\n?/g, '\n');
  if (v !== '' && /^[=+\-@\t]/.test(v) && !/^-?\d+(?:[.,]\d+)?$/.test(v)) v = "'" + v;
  return /[";\n]/.test(v) ? '"' + v.replace(/"/g, '""') + '"' : v;
}
function downloadCSV(rows, filename) {
  var csv = '﻿' + rows.map(function (r) { return r.map(csvCell).join(';'); }).join('\r\n');
  var blob = new Blob([csv], { type: 'text/csv;charset=utf-8' });
  var a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = filename; document.body.appendChild(a); a.click();
  setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 500);
}

/* Компактна карта „в подготовка“ с реална форма за интерес (UX-03, WL-02, WL-04) */
var SOON_POINTS = {
  producers: ['Профил на всеки производител: свързани търговци, продукти и бележки', 'Групиране по началото на наименованията', 'CSV на списъците'],
  traders: ['Профил на всеки търговец: свързани производители и продукти', 'Продукти с търговец, предположен по наименованието, отделно от посочените', 'CSV на списъците'],
  novel: ['Статус на съставка по EU Novel Food Catalogue и Union List', 'Връзки към намерените документи и дата на проверката', 'Ясно означаване, когато няма еднозначен резултат'],
  inspector: ['Сравнение на две публикувани версии на регистъра', 'Списък на добавените, променените (по полета) и липсващите записи', 'Известие по имейл при нова версия'],
  watchlist: ['Следене на избрани продукти, фирми или съставки', 'Известие при нов, променен или липсващ запис', 'Избор на честота на известията']
};
function renderSoon(c, title, desc, source, isPro) {
  var ok = state.waitOk[source];
  var pts = (SOON_POINTS[source] || []).map(function (t) { return '<li>' + esc(t) + '</li>'; }).join('');
  c.innerHTML =
    '<div class="b6-head"><div><h1 class="b6-title">' + esc(title) + '</h1></div></div>' +
    '<div class="b6-soon">' +
      '<span class="b6-soon-badge"><i></i>' + (isPro ? 'Pro достъп' : 'В подготовка') + '</span>' +
      '<h2>' + esc(title) + '</h2><p>' + esc(desc) + '</p>' +
      '<div class="b6-soon-now">Сега работи: търсенето и филтрите в „Продукти“ и обобщението в „Регистър“.' + (isPro ? ' Разделът е достъпен след вход с парола за Pro достъп.' : '') + '</div>' +
      (pts ? '<div class="b6-sec-l">Какво ще получи записалият се</div><ul class="b6-wait-list">' + pts + '</ul>' : '') +
      '<div id="b6-wl-box">' + waitFormHTML(source, isPro, ok) + '</div>' +
    '</div>';
  bindWaitForm(source, isPro);
}
function waitFormHTML(source, isPro, ok) {
  if (ok) return '<div class="b6-wait-ok" role="status">' + (ok === 'exists' ? 'Този имейл вече е записан за известие за тази функция.' : 'Имейлът е записан. Ще получиш едно известие ' + (isPro ? 'за възможностите за достъп' : 'при пускането на функцията') + '.') + ' <button type="button" class="b6-link" id="b6-wl-again">Запиши друг имейл</button></div>';
  return '<form class="b6-wait-form" id="b6-wl-form" novalidate>' +
    '<label class="b6-wl-label" for="b6-wl-email">Имейл за известие ' + (isPro ? 'за възможностите за достъп' : 'при пускане на функцията') + '</label>' +
    '<div class="b6-wait"><input type="email" id="b6-wl-email" placeholder="name@example.com" autocomplete="email" maxlength="191" value="' + esc(state.waitEmail) + '" aria-describedby="b6-wl-err b6-wl-note"><input type="text" id="b6-wl-hp" class="b6-hp" tabindex="-1" autocomplete="off" aria-hidden="true"><button type="submit" id="b6-wl-go">' + I.bell + '<span>Уведоми ме</span></button></div>' +
    '<div class="b6-wl-err" id="b6-wl-err" role="alert"></div>' +
    '<div class="b6-soon-note" id="b6-wl-note">Имейлът се използва само за едно известие за тази функция; един имейл може да бъде записан за няколко функции. Записът може да бъде премахнат при поискване.</div>' +
    '</form>';
}
function bindWaitForm(source, isPro) {
  var again = $('#b6-wl-again');
  if (again) { again.addEventListener('click', function () { delete state.waitOk[source]; state.waitEmail = ''; $('#b6-wl-box').innerHTML = waitFormHTML(source, isPro, null); bindWaitForm(source, isPro); focusEl('#b6-wl-email'); }); return; }
  var form = $('#b6-wl-form'); if (!form) return;
  var inp = $('#b6-wl-email'), go = $('#b6-wl-go'), errEl = $('#b6-wl-err'), busy = false;
  inp.addEventListener('input', function () { state.waitEmail = inp.value; errEl.textContent = ''; inp.removeAttribute('aria-invalid'); });
  form.addEventListener('submit', function (e) {
    e.preventDefault();
    if (busy) return;
    var em = (inp.value || '').trim();
    var fieldErr = function (t) { errEl.textContent = t; inp.setAttribute('aria-invalid', 'true'); inp.focus(); };
    if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(em)) { fieldErr('Въведи валиден имейл адрес, например name@example.com.'); return; }
    errEl.textContent = ''; inp.removeAttribute('aria-invalid');
    busy = true; go.disabled = true; go.innerHTML = 'Записване…';
    api('/waitlist', null, { method: 'POST', body: { email: em, source: source, website: $('#b6-wl-hp').value || '' } }).then(function (j) {
      busy = false;
      if (!(j && j.ok)) throw new Error('Сървърът не потвърди записа.');
      state.waitOk[source] = j.exists ? 'exists' : 'ok';
      var box = $('#b6-wl-box'); if (box && state.tab && $('#b6-wl-form')) { box.innerHTML = waitFormHTML(source, isPro, state.waitOk[source]); bindWaitForm(source, isPro); }
    }).catch(function (e2) {
      busy = false; go.disabled = false; go.innerHTML = I.bell + '<span>Уведоми ме</span>';
      if (!$('#b6-wl-form')) return;
      /* Технически отказ не се представя като грешка в полето (WL-02); имейлът остава */
      if (e2.status === 400) fieldErr(e2.message);
      else errEl.textContent = (e2.status ? e2.message : 'Няма връзка със сървъра.') + ' Имейлът не е записан — опитай отново.';
    });
  });
}

/* ===== Споделен линк #p=REG: точен запис през /product/{reg}, включително липсващ в последния файл (PR-10) ===== */
function parseHash() {
  var m = location.hash.match(/#p=([^&]+)/);
  if (!m) return null;
  try { return decodeURIComponent(m[1]); } catch (e) { return m[1]; }
}
function openDeep(reg) {
  clearTimers();
  reg = String(reg || '').trim();
  if (!reg) return;
  state.tab = 'products'; state.page = 1; state.openReg = null; state.fpanel = false;
  state.f = newFilters(); state.f.q = '';
  state.deepReg = reg; state.deepErr = ''; state.items = []; state.total = 0; state.loading = true;
  var id = ++seq.products;
  $$('.b6-item').forEach(function (b) { var on = b.getAttribute('data-tab') === 'products'; b.classList.toggle('on', on); if (on) b.setAttribute('aria-current', 'page'); else b.removeAttribute('aria-current'); });
  $$('.b6-bnav button[data-tab]').forEach(function (b) { var on = b.getAttribute('data-tab') === 'products'; b.classList.toggle('on', on); if (on) b.setAttribute('aria-current', 'page'); else b.removeAttribute('aria-current'); });
  render();
  api('/product/' + encodeURIComponent(reg)).then(function (item) {
    if (id !== seq.products || state.deepReg !== reg) return;
    state.items = [item]; state.total = 1; state.loading = false;
    renderList(false);
    openProduct(item, null);
  }).catch(function (e) {
    if (id !== seq.products || state.deepReg !== reg) return;
    state.loading = false; state.items = []; state.total = 0;
    state.deepErr = e.status === 404 ? 'Не е намерен запис с регистрационен номер „' + reg + '“ в наличните данни. Потърси продукта по име или фирма.' : 'Записът от линка не можа да бъде зареден (' + e.message + ').';
    renderList(false);
  });
}

/* ===== Init ===== */
if (String(CFG.locked) === '1') { renderGate(); return; }
(function () { var c0 = parseInt(pref('cols'), 10); if ([2, 3, 4].indexOf(c0) !== -1) state.cols = c0; if (pref('pview') === 'table') state.pview = 'table'; })();
renderShell();
var deep0 = parseHash();
if (deep0) openDeep(deep0); else { render(); }
loadStats().then(function () {
  if (state.tab === 'overview') render();
  else if (state.tab === 'products') { renderFilterPanel(); renderActive(); updateHeroStats(); }
}).catch(function () {
  /* Отказ на статистиката обновява само зависещите от нея области (PR-15) */
  if (state.tab === 'overview') render();
});
window.addEventListener('hashchange', function () { var r = parseHash(); if (r) openDeep(r); });
/* Само при смяна на breakpoint-а, не при всеки resize (UX-07); черновата на телефон се запазва */
if (mq) {
  var onBp = function () {
    if (state.tab !== 'products') { document.body.classList.remove('b6-noscroll'); return; }
    if (!isMobile()) { if (state.draft) { state.f = state.draft; state.draft = null; } state.fpanel = false; renderActive(); }
    renderFilterPanel();
  };
  if (mq.addEventListener) mq.addEventListener('change', onBp); else if (mq.addListener) mq.addListener(onBp);
}
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
