<!-- Генерирано от docs/plan/source/proveri-babh-full-plan-bg-v1.0.html (sha256 7d78033aea6368252c3e1d6dbb4af70699799d7a94c886416184d70cfb6c3c5d) чрез docs/plan/tools/build_backlog.py. Не редактирай на ръка. -->

# ADM · Администрация и контрол на данните

**Отговорност:** Admin frontend + backend инженер  
**Обхват:** Admin console върху същите domain services; не директни ad hoc SQL редакции.

| ID | Задача | Пр. | Етап | Вълна | Статус | Зависимости | Резултат |
|---|---|---|---|---|---|---|---|
| ADM-001 | Структура на администрацията | P1 | R3 | 7 | planned | ARC-006, API-001 | admin navigation и permissions map |
| ADM-002 | Оперативен обзор | P1 | R3 | 14 | planned | ADM-001, SRC-012, NRM-007 | admin overview |
| ADM-003 | Wizard за импорт и preview | P0 | R3 | 12 | planned | ADM-001, SRC-008 | import wizard |
| ADM-004 | Управление на jobs | P1 | R3 | 13 | planned | ADM-001, SRC-010 | jobs console |
| ADM-005 | Работна опашка за качество | P0 | R3 | 14 | planned | ADM-001, NRM-008, DAT-001 | quarantine/review console |
| ADM-006 | Корекции с преглед и история | P0 | R3 | 15 | planned | ADM-005, DAT-007, NRM-009 | correction workflow |
| ADM-007 | Конзола за идентичности | P0 | R3 | 16 | planned | ADM-005, ENT-003 | entity review UI |
| ADM-008 | Управление на речници и правила | P1 | R3 | 20 | planned | ADM-001, ENT-008, SRC-011 | rules console |
| ADM-009 | Потребители, екипи и поддръжка | P1 | R4 | 8 | planned | ADM-001, IAM-004, IAM-006 | account support console |
| ADM-010 | Каталог на функции и PRO достъп | P1 | R4 | 10 | planned | ADM-001, BIL-004 | entitlements admin UI |
| ADM-011 | Плащания и разминавания | P0 | R5 | 11 | planned | ADM-001, BIL-008, BIL-013 | billing support console |
| ADM-012 | Настройки без връщане на секрети | P0 | R3 | 8 | planned | ADM-001, SEC-005 | settings UI |
| ADM-013 | Миграции, версии и възстановяване | P1 | R3 | 11 | planned | ADM-001, DAT-008, OPS-005 | system lifecycle console |
| ADM-014 | Журнал, сигнали и review SLA | P1 | R3 | 23 | planned | ADM-006, APP-011, SEC-007 | audit и issue console |

## ADM-001 · Структура на администрацията

Раздели Обзор, Източници/импорти, Качество, Речници/фирми, Потребители/организации, Планове/плащания, Система/одит.

- **Приоритет / етап / вълна:** P1 / R3 / 7
- **Резултат:** admin navigation и permissions map
- **Зависимости:** ARC-006, API-001
- **Основание:** AD-03 · покрива: AD-03
- **Статус:** planned

**Приемане**

- Всяка операция има един основен екран
- Скрита функция няма достъпен незащитен endpoint

**Конкретна проверка:** Провери карта на всички стари admin контроли към новите раздели.

## ADM-002 · Оперативен обзор

Покажи active dataset, source freshness, jobs, quarantine, failed deliveries и system dependencies; health checks са независими.

- **Приоритет / етап / вълна:** P1 / R3 / 14
- **Резултат:** admin overview
- **Зависимости:** ADM-001, SRC-012, NRM-007
- **Основание:** AD-01, AD-05 · покрива: AD-01, AD-05
- **Статус:** planned

**Приемане**

- 401 от защитен endpoint не се отчита като недостъпна услуга
- Един бавен health check не блокира целия екран

**Конкретна проверка:** Липсващ ZIP/XML, неработещ scheduler и недостъпен AI provider.

## ADM-003 · Wizard за импорт и preview

Стъпки избор на пакет, manifest/schema, dry run, diff, review и publish; покажи точно какво ще се промени.

- **Приоритет / етап / вълна:** P0 / R3 / 12
- **Резултат:** import wizard
- **Зависимости:** ADM-001, SRC-008
- **Основание:** AD-03, IM-03 · покрива: IM-03, AD-03
- **Статус:** planned

**Приемане**

- Частичен пакет не може да се публикува с обикновен confirm
- Потребителят вижда rejected rows и completeness evidence

**Конкретна проверка:** Качване само на една от три части и неизвестна нова колона.

## ADM-004 · Управление на jobs

Покажи етап, real progress, heartbeat, retry/cancel и постоянен финален отчет; без принудителен reload.

- **Приоритет / етап / вълна:** P1 / R3 / 13
- **Резултат:** jobs console
- **Зависимости:** ADM-001, SRC-010
- **Основание:** AD-02, SY-09 · покрива: SY-09, AD-02
- **Статус:** planned

**Приемане**

- Progress не се преструва на 100% преди validation/publish
- Грешка при polling се показва с retry

**Конкретна проверка:** Прекъсване на status API и възстановяване без загуба на job ID.

## ADM-005 · Работна опашка за качество

Филтрирай по severity, field, file и rule; сравни raw клетката, normalized value и контекста на реда.

- **Приоритет / етап / вълна:** P0 / R3 / 14
- **Резултат:** quarantine/review console
- **Зависимости:** ADM-001, NRM-008, DAT-001
- **Основание:** DESIGN · покрива: DESIGN
- **Статус:** planned

**Приемане**

- Причината е възпроизводима от file/sheet/row
- Bulk action има preview и ограничен scope

**Конкретна проверка:** Конфликтен номер, future date и country-only producer в една опашка.

## ADM-006 · Корекции с преглед и история

Дай draft, review, approve/reject и revoke; високорисков bulk change изисква втори reviewer.

- **Приоритет / етап / вълна:** P0 / R3 / 15
- **Резултат:** correction workflow
- **Зависимости:** ADM-005, DAT-007, NRM-009
- **Основание:** DESIGN · покрива: DESIGN
- **Статус:** planned

**Приемане**

- Редакторът не може сам да одобри високорискова масова промяна
- Запазени са old/new/reason/actor/time

**Конкретна проверка:** Нов source import влиза в конфликт с одобрена корекция.

## ADM-007 · Конзола за идентичности

Показвай merge candidates, aliases, roles, affected records и reversible split; не предоставяй безконтролен merge all.

- **Приоритет / етап / вълна:** P0 / R3 / 16
- **Резултат:** entity review UI
- **Зависимости:** ADM-005, ENT-003
- **Основание:** DESIGN · покрива: DESIGN
- **Статус:** planned

**Приемане**

- Прегледът включва потенциални false merges
- Undo възстановява списъци и redirects по одобрена политика

**Конкретна проверка:** Сходни ООД/ЕООД с различни identifiers.

## ADM-008 · Управление на речници и правила

Редакция като draft, sample preview, impact report, approval и publish version; без скрито незабавно recompute.

- **Приоритет / етап / вълна:** P1 / R3 / 20
- **Резултат:** rules console
- **Зависимости:** ADM-001, ENT-008, SRC-011
- **Основание:** AD-01 · покрива: AD-01
- **Статус:** planned

**Приемане**

- Старите решения са проследими
- Counts и показаните flags се обновяват заедно

**Конкретна проверка:** Промени правило за бележки и сравни преди/след на всички екрани.

## ADM-009 · Потребители, екипи и поддръжка

Покажи membership, активни сесии и права; support е ограничен и всяка промяна е одитирана.

- **Приоритет / етап / вълна:** P1 / R4 / 8
- **Резултат:** account support console
- **Зависимости:** ADM-001, IAM-004, IAM-006
- **Основание:** DESIGN · покрива: DESIGN
- **Статус:** planned

**Приемане**

- Support не вижда/сменя секрети или billing без право
- Отнемане на membership влиза в сила веднага

**Конкретна проверка:** Потребител е в две организации и напуска само едната.

## ADM-010 · Каталог на функции и PRO достъп

Управлявай feature availability, capability, quota и временно admin grant отделно от price/role.

- **Приоритет / етап / вълна:** P1 / R4 / 10
- **Резултат:** entitlements admin UI
- **Зависимости:** ADM-001, BIL-004
- **Основание:** AU-01 · покрива: AU-01
- **Статус:** planned

**Приемане**

- Admin grant има причина, автор и expiry
- Не създава фиктивно плащане

**Конкретна проверка:** Дай едноседмичен trial grant и провери автоматичното му изтичане.

## ADM-011 · Плащания и разминавания

Read-first console за provider status, internal entitlement, invoice и reconciliation; финансови действия имат отделни права.

- **Приоритет / етап / вълна:** P0 / R5 / 11
- **Резултат:** billing support console
- **Зависимости:** ADM-001, BIL-008, BIL-013
- **Основание:** DESIGN · покрива: DESIGN
- **Статус:** planned

**Приемане**

- Показва несъответствия, не ги скрива с ръчна смяна на paid flag
- Refund/cancel изискват конкретен reviewable action

**Конкретна проверка:** Provider казва cancelled, локалният достъп е active.

## ADM-012 · Настройки без връщане на секрети

Покажи configured/last verified и masked suffix; write-only secret rotation, validate config и audit.

- **Приоритет / етап / вълна:** P0 / R3 / 8
- **Резултат:** settings UI
- **Зависимости:** ADM-001, SEC-005
- **Основание:** AD-04 · покрива: AD-04
- **Статус:** planned

**Приемане**

- API ключът не присъства в HTML/response/log
- Празно поле не изтрива неволно действащ secret

**Конкретна проверка:** Запази несвързана настройка и провери, че ключът остава валиден.

## ADM-013 · Миграции, версии и възстановяване

Покажи schema/app/data/rule version, backup status и runbooks; опасните действия имат dry run и audit.

- **Приоритет / етап / вълна:** P1 / R3 / 11
- **Резултат:** system lifecycle console
- **Зависимости:** ADM-001, DAT-008, OPS-005
- **Основание:** AD-06 · покрива: AD-06
- **Статус:** planned

**Приемане**

- Migration failed не изглежда completed
- Възстановяването показва точния dataset и последствията

**Конкретна проверка:** Прекъсната миграция и rollback към предходна активна версия.

## ADM-014 · Журнал, сигнали и review SLA

Обедини търсене на audit events, входящи data issues, назначения и resolution; историята е append-only за операторите.

- **Приоритет / етап / вълна:** P1 / R3 / 23
- **Резултат:** audit и issue console
- **Зависимости:** ADM-006, APP-011, SEC-007
- **Основание:** DESIGN · покрива: DESIGN
- **Статус:** planned

**Приемане**

- Сигналът пази оригиналния record/version context
- Затваряне изисква обяснение и видим резултат

**Конкретна проверка:** Сигналът се решава след междинна нова версия на регистъра.
