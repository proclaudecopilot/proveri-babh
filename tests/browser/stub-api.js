'use strict';
/* Подменя /wp-json/babh6/v1 с фиксирани отговори по договора contracts/openapi-babh6-v1.yaml (as-is 1.0).
   Данните са synthetic (не са от БАБХ) и служат само за поведение на интерфейса, не за parser приемане. */
const fs = require('fs'), path = require('path');
const ROOT = path.join(__dirname, '..', '..');

function card(reg, name, nd) {
  return { reg, t: reg[0], n: name, p: 'Тестова фирма', pk: 'firm', pn: 'тестова фирма', tr: '', tk: '', tn: '', to: 1,
    nd, cat: 'other', catl: 'Друго', fc: 0, x: 0, del: false, short: 1 };
}
function item(reg, name, nd) {
  return { reg, t: reg[0], y: 2000 + parseInt(reg.slice(3, 5), 10), o: 'Ловеч', n: name, p: 'Тестова фирма ЕООД, гр. София', pk: 'firm', pn: 'тестова фирма',
    tr: '', tk: '', tn: '', ti: 0, tin: '', tinn: '', to: 1, c: 'Магнезий 187,5 мг; Витамин B6 1,05 мг', pp: 'тест', st: null, nn: '1', nd, ld: null, ed: null,
    cat: 'other', catl: 'Друго', f: [], x: 0, xd: '', del: false, dn: '' };
}
const DB = {
  'Т162618382': item('Т162618382', 'Магнезий + B6 (deep link)', '2026-03-01'),
  'Т162600001': item('Т162600001', 'Ашваганда форте', '2026-02-01'),
  'П052112345': item('П052112345', 'Куркума капсули', '2021-05-05'),
};
const stats = { total: 3, producers_bg: 1, traders_bg: 0, deleted: 0, delnote: 0, producers: 1, traders: 0, inferred: 0,
  last_update: '16.10.2026', last_update_at: '16.10.2026 19:25', last_partial: 0, source_date: '13.10.2026', checked_at: '17.10.2026 06:00',
  sync_result: 'ok', sync_enabled: 1, check_overdue: 0,
  monthly: Array.from({ length: 12 }, (_, i) => ({ m: '2025-' + String(11 + i > 12 ? 11 + i - 12 : 11 + i).padStart(2, '0'), c: 0 })),
  recent12: 0, recent_from: '2025-11-01', recent_to: '2026-11-01',
  cats: [{ code: 'other', label: 'Друго', count: 3 }], years: [2026, 2021], oblasti: ['Ловеч', 'Стара Загора'], rules: 'test' };

/** Playwright route handler за http://babh6.test/** : статични файлове от хранилището + API stub. Записва всички API заявки в log. */
function install(page, log) {
  return page.route('http://babh6.test/**', async (route) => {
    const u = new URL(route.request().url());
    if (!u.pathname.startsWith('/api/')) {
      /* BABH6_JS=<път> сервира друг babh6.js (напр. git show HEAD~1:assets/babh6.js) — за възпроизвеждане на дефекта преди поправката */
      const file = u.pathname === '/' || u.pathname === '/harness.html' ? path.join(__dirname, 'harness.html')
        : (u.pathname === '/assets/babh6.js' && process.env.BABH6_JS) ? path.resolve(process.env.BABH6_JS) : path.join(ROOT, u.pathname);
      if (!fs.existsSync(file)) return route.fulfill({ status: 404, body: 'not found' });
      const ct = file.endsWith('.js') ? 'application/javascript' : file.endsWith('.css') ? 'text/css' : 'text/html; charset=utf-8';
      return route.fulfill({ status: 200, contentType: ct, body: fs.readFileSync(file) });
    }
    const p = u.pathname.slice('/api'.length);
    log.push(p + u.search);
    const json = (status, body) => route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(body) });
    if (p === '/stats') return json(200, stats);
    if (p.startsWith('/product/')) {
      const reg = decodeURIComponent(p.slice('/product/'.length));
      return DB[reg] ? json(200, DB[reg]) : json(404, { code: 'babh6_notfound', message: 'Няма намерен запис.', data: { status: 404 } });
    }
    if (p === '/products') {
      const q = (u.searchParams.get('q') || '').toLowerCase();
      if (q.length === 1) return json(400, { code: 'babh6_query_short', message: 'Въведи поне 2 знака.', data: { status: 400 } });
      const items = Object.values(DB).filter(it => !q || it.n.toLowerCase().includes(q) || it.c.toLowerCase().includes(q)).map(it => card(it.reg, it.n, it.nd));
      return json(200, { total: items.length, page: 1, per: 20, items });
    }
    return json(403, { code: 'babh6_pro', message: 'Pro функция.', data: { status: 403 } });
  });
}
module.exports = { install, DB };
