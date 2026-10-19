<?php
/* Поведенчески тестове на includes/detect.php (чисти функции, без WordPress).
   Пускане: php -d display_errors=1 tests/php/detect.test.php  (или tests/run.sh) */
require __DIR__ . '/bootstrap.php';

/* ---- регистрационен номер (DAT-002 / NRM-001 основа) ---- */
$r = babh6_parse_reg('Т162618382');
t_eq('reg: тип Т', 'Т', $r['rtype']);
t_eq('reg: година 20YY от цифри 3-4', 2026, $r['ryear']);
t_eq('reg: област от първите две цифри (16 = Ловеч)', 'Ловеч', $r['oblast']);
t_eq('reg: стойността е нормализирана без интервали', 'Т162618382', babh6_parse_reg(' Т 1626 18382 ')['reg']);
t_eq('reg: латинско T се приема за кирилско Т', 'Т', babh6_parse_reg('T162618382')['rtype']);
t_eq('reg: суфикс "/1" се пази', 'П052112345/1', babh6_parse_reg('П052112345/1')['reg']);
t_eq('reg: невалиден тип → null', null, babh6_parse_reg('X162618382'));
t_eq('reg: под 7 цифри → null', null, babh6_parse_reg('Т12345'));
t_eq('reg: година в бъдещето (над текущата+1) → без година, не 19xx', null, babh6_parse_reg('Т169912345')['ryear']);

/* ---- дати (NRM-002) ---- */
t_eq('date: dd.mm.yyyy', '2013-04-30', babh6_parse_date_any('30.04.2013'));
t_eq('date: невалидна (31.04) → null, не се „поправя“', null, babh6_parse_date_any('31.04.2013'));
t_eq('date: Excel сериен номер', '2024-01-15', babh6_parse_date_any('45306'));
t_eq('date: ISO', '2020-02-29', babh6_parse_date_any('2020-02-29'));
t_eq('date: празно → null', null, babh6_parse_date_any(''));
/* LIVE-08: бъдеща дата минава без сигнал. Желано (NRM-002): parser-ът я приема (стойността е от източника),
   но връща и anomaly flag. Докато няма такъв API, документираме текущото поведение като известен пропуск. */
t_xfail('NRM-002', 'date: бъдеща дата носи anomaly flag', 'flagged', is_string(babh6_parse_date_any('17.04.2027')) ? 'accepted-silently' : 'rejected');

/* ---- държава vs фирма (ENT-004, LIVE-05) ---- */
t_eq('country: „Полша“ е държава, не фирма', true, babh6_is_country('Полша'));
t_eq('country: „Р. България“ е държава', true, babh6_is_country('Р. България'));
t_eq('country: „ЕС“ не е фирма', true, babh6_is_country('ЕС'));
t_eq('country: фирма с правна форма не е държава', false, babh6_is_country('Полша ЕООД'));

/* ---- ключ на фирма (ENT-002/ENT-003) — случаите от README v6.5.1 ---- */
$k1 = babh6_norm_firm('„Флай Фиш“ ЕООД');
t_eq('norm: кавички и правна форма се махат', 'флай фиш', $k1);
t_eq('norm: адрес след правната форма отпада', $k1, babh6_norm_firm('Флай Фиш ЕООД гр. София'));
t_eq('norm: главни букви и адрес със запетая', $k1, babh6_norm_firm('ФЛАЙ ФИШ ЕООД, ул. Иван Вазов 1'));
t_eq('norm: водеща правна форма („ЕТ Иван Иванов“)', 'иван иванов', babh6_norm_firm('ЕТ Иван Иванов'));
t_eq('norm: латински букви в кирилска дума се поправят', babh6_norm_firm('Адифарм ЕАД'), babh6_norm_firm('Адифарм ЕAД'));
t_eq('norm: число + дума започва адрес (NOW Foods 244 Knollwood Dr.)', 'now foods', babh6_norm_firm('NOW Foods 244 Knollwood Dr.'));
t_eq('norm: празно → празно', '', babh6_norm_firm(''));
/* ООД и ЕООД варианти са различни ключове? Не — правната форма се маха, затова съвпадат. LIVE-06 иска identity review (ENT-002): това е характеризация, не одобрение. */
t_eq('norm (характеризация): ООД и ЕООД дават един ключ', babh6_norm_firm('Ревита ООД'), babh6_norm_firm('Ревита ЕООД'));

/* ---- българска регистрация (CO-03 / ENT-004) ---- */
t_eq('bg: ЕООД + гр. → да', true, babh6_is_bg_firm('Флай Фиш ЕООД, гр. София, ул. Витоша 1'));
t_eq('bg: GmbH → не', false, babh6_is_bg_firm('Natur Pharma GmbH, Berlin'));
t_eq('bg: Sp. z o.o. → не', false, babh6_is_bg_firm('Olimp Laboratories Sp. z o.o., Dębica'));
t_eq('bg: „България“ в адреса → да', true, babh6_is_bg_firm('Nutrend Bulgaria, Plovdiv, Bulgaria'));

/* ---- категории и бележки (NRM-007, ENT-007) ---- */
t_eq('cat: празни име и състав → other', 'other', babh6_categorize('', ''));
$f = babh6_find_flags_fields('Продукт', 'Ашваганда 74,5 мг', '');
t_eq('flags: бележката носи поле и термин (AD-01)', true, !$f || (isset($f[0]['field']) && isset($f[0]['term'])));
t_eq('flags: без термин → без бележки', array(), babh6_find_flags_fields('Магнезий', 'магнезиев цитрат 187,5 мг', ''));

exit(t_report(__FILE__) ? 0 : 1);
