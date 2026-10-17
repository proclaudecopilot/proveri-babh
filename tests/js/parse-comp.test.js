// Поведенчески тест на parseComp от assets/babh6.js без браузър (node tests/js/parse-comp.test.js).
// Функцията е вътре в IIFE, затова се изрязва по маркери; ако маркерите се променят, тестът спира с ясна грешка.
'use strict';
const fs = require('fs'), path = require('path'), vm = require('vm');
const src = fs.readFileSync(path.join(__dirname, '..', '..', 'assets', 'babh6.js'), 'utf8');
const a = src.indexOf('var UNIT_RE'), b = src.indexOf('/* Съставка с автоматична бележка');
if (a < 0 || b < 0 || b < a) { console.error('parse-comp.test.js: маркерите UNIT_RE / „Съставка с автоматична бележка“ не са намерени в babh6.js'); process.exit(2); }
const ctx = {}; vm.createContext(ctx);
vm.runInContext(src.slice(a, b) + '\nthis.parseComp = parseComp;', ctx);
const parseComp = ctx.parseComp;

let pass = 0, fail = 0, xfail = 0, xpass = 0; const msgs = [];
const eq = (name, exp, act) => { if (JSON.stringify(exp) === JSON.stringify(act)) pass++; else { fail++; msgs.push('FAIL  ' + name + '\n      очаквано: ' + JSON.stringify(exp) + '\n      получено: ' + JSON.stringify(act)); } };
const xf = (task, name, exp, act) => { if (JSON.stringify(exp) === JSON.stringify(act)) { xpass++; msgs.push('XPASS ' + name + ' — ' + task + ' изглежда поправен: смени xf на eq'); } else { xfail++; msgs.push('xfail ' + name + ' (' + task + ') — очаквано ' + JSON.stringify(exp) + ', текущо ' + JSON.stringify(act)); } };

eq('празен състав → []', [], parseComp(''));
eq('разделител „;“ с кирилска единица', [{ name: 'Магнезий', amount: '187.5', unit: 'мг' }], parseComp('Магнезий 187,5 мг'));
eq('латинска единица (PR-12)', [{ name: 'Vitamin C', amount: '500', unit: 'mg' }], parseComp('Vitamin C 500 mg'));
eq('две позиции по „;“', ['Цинк', 'Селен'], parseComp('Цинк 15 мг; Селен 55 мкг').map(p => p.name));
eq('неразпознат сегмент остава цял, без измислено количество', [{ name: 'екстракт от зелен чай', amount: '', unit: '' }], parseComp('екстракт от зелен чай'));
eq('много дълъг текст без разделител не се реже', true, parseComp('а'.repeat(500))[0].raw === true);

/* LIVE-05 / ING-001: наблюдаваният на живо състав трябва да даде 3 позиции с decimal стойности и единица mg. */
const live05 = parseComp('Магнезий-187,5 мг, Витамин B6-1,05 мг, Ашваганда-74,5 мг');
xf('ING-001', 'LIVE-05: три позиции при разделител „,“ и тире преди числото', 3, live05.length);
xf('ING-001', 'LIVE-05: количествата са 187.5 / 1.05 / 74.5', ['187.5', '1.05', '74.5'], live05.map(p => p.amount));

msgs.forEach(m => console.log(m));
console.log('parse-comp.test.js: pass=' + pass + ' fail=' + fail + ' xfail=' + xfail + ' xpass=' + xpass);
process.exit(fail === 0 && xpass === 0 ? 0 : 1);
