<!-- Генерирано от docs/plan/source/proveri-babh-full-plan-bg-v1.0.html (sha256 7d78033aea6368252c3e1d6dbb4af70699799d7a94c886416184d70cfb6c3c5d) чрез docs/plan/tools/build_backlog.py. Не редактирай на ръка. -->

# OPS · Миграции, производителност и експлоатация

**Отговорност:** Platform / DevOps инженер  
**Обхват:** CI/CD, scheduler, backups, releases и operational runbooks.

| ID | Задача | Пр. | Етап | Вълна | Статус | Зависимости | Резултат |
|---|---|---|---|---|---|---|---|
| OPS-001 | Възпроизводима development среда | P1 | R0 | 3 | planned | ARC-001, ARC-007 | environment scripts и version matrix |
| OPS-002 | CI с meaningful gates | P0 | R0 | 5 | planned | OPS-001, ARC-008 | CI pipeline |
| OPS-003 | Мониторинг на услугата | P1 | R3 | 21 | planned | SRC-009, SRC-012, API-012 | observability config |
| OPS-004 | Безопасни schema миграции | P0 | R3 | 9 | planned | DAT-005, ARC-001 | migration framework |
| OPS-005 | Миграция на съществуващите данни | P0 | R3 | 10 | planned | OPS-004, DAT-008, ARC-002 | migration reconciliation report |
| OPS-006 | Backup и проверено restore | P0 | R3 | 6 | planned | DAT-011, OPS-001 | backup/restore automation |
| OPS-007 | Публикуване с rollback | P0 | R3 | 11 | planned | OPS-002, OPS-005, OPS-006 | deployment runbook |
| OPS-008 | Ограничен GitHub auth transport | P0 | R3 | 4 | planned | SEC-005 | updater HTTP adapter |
| OPS-009 | Проверка на release пакета | P0 | R3 | 6 | planned | OPS-008, OPS-002 | package verifier |
| OPS-010 | Жизнен цикъл на updater | P1 | R3 | 7 | planned | OPS-009 | updater status и lifecycle |
| OPS-011 | Capacity и натоварване | P1 | R3 | 21 | planned | API-012, ANA-010, XLS-009 | capacity report |
| OPS-012 | Runbooks и инциденти | P1 | R3 | 22 | planned | OPS-003, OPS-006, OPS-007 | operations handbook |
| OPS-013 | Експлоатация на платената услуга | P1 | R5 | 26 | planned | BIL-013, MON-012, AI-010, OPS-012 | paid operations handbook |

## OPS-001 · Възпроизводима development среда

Фиксирай PHP/WP/DB/runtime версии по потвърдената текуща среда; dependency lockfiles и one-command setup.

- **Приоритет / етап / вълна:** P1 / R0 / 3
- **Резултат:** environment scripts и version matrix
- **Зависимости:** ARC-001, ARC-007
- **Основание:** DESIGN · покрива: DESIGN
- **Статус:** planned

**Приемане**

- Нов агент стартира същата среда от чист checkout
- Няма секрети или production DB в репото

**Конкретна проверка:** Clean machine setup и smoke import на fixture.

## OPS-002 · CI с meaningful gates

Syntax/static analysis, contract, unit/integration suites, migration и artifact checks; отделни бързи/тежки jobs.

- **Приоритет / етап / вълна:** P0 / R0 / 5
- **Резултат:** CI pipeline
- **Зависимости:** OPS-001, ARC-008
- **Основание:** DESIGN · покрива: DESIGN
- **Статус:** planned

**Приемане**

- Failing required gate блокира merge
- Тестовете не извършват реални плащания/имейли

**Конкретна проверка:** Умишлено счупен contract и секрет в fixture блокират build.

## OPS-003 · Мониторинг на услугата

Trace/correlation IDs, latency, errors, source lag, queue age и resource saturation; alerts с owner/runbook.

- **Приоритет / етап / вълна:** P1 / R3 / 21
- **Резултат:** observability config
- **Зависимости:** SRC-009, SRC-012, API-012
- **Основание:** DESIGN · покрива: DESIGN
- **Статус:** planned

**Приемане**

- Проблем с parser се отличава от network/provider outage
- SLO има измерим прозорец и dashboard

**Конкретна проверка:** Source stale и worker stopped се откриват без посещение на сайта.

## OPS-004 · Безопасни schema миграции

Expand/contract, checkpoints, lock и post-validation; version се записва след успех; тежката миграция не е на init.

- **Приоритет / етап / вълна:** P0 / R3 / 9
- **Резултат:** migration framework
- **Зависимости:** DAT-005, ARC-001
- **Основание:** AD-06 · покрива: AD-06
- **Статус:** planned

**Приемане**

- Частичен failure може да се продължи или върне
- Старият код не чете несъвместима schema по време на rollout

**Конкретна проверка:** Fail след втори migration step и повторно изпълнение.

## OPS-005 · Миграция на съществуващите данни

Shadow import и old/new diff за records, companies, metrics и deep links; не запълвай липсващ historical raw с измислен източник.

- **Приоритет / етап / вълна:** P0 / R3 / 10
- **Резултат:** migration reconciliation report
- **Зависимости:** OPS-004, DAT-008, ARC-002
- **Основание:** DESIGN · покрива: DESIGN
- **Статус:** planned

**Приемане**

- Всички текущи IDs са mapped или явно unresolved
- Трансформация на inferred факти е отчетена, не скрита

**Конкретна проверка:** Сравни старите 82k+ регистрации с новия модел по exact key.

## OPS-006 · Backup и проверено restore

Пази DB, originals, config references и необходимите logs; encrypted backups и restore в изолирана среда.

- **Приоритет / етап / вълна:** P0 / R3 / 6
- **Резултат:** backup/restore automation
- **Зависимости:** DAT-011, OPS-001
- **Основание:** DESIGN · покрива: DESIGN
- **Статус:** planned

**Приемане**

- Предложените RPO≤15 min/RTO≤60 min са измерени или преразгледани преди launch
- Restore възстановява данни и file pointers

**Конкретна проверка:** Симулирай загуба на DB и storage manifest и измери възстановяването.

## OPS-007 · Публикуване с rollback

Версиониран release artifact, staging sign-off, migration dry run, canary/smoke и конкретна rollback процедура.

- **Приоритет / етап / вълна:** P0 / R3 / 11
- **Резултат:** deployment runbook
- **Зависимости:** OPS-002, OPS-005, OPS-006
- **Основание:** DESIGN · покрива: DESIGN
- **Статус:** planned

**Приемане**

- Публикуването е отделно одобрено действие
- Rollback не връща финансови или auth събития назад

**Конкретна проверка:** Провали smoke след release и върни кода/активния dataset по runbook.

## OPS-008 · Ограничен GitHub auth transport

Добавяй token само за конкретния allowlisted API/release request; redirects не получават чужди credentials.

- **Приоритет / етап / вълна:** P0 / R3 / 4
- **Резултат:** updater HTTP adapter
- **Зависимости:** SEC-005
- **Основание:** GH-01 · покрива: GH-01
- **Статус:** planned

**Приемане**

- Глобален HTTP hook не добавя token към произволни домейни
- Redirect сменя auth scope безопасно

**Конкретна проверка:** Паралелна заявка към друг host не съдържа GitHub header.

## OPS-009 · Проверка на release пакета

Избирай точен asset по manifest/plugin ID/version; checksum/signature policy и съдържание преди install.

- **Приоритет / етап / вълна:** P0 / R3 / 6
- **Резултат:** package verifier
- **Зависимости:** OPS-008, OPS-002
- **Основание:** GH-02 · покрива: GH-02
- **Статус:** planned

**Приемане**

- Първият ZIP asset не се приема автоматично
- Пакет за друг plugin или версия се отхвърля

**Конкретна проверка:** Release с docs.zip преди правилния install artifact.

## OPS-010 · Жизнен цикъл на updater

Отдели no update, failed check и incompatible; покажи собствеността/версията на MU компонента и uninstall policy.

- **Приоритет / етап / вълна:** P1 / R3 / 7
- **Резултат:** updater status и lifecycle
- **Зависимости:** OPS-009
- **Основание:** GH-03, GH-04 · покрива: GH-03, GH-04
- **Статус:** planned

**Приемане**

- 403/timeout не означава най-нова версия
- Изключване на plugin не оставя неясен orphan updater

**Конкретна проверка:** Грешен token, pre-release и deactivated main plugin.

## OPS-011 · Capacity и натоварване

Определи ресурси, concurrency и максимум на package; измери API, import, export и UI без недоказани speed claims.

- **Приоритет / етап / вълна:** P1 / R3 / 21
- **Резултат:** capacity report
- **Зависимости:** API-012, ANA-010, XLS-009
- **Основание:** DESIGN · покрива: DESIGN
- **Статус:** planned

**Приемане**

- Публикувани са hardware, dataset size и test profile
- Queue/backpressure пази четенето при голям import

**Конкретна проверка:** 200k records, най-голям workbook, 50 readers и export едновременно.

## OPS-012 · Runbooks и инциденти

Процедури за broken source, bad publish, stuck job, key leak, email outage и restore; owners и escalation.

- **Приоритет / етап / вълна:** P1 / R3 / 22
- **Резултат:** operations handbook
- **Зависимости:** OPS-003, OPS-006, OPS-007
- **Основание:** DESIGN · покрива: DESIGN
- **Статус:** planned

**Приемане**

- Всеки alert води към изпълнима процедура
- Incident review връща regression task към backlog

**Конкретна проверка:** Tabletop за грешно публикуван dataset и изтекъл credential.

## OPS-013 · Експлоатация на платената услуга

Добави payment reconciliation, renewal failure, AI budget и notification lag runbooks; support access е ограничен.

- **Приоритет / етап / вълна:** P1 / R5 / 26
- **Резултат:** paid operations handbook
- **Зависимости:** BIL-013, MON-012, AI-010, OPS-012
- **Основание:** DESIGN · покрива: DESIGN
- **Статус:** planned

**Приемане**

- Платежен проблем се открива независимо от webhook success rate
- Support вижда необходимото без secrets

**Конкретна проверка:** Provider outage по време на renewal и просрочен digest.
