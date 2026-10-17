'use strict';
/* LIVE-07 / UX-003: след търсене, филтър, подредба, смяна на екран или затваряне на детайла старият #p= не остава,
   затова презареждането не отваря стария продукт върху новите резултати.
   Истински браузър (Chromium през Playwright), реалните assets/babh6.js + babh6.css, REST API-то е stub (tests/browser/stub-api.js).
   Пускане: node tests/browser/live07-product-hash.test.js   (изисква playwright + Chromium; tests/run.sh го пропуска, ако липсват) */
const { chromium } = require('playwright');
const { install } = require('./stub-api');

const VIEWPORTS = [{ name: 'desktop 1280', width: 1280, height: 900 }, { name: 'phone 390', width: 390, height: 844 }];
let pass = 0, fail = 0; const msgs = [];
const eq = (name, exp, act) => { if (exp === act) pass++; else { fail++; msgs.push('FAIL  ' + name + '\n      очаквано: ' + JSON.stringify(exp) + '\n      получено: ' + JSON.stringify(act)); } };

async function scenario(browser, vp) {
  const ctx = await browser.newContext({ viewport: { width: vp.width, height: vp.height } });
  const page = await ctx.newPage();
  const log = [];
  await install(page, log);
  const tag = (n) => vp.name + ' · ' + n;
  const wait = (ms) => page.waitForTimeout(ms);
  /* пълно зареждане на споделен линк (през about:blank, за да не е само hashchange) */
  const openDeepLink = async (reg) => { await page.goto('about:blank'); await page.goto('http://babh6.test/harness.html#p=' + reg); await page.waitForSelector('#b6-dw.open', { timeout: 5000 }); };
  const closeDetail = async () => { await page.evaluate(() => { var x = document.querySelector('#b6-dw-x'); if (x) x.click(); }); await wait(350); };

  /* 1. Споделен линк отваря точния запис (PR-10 остава да работи) */
  await openDeepLink('%D0%A2162618382');
  eq(tag('deep link: заявка към /product/{reg}'), true, log.some(l => l.startsWith('/product/%D0%A2162618382')));
  eq(tag('deep link: детайлът показва записа'), true, (await page.textContent('#b6-dw-p')).includes('Магнезий + B6'));
  eq(tag('deep link: hash е #p='), '#p=%D0%A2162618382', await page.evaluate(() => location.hash));

  /* 2. Ново търсене маха стария #p= (LIVE-07) */
  await closeDetail();
  eq(tag('затваряне на детайла маха #p='), '', await page.evaluate(() => location.hash));
  await openDeepLink('%D0%A2162618382');
  await closeDetail();
  await page.fill('#b6-q', 'ашваганда');
  await wait(600); /* debounce 350 ms + заявка */
  eq(tag('търсене: заявка с q='), true, log.some(l => l.startsWith('/products') && l.includes('q=')));
  eq(tag('търсене: резултатът е новият продукт'), true, (await page.textContent('#b6-list')).includes('Ашваганда'));
  eq(tag('търсене: hash е празен'), '', await page.evaluate(() => location.hash));
  eq(tag('търсене: path/search са непроменени'), '/harness.html', await page.evaluate(() => location.pathname + location.search));

  /* 3. Презареждане след търсенето НЕ отваря стария продукт */
  log.length = 0;
  await page.reload();
  await page.waitForSelector('.b6-item', { timeout: 5000 });
  await wait(600);
  eq(tag('reload: няма заявка /product/'), false, log.some(l => l.startsWith('/product/')));
  eq(tag('reload: детайлът не е отворен'), false, await page.evaluate(() => !!document.querySelector('#b6-dw.open')));
  eq(tag('reload: hash е празен'), '', await page.evaluate(() => location.hash));

  /* 4. Смяна на подредбата и смяна на екран също махат #p= */
  await openDeepLink('%D0%A2162618382');
  await closeDetail();
  if (await page.$('#b6-sort')) {
    await page.selectOption('#b6-sort', 'new'); await wait(300);
    eq(tag('подредба: hash е празен'), '', await page.evaluate(() => location.hash));
  }
  await openDeepLink('%D0%A2162618382');
  await closeDetail();
  await page.evaluate(() => { document.querySelector('.b6-item[data-tab="overview"]').click(); });
  await wait(300);
  eq(tag('смяна на екран: hash е празен'), '', await page.evaluate(() => location.hash));

  /* 5. Споделеният линк към липсващ запис дава ясна грешка, не празен екран */
  await page.goto('about:blank'); await page.goto('http://babh6.test/harness.html#p=%D0%A2999999999');
  await wait(800);
  eq(tag('липсващ запис: обяснение'), true, (await page.textContent('#b6-list')).includes('Не е намерен запис'));

  await ctx.close();
}

(async () => {
  const browser = await chromium.launch();
  try {
    for (const vp of VIEWPORTS) await scenario(browser, vp);
  } catch (e) { fail++; msgs.push('FAIL  изключение: ' + (e && e.stack || e)); }
  await browser.close();
  msgs.forEach(m => console.log(m));
  console.log('live07-product-hash.test.js: pass=' + pass + ' fail=' + fail + ' (' + VIEWPORTS.map(v => v.name).join(', ') + ')');
  process.exit(fail ? 1 : 0);
})();
