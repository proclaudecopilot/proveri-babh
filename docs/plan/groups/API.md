<!-- Генерирано от docs/plan/source/proveri-babh-full-plan-bg-v1.0.html (sha256 7d78033aea6368252c3e1d6dbb4af70699799d7a94c886416184d70cfb6c3c5d) чрез docs/plan/tools/build_backlog.py. Не редактирай на ръка. -->

# API · Търсене, филтри, договори и експорт

**Отговорност:** Backend API инженер  
**Обхват:** Един query service за списъци, KPI, saved searches и export.

| ID | Задача | Пр. | Етап | Вълна | Статус | Зависимости | Резултат |
|---|---|---|---|---|---|---|---|
| API-001 | Версиониран REST слой | P0 | R1 | 6 | planned | ARC-005, DAT-002 | REST controllers и OpenAPI |
| API-002 | Единна семантика на търсене | P1 | R2 | 13 | planned | API-001, NRM-005 | search query service |
| API-003 | Договор за празни и невалидни заявки | P1 | R2 | 14 | planned | API-002 | query validation и error map |
| API-004 | Контекстни facets | P1 | R2 | 19 | planned | API-002, ENT-007 | facet aggregation |
| API-005 | Стабилно сортиране и странициране | P1 | R2 | 14 | planned | API-002 | pagination contract |
| API-006 | Детайл с произход и качество | P0 | R2 | 10 | planned | API-001, DAT-006, DAT-007 | registration detail endpoint |
| API-007 | Възстановими списъци | P1 | R2 | 15 | planned | API-005 | list error contract |
| API-008 | Единен обхват на експорта | P1 | R2 | 20 | planned | API-004, API-005 | export contract и preview count |
| API-009 | Фонови големи експорти | P1 | R2 | 21 | planned | API-008, SRC-006 | export worker |
| API-010 | Безопасен и самостоятелен CSV | P0 | R2 | 21 | planned | API-008 | CSV serializer и export metadata |
| API-011 | Изолирани откази и cache contract | P1 | R2 | 10 | planned | API-001, DAT-010 | resource cache contract |
| API-012 | Профилиране и ограничения на API | P1 | R3 | 20 | planned | API-004, API-005, ENT-010 | API benchmark и limits |

## API-001 · Версиониран REST слой

Регистрирай routes с schema validation, permission callback, стандартизирани errors и correlation ID; поддържай WP fallback route.

- **Приоритет / етап / вълна:** P0 / R1 / 6
- **Резултат:** REST controllers и OpenAPI
- **Зависимости:** ARC-005, DAT-002
- **Основание:** AU-03 · покрива: AU-03
- **Статус:** planned

**Приемане**

- GET/POST имат единна authentication/permission политика
- Грешка не връща HTML вместо договорения JSON

**Конкретна проверка:** Тествай pretty permalinks включени и изключени.

## API-002 · Единна семантика на търсене

Дефинирай exact registration, име, фирма, съставка, кирилица/латиница и token behavior; fulltext/fallback имат еднакви обещания.

- **Приоритет / етап / вълна:** P1 / R2 / 13
- **Резултат:** search query service
- **Зависимости:** API-001, NRM-005
- **Основание:** PR-04, LIVE-04 · покрива: LIVE-04, PR-04
- **Статус:** planned

**Приемане**

- Exact номер е предвидим и при кратка заявка
- Fallback не променя тайно обхвата

**Конкретна проверка:** магнезий, ашваганда, ashvaganda, Т162618382 и еднобуквен input.

## API-003 · Договор за празни и невалидни заявки

Опиши empty query, minimum text length, invalid filters, timeout и no results с ясни status codes.

- **Приоритет / етап / вълна:** P1 / R2 / 14
- **Резултат:** query validation и error map
- **Зависимости:** API-002
- **Основание:** PR-05 · покрива: PR-05
- **Статус:** planned

**Приемане**

- Нула резултата не е техническа грешка
- Невалиден филтър не се игнорира тихо

**Конкретна проверка:** Празна заявка, неизвестна категория, 500 и 429.

## API-004 · Контекстни facets

Брой facets в текущия dataset и query scope; за текущия facet изключвай собственото му ограничение по документирано правило.

- **Приоритет / етап / вълна:** P1 / R2 / 19
- **Резултат:** facet aggregation
- **Зависимости:** API-002, ENT-007
- **Основание:** PR-06, PR-08, LIVE-14 · покрива: LIVE-14, PR-06, PR-08
- **Статус:** planned

**Приемане**

- Counts се преизчисляват с търсенето
- Multi-select union/intersection е описан и еднакъв в UI/API

**Конкретна проверка:** Търси магнезий, избери две категории и сравни count с резултатите.

## API-005 · Стабилно сортиране и странициране

Потребителската сортировка има предимство; cursor включва dataset/query/sort и deterministic tie-breaker.

- **Приоритет / етап / вълна:** P1 / R2 / 14
- **Резултат:** pagination contract
- **Зависимости:** API-002
- **Основание:** PR-09 · покрива: PR-09
- **Статус:** planned

**Приемане**

- Няма повторени/пропуснати записи между страници
- Стар cursor не се използва върху нов dataset без контрол

**Конкретна проверка:** Еднакви дати на 200 записи, публикация между page 1 и 2.

## API-006 · Детайл с произход и качество

Върни всички полезни source fields, parsed values, source row, flags и correction context; скрий само неразрешени частни данни.

- **Приоритет / етап / вълна:** P0 / R2 / 10
- **Резултат:** registration detail endpoint
- **Зависимости:** API-001, DAT-006, DAT-007
- **Основание:** PR-11, PR-13 · покрива: PR-11, PR-13
- **Статус:** planned

**Приемане**

- Източник и предположение са различими машинно
- Наличната стойност не се губи поради UI truncation

**Конкретна проверка:** Дълъг състав, country-only producer и manual correction.

## API-007 · Възстановими списъци

Next-page retry запазва query и cursor; client получава retryable flag и timeout context.

- **Приоритет / етап / вълна:** P1 / R2 / 15
- **Резултат:** list error contract
- **Зависимости:** API-005
- **Основание:** CO-02 · покрива: CO-02
- **Статус:** planned

**Приемане**

- Провалена страница не изтрива заредените резултати
- Retry не прескача и не дублира страница

**Конкретна проверка:** Откажи третата страница веднъж, после върни успешен отговор.

## API-008 · Единен обхват на експорта

Export request съдържа точните query, sort, dataset, columns и all/selected/page scope; лимитът е видим преди старта.

- **Приоритет / етап / вълна:** P1 / R2 / 20
- **Резултат:** export contract и preview count
- **Зависимости:** API-004, API-005
- **Основание:** EX-01, LIVE-12 · покрива: LIVE-12, EX-01
- **Статус:** planned

**Приемане**

- Експортът не означава неявно само loaded rows
- Ограничен план съобщава лимита и реалния count

**Конкретна проверка:** Фирмен списък с 24 заредени реда и 2615 общо.

## API-009 · Фонови големи експорти

Изпълнявай chunked export с immutable query и version; job има progress, expiry и authorized download.

- **Приоритет / етап / вълна:** P1 / R2 / 21
- **Резултат:** export worker
- **Зависимости:** API-008, SRC-006
- **Основание:** EX-01, EX-03 · покрива: EX-01, EX-03
- **Статус:** planned

**Приемане**

- Повторен request_id не прави два export job-а
- Отказан/изтекъл файл не е публично достъпен

**Конкретна проверка:** 50k реда, прекъсване на worker и възстановяване.

## API-010 · Безопасен и самостоятелен CSV

Сериализирай UTF-8, quotes, delimiters, newlines и spreadsheet formula mitigation; metadata е отделен README/manifest.

- **Приоритет / етап / вълна:** P0 / R2 / 21
- **Резултат:** CSV serializer и export metadata
- **Зависимости:** API-008
- **Основание:** EX-02, EX-03 · покрива: EX-02, EX-03
- **Статус:** planned

**Приемане**

- =,+,-,@ и leading controls не изпълняват формули
- Файлът съдържа raw/derived разграничение, scope и версия

**Конкретна проверка:** Клетки с formula payload, кавички, нов ред и кирилица се отварят безопасно.

## API-011 · Изолирани откази и cache contract

Раздели ресурси и stale/error policy; отказ на overview не подменя активен product screen.

- **Приоритет / етап / вълна:** P1 / R2 / 10
- **Резултат:** resource cache contract
- **Зависимости:** API-001, DAT-010
- **Основание:** PR-15 · покрива: PR-15
- **Статус:** planned

**Приемане**

- Stale data е означена с версия/дата
- Грешка в един endpoint не изтрива независим резултат

**Конкретна проверка:** 500 от statistics по време на работещо търсене.

## API-012 · Профилиране и ограничения на API

Измери queries, plans, rate/concurrency limits и скъпите комбинации; лимитите връщат полезен retry ответ.

- **Приоритет / етап / вълна:** P1 / R3 / 20
- **Резултат:** API benchmark и limits
- **Зависимости:** API-004, API-005, ENT-010
- **Основание:** DESIGN · покрива: DESIGN
- **Статус:** planned

**Приемане**

- Покрит е договореният p95 бюджет при конкретен товар
- Няма неограничен wildcard/regex/offset обход

**Конкретна проверка:** 200k записа, 50 паралелни четения и активен import.
