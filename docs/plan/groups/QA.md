<!-- Генерирано от docs/plan/source/proveri-babh-full-plan-bg-v1.0.html (sha256 7d78033aea6368252c3e1d6dbb4af70699799d7a94c886416184d70cfb6c3c5d) чрез docs/plan/tools/build_backlog.py. Не редактирай на ръка. -->

# QA · Приемане, регресии и критерии за пускане

**Отговорност:** Независим QA / release owner  
**Обхват:** Доказателства за поведение; QA не се свежда до одобрение на собствения код.

| ID | Задача | Пр. | Етап | Вълна | Статус | Зависимости | Резултат |
|---|---|---|---|---|---|---|---|
| QA-001 | План за fixtures и очаквания | P0 | R0 | 3 | planned | ARC-002, ARC-004 | fixture-catalog.json и review log |
| QA-002 | Приемане на качеството на данните | P0 | R1 | 20 | planned | QA-001, XLS-012, NRM-010, ING-011, ENT-011 | data acceptance report |
| QA-003 | Failure injection на импорта | P0 | R1 | 12 | planned | SRC-008, DAT-008, QA-001 | import resilience report |
| QA-004 | E2E на основните сценарии | P1 | R3 | 24 | planned | APP-012, ANA-009, MON-003 | core E2E suite |
| QA-005 | Десктоп и мобилно визуално приемане | P1 | R3 | 24 | planned | APP-012, UX-011 | visual/device acceptance report |
| QA-006 | Достъпност с ръчна проверка | P1 | R3 | 24 | planned | UX-007, UX-011, APP-012 | a11y report |
| QA-007 | Административно приемане | P1 | R3 | 24 | planned | ADM-003, ADM-006, ADM-007, ADM-013, ADM-014 | admin UAT report |
| QA-008 | Миграция и възстановяване в staging | P0 | R3 | 12 | planned | OPS-005, OPS-006, OPS-007 | migration/restore evidence |
| QA-009 | Приемане на производителността | P1 | R3 | 24 | planned | OPS-011, APP-012 | performance acceptance |
| QA-010 | Gate за production core | P0 | R3 | 25 | planned | QA-002, QA-003, QA-004, QA-005, QA-006, QA-007, QA-008, QA-009, SEC-011 | CORE_RELEASE_CHECKLIST.md |
| QA-011 | Gate за PRO скелета | P0 | R4 | 26 | planned | MON-012, AI-007, AI-009, IAM-012, BIL-005, ANA-008 | PRO_READY_CHECKLIST.md |
| QA-012 | Gate за платено пускане | P0 | R5 | 28 | planned | QA-010, QA-011, BIL-016, SEC-010, OPS-013, ADM-011, QA-013 | PAID_RELEASE_CHECKLIST.md |
| QA-013 | Контролирано пилотно приемане | P1 | R5 | 27 | planned | QA-010, QA-011, BIL-016, SEC-010, OPS-013, ADM-011 | pilot UAT и signed findings |
| QA-014 | Наблюдение след пускане | P1 | R5 | 29 | planned | QA-012 | post-launch checklist и review dates |

## QA-001 · План за fixtures и очаквания

Опиши реални, synthetic и holdout набори; независим reviewer утвърждава expected results и source provenance.

- **Приоритет / етап / вълна:** P0 / R0 / 3
- **Резултат:** fixture-catalog.json и review log
- **Зависимости:** ARC-002, ARC-004
- **Основание:** DESIGN · покрива: DESIGN
- **Статус:** planned

**Приемане**

- Няма expected резултат, генериран само от тествания parser
- Има случаи за всички рискови категории

**Конкретна проверка:** Ръчно проследи случайна извадка до оригиналните клетки.

## QA-002 · Приемане на качеството на данните

Измери exactness, coverage, abstentions, false merges и reconciliation; отдели source errors от parser errors.

- **Приоритет / етап / вълна:** P0 / R1 / 20
- **Резултат:** data acceptance report
- **Зависимости:** QA-001, XLS-012, NRM-010, ING-011, ENT-011
- **Основание:** DESIGN · покрива: DESIGN
- **Статус:** planned

**Приемане**

- 100% raw provenance и row accounting
- Нула silent ID/date/unit errors в critical corpus

**Конкретна проверка:** Независим holdout включва формати и фирми извън разработващия corpus.

## QA-003 · Failure injection на импорта

Прекъсни download, parse, SQL, validation, publish и worker lease; проверявай active dataset след всяко прекъсване.

- **Приоритет / етап / вълна:** P0 / R1 / 12
- **Резултат:** import resilience report
- **Зависимости:** SRC-008, DAT-008, QA-001
- **Основание:** IM-02, IM-04, IM-05 · покрива: IM-02, IM-04, IM-05
- **Статус:** planned

**Приемане**

- Активният набор остава цял и консистентен
- Retry е детерминистичен без дублиране

**Конкретна проверка:** Два workers, SQL отказ и stale worker след lease takeover.

## QA-004 · E2E на основните сценарии

Покрий search→filter→detail→company→export→back и history; асертирай IDs, counts, URL и context.

- **Приоритет / етап / вълна:** P1 / R3 / 24
- **Резултат:** core E2E suite
- **Зависимости:** APP-012, ANA-009, MON-003
- **Основание:** DESIGN · покрива: DESIGN
- **Статус:** planned

**Приемане**

- Работят empty/error/retry и директни URL
- Не се проверява само наличие на бутон

**Конкретна проверка:** Търси, отвори детайл, смени фирма, върни се и reload.

## QA-005 · Десктоп и мобилно визуално приемане

Снимки на всички екрани/състояния по viewport matrix; реални iOS Safari и Android Chrome за критичните потоци.

- **Приоритет / етап / вълна:** P1 / R3 / 24
- **Резултат:** visual/device acceptance report
- **Зависимости:** APP-012, UX-011
- **Основание:** DESIGN · покрива: DESIGN
- **Статус:** planned

**Приемане**

- Няма overlap, clip, unreadable controls или page overflow
- Тестовите устройство/browser версии са записани

**Конкретна проверка:** 320px, landscape, виртуална клавиатура, notch и дълги български стойности.

## QA-006 · Достъпност с ръчна проверка

Автоматизиран scan плюс keyboard, screen reader, contrast, zoom/reflow и form errors към WCAG 2.2 AA target.

- **Приоритет / етап / вълна:** P1 / R3 / 24
- **Резултат:** a11y report
- **Зависимости:** UX-007, UX-011, APP-012
- **Основание:** DESIGN · покрива: DESIGN
- **Статус:** planned

**Приемане**

- Критичните сценарии са изпълними без мишка
- Автоматичен pass не замества ръчните проверки

**Конкретна проверка:** VoiceOver/NVDA сценарий за search, modal, error и export.

## QA-007 · Административно приемане

Оператор изпълнява import preview, quarantine, correction, merge/split, publish и rollback с различни роли.

- **Приоритет / етап / вълна:** P1 / R3 / 24
- **Резултат:** admin UAT report
- **Зависимости:** ADM-003, ADM-006, ADM-007, ADM-013, ADM-014
- **Основание:** DESIGN · покрива: DESIGN
- **Статус:** planned

**Приемане**

- Всяко действие има правилен audit и permissions
- Операторът разбира защо publish е блокиран

**Конкретна проверка:** Двама reviewers и конфликтна корекция след нов source import.

## QA-008 · Миграция и възстановяване в staging

Изпълни upgrade от потвърдената текуща версия със sanitized snapshot и отделно restore drill.

- **Приоритет / етап / вълна:** P0 / R3 / 12
- **Резултат:** migration/restore evidence
- **Зависимости:** OPS-005, OPS-006, OPS-007
- **Основание:** DESIGN · покрива: DESIGN
- **Статус:** planned

**Приемане**

- Няма загубени текущи регистрации без изрично обяснение
- Старите deep links са mapping-tested

**Конкретна проверка:** Legacy #p/#f адреси след миграция и прекъсната migration стъпка.

## QA-009 · Приемане на производителността

Измери договорени API/import/export budgets и web vitals; field p75 и lab simulation се отчитат отделно.

- **Приоритет / етап / вълна:** P1 / R3 / 24
- **Резултат:** performance acceptance
- **Зависимости:** OPS-011, APP-012
- **Основание:** DESIGN · покрива: DESIGN
- **Статус:** planned

**Приемане**

- Показани са среда, sample size, p95 и cold/warm условия
- Не се твърди полево качество само от Lighthouse

**Конкретна проверка:** Слаб мобилен профил и concurrent import/exports на staging.

## QA-010 · Gate за production core

Провери всички invariants, blocker matrix, rollback, operational ownership и evidence; unsigned/unknown не е pass.

- **Приоритет / етап / вълна:** P0 / R3 / 25
- **Резултат:** CORE_RELEASE_CHECKLIST.md
- **Зависимости:** QA-002, QA-003, QA-004, QA-005, QA-006, QA-007, QA-008, QA-009, SEC-011
- **Основание:** DESIGN · покрива: DESIGN
- **Статус:** planned

**Приемане**

- Няма отворен Sev0/Sev1/Sev2 в core scope
- Неизмерени parser/mobile рискове блокират заявка за готовност

**Конкретна проверка:** Независим release owner проследява всеки критерий до отчет.

## QA-011 · Gate за PRO скелета

Добави нова тестова capability през catalog; докажи roles, quotas, tenant isolation и degradation без плащане.

- **Приоритет / етап / вълна:** P0 / R4 / 26
- **Резултат:** PRO_READY_CHECKLIST.md
- **Зависимости:** MON-012, AI-007, AI-009, IAM-012, BIL-005, ANA-008
- **Основание:** DESIGN · покрива: DESIGN
- **Статус:** planned

**Приемане**

- Нова функция не изисква промяна на всеки endpoint
- Paid-only output е защитен и при директни заявки

**Конкретна проверка:** FREE→admin grant→expiry с pending watch, export и AI request.

## QA-012 · Gate за платено пускане

Приеми sandbox lifecycle, финални търговски решения, tax/invoice review, support и конкретен live rollout plan.

- **Приоритет / етап / вълна:** P0 / R5 / 28
- **Резултат:** PAID_RELEASE_CHECKLIST.md
- **Зависимости:** QA-010, QA-011, BIL-016, SEC-010, OPS-013, ADM-011, QA-013
- **Основание:** DESIGN · покрива: DESIGN
- **Статус:** planned

**Приемане**

- Няма отворен Sev0/Sev1/Sev2 в платените потоци
- Live credentials/charges/deploy са отделна финална стъпка

**Конкретна проверка:** Репетиция на launch и rollback без реално таксуване.

## QA-013 · Контролирано пилотно приемане

Представители на производител и търговец изпълняват задачи в staging с реалистични данни и sandbox плащания; измери завършване/грешки и коригирай blockers преди финалния paid gate.

- **Приоритет / етап / вълна:** P1 / R5 / 27
- **Резултат:** pilot UAT и signed findings
- **Зависимости:** QA-010, QA-011, BIL-016, SEC-010, OPS-013, ADM-011
- **Основание:** DESIGN · покрива: DESIGN
- **Статус:** planned

**Приемане**

- Основните сценарии се завършват без помощ при предварително описан протокол
- Всеки дефект има severity/owner и повторна проверка

**Конкретна проверка:** Намери регистрация, проследи фирма, свали отчет и управлявай план без реално таксуване.

## QA-014 · Наблюдение след пускане

Провери SLO, грешки, качество на import и billing reconciliation през първите 7/30 дни; без обещание за бъдеща липса на дефекти.

- **Приоритет / етап / вълна:** P1 / R5 / 29
- **Резултат:** post-launch checklist и review dates
- **Зависимости:** QA-012
- **Основание:** DESIGN · покрива: DESIGN
- **Статус:** planned

**Приемане**

- Инцидентите водят до регресии и конкретни действия
- Не се обявява финален operational sign-off без измерени данни

**Конкретна проверка:** Първи реален нов BABH пакет и първи renewal се проследяват end-to-end.
