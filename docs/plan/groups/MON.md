<!-- Генерирано от docs/plan/source/proveri-babh-full-plan-bg-v1.0.html (sha256 7d78033aea6368252c3e1d6dbb4af70699799d7a94c886416184d70cfb6c3c5d) чрез docs/plan/tools/build_backlog.py. Не редактирай на ръка. -->

# MON · История, наблюдение и известия

**Отговорност:** Backend event/notification инженер  
**Обхват:** Публична история и частни watches споделят event model, но имат различен достъп.

| ID | Задача | Пр. | Етап | Вълна | Статус | Зависимости | Резултат |
|---|---|---|---|---|---|---|---|
| MON-001 | Единен журнал на промените | P0 | R3 | 10 | planned | DAT-003, DAT-006 | change event schema |
| MON-002 | Transactional outbox | P0 | R3 | 11 | planned | MON-001, DAT-005 | event outbox worker |
| MON-003 | Екран Промени | P1 | R3 | 11 | planned | MON-001, UX-009 | change feed UI/API |
| MON-004 | Запазени търсения | P1 | R4 | 20 | planned | API-004, IAM-006, BIL-004 | saved search service/UI |
| MON-005 | Следене на обекти и заявки | P1 | R4 | 21 | planned | MON-004, ENT-012 | watch service/UI |
| MON-006 | Оценка на watches при нова версия | P0 | R4 | 22 | planned | MON-002, MON-005 | watch evaluator |
| MON-007 | Digest и доставка | P1 | R4 | 23 | planned | MON-006 | notification delivery pipeline |
| MON-008 | Един център за известия | P1 | R4 | 24 | planned | MON-007, UX-005 | notification center |
| MON-009 | Отписване и предпочитания | P0 | R4 | 24 | planned | MON-007, IAM-006 | notification preferences |
| MON-010 | Поведение при загубен PRO достъп | P1 | R4 | 22 | planned | MON-005, BIL-003, BIL-004 | watch entitlement policy |
| MON-011 | Завършена заявка за интерес | P1 | R3 | 7 | planned | UX-005, API-001 | waitlist service/admin UI |
| MON-012 | Приемане на известията | P0 | R4 | 25 | planned | MON-008, MON-009, MON-010 | notification acceptance report |

## MON-001 · Единен журнал на промените

Събития source_added, source_changed, not_seen, reappeared и explicit_note; derivation changes са отделни.

- **Приоритет / етап / вълна:** P0 / R3 / 10
- **Резултат:** change event schema
- **Зависимости:** DAT-003, DAT-006
- **Основание:** IM-10, WL-04 · покрива: WL-04, IM-10
- **Статус:** planned

**Приемане**

- Observed time и source effective date са различни
- Липса не е административно заличаване

**Конкретна проверка:** Поле се променя, запис изчезва и се връща в три пълни снимки.

## MON-002 · Transactional outbox

Записвай publish и pending event batch атомарно; dispatcher има retries и semantic idempotency.

- **Приоритет / етап / вълна:** P0 / R3 / 11
- **Резултат:** event outbox worker
- **Зависимости:** MON-001, DAT-005
- **Основание:** DESIGN · покрива: DESIGN
- **Статус:** planned

**Приемане**

- Няма изгубено събитие след успешен publish
- Retry/rollback/republication не изпраща дубликати

**Конкретна проверка:** Crash след commit преди dispatch, после повторен worker.

## MON-003 · Екран Промени

Филтри по период, тип, фирма и поле; before/after с източник и непълни данни; достъпен от детайл.

- **Приоритет / етап / вълна:** P1 / R3 / 11
- **Резултат:** change feed UI/API
- **Зависимости:** MON-001, UX-009
- **Основание:** LIVE-11 · покрива: LIVE-11
- **Статус:** planned

**Приемане**

- Текстът описва реалната промяна, не бизнес догадка
- Страницирането пази стабилен scope

**Конкретна проверка:** Само trader поле се сменя и е видимо в feed.

## MON-004 · Запазени търсения

Съхранявай query AST/schema version, име, owner/organization и visibility; мигрирай стари филтри.

- **Приоритет / етап / вълна:** P1 / R4 / 20
- **Резултат:** saved search service/UI
- **Зависимости:** API-004, IAM-006, BIL-004
- **Основание:** DESIGN · покрива: DESIGN
- **Статус:** planned

**Приемане**

- Споделеното търсене не изтича извън организацията
- Невалиден стар филтър се показва, не се игнорира

**Конкретна проверка:** Изтрита категория при запазено търсене от предходна версия.

## MON-005 · Следене на обекти и заявки

Един Watch модел за регистрация, фирма, съставка и query; настройки за събития, период и канал.

- **Приоритет / етап / вълна:** P1 / R4 / 21
- **Резултат:** watch service/UI
- **Зависимости:** MON-004, ENT-012
- **Основание:** DESIGN · покрива: DESIGN
- **Статус:** planned

**Приемане**

- Повторно включване не създава duplicate watch
- Квотата се проверява на сървъра

**Конкретна проверка:** Следи фирма като manufacturer и trader без два еднакви сигнала.

## MON-006 · Оценка на watches при нова версия

Сравнявай old/new match sets; първоначалното записване създава baseline без лавина от historical alerts.

- **Приоритет / етап / вълна:** P0 / R4 / 22
- **Резултат:** watch evaluator
- **Зависимости:** MON-002, MON-005
- **Основание:** DESIGN · покрива: DESIGN
- **Статус:** planned

**Приемане**

- Нова watch не изпраща 80k стари записи
- Излизане от query и промяна в поле са различими събития

**Конкретна проверка:** Категорията на запис се променя само поради нов classifier.

## MON-007 · Digest и доставка

Timezone-aware frequency, dedup key, retries/backoff, provider status и DLQ; email accepted не значи delivered.

- **Приоритет / етап / вълна:** P1 / R4 / 23
- **Резултат:** notification delivery pipeline
- **Зависимости:** MON-006
- **Основание:** DESIGN · покрива: DESIGN
- **Статус:** planned

**Приемане**

- Повторен retry не изпраща duplicate digest
- Недоставеното се вижда и може да се обработи

**Конкретна проверка:** Provider timeout след приемане и повторно изпълнение.

## MON-008 · Един център за известия

Покажи inbox, read state, следени обекти и настройки; унифицирай Промени и Известия като части на Наблюдение.

- **Приоритет / етап / вълна:** P1 / R4 / 24
- **Резултат:** notification center
- **Зависимости:** MON-007, UX-005
- **Основание:** DESIGN · покрива: DESIGN
- **Статус:** planned

**Приемане**

- Едно събитие има една идентичност във feed/digest/inbox
- Unread count съвпада с реалните записи

**Конкретна проверка:** Маркирай прочетено на едно устройство и отвори на друго.

## MON-009 · Отписване и предпочитания

Отдели service/billing/security от избираеми watch/marketing съобщения; scoped signed unsubscribe и preference audit.

- **Приоритет / етап / вълна:** P0 / R4 / 24
- **Резултат:** notification preferences
- **Зависимости:** MON-007, IAM-006
- **Основание:** DESIGN · покрива: DESIGN
- **Статус:** planned

**Приемане**

- Unsubscribe не изисква разкриване на чужд акаунт
- Не се изпраща watch email след отписване

**Конкретна проверка:** Стар unsubscribe линк и премахнат organization membership.

## MON-010 · Поведение при загубен PRO достъп

Запази watch definitions според retention; паузирай premium изпълнение и ясно покажи paused/over-limit; в R4 провери чрез временни grants, а с реален billing adapter в R5.

- **Приоритет / етап / вълна:** P1 / R4 / 22
- **Резултат:** watch entitlement policy
- **Зависимости:** MON-005, BIL-003, BIL-004
- **Основание:** DESIGN · покрива: DESIGN
- **Статус:** planned

**Приемане**

- Не се изпращат premium данни след отнемане на право
- Upgrade не повтаря всички пропуснати събития без избор

**Конкретна проверка:** Downgrade по време на подготвен digest.

## MON-011 · Завършена заявка за интерес

Ако остава waitlist: един компонент, много feature interests на email, explicit consent, admin lifecycle и correct errors.

- **Приоритет / етап / вълна:** P1 / R3 / 7
- **Резултат:** waitlist service/admin UI
- **Зависимости:** UX-005, API-001
- **Основание:** WL-01, WL-02, WL-03 · покрива: WL-01, WL-02, WL-03
- **Статус:** planned

**Приемане**

- Втори интерес със същия email се запазва
- DB/мрежов отказ не се описва като невалиден email

**Конкретна проверка:** Един email заявява две функции и после оттегля едната.

## MON-012 · Приемане на известията

Тествай с mail sink, publication retries, DST и tenancy; измери latency и replay behavior.

- **Приоритет / етап / вълна:** P0 / R4 / 25
- **Резултат:** notification acceptance report
- **Зависимости:** MON-008, MON-009, MON-010
- **Основание:** DESIGN · покрива: DESIGN
- **Статус:** planned

**Приемане**

- Нула duplicate deliveries в контролирания suite
- Нито едно съобщение не съдържа данни от чужда организация

**Конкретна проверка:** Два tenant-а със сходни watches и повторен batch.
