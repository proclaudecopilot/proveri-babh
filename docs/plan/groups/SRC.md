<!-- Генерирано от docs/plan/source/proveri-babh-full-plan-bg-v1.0.html (sha256 7d78033aea6368252c3e1d6dbb4af70699799d7a94c886416184d70cfb6c3c5d) чрез docs/plan/tools/build_backlog.py. Не редактирай на ръка. -->

# SRC · Източници, сваляне и работни задачи

**Отговорност:** Data / backend инженер  
**Обхват:** Source adapters, durable queue и import orchestration.

| ID | Задача | Пр. | Етап | Вълна | Статус | Зависимости | Резултат |
|---|---|---|---|---|---|---|---|
| SRC-001 | Каталог на източници и части | P0 | R1 | 4 | planned | ARC-002, ARC-005 | source manifest contract |
| SRC-002 | Откриване на промяна по съдържание | P0 | R1 | 5 | planned | SRC-001 | content-addressed download inventory |
| SRC-003 | Ограничен и проверен download transport | P0 | R1 | 6 | planned | SRC-002 | download adapter |
| SRC-004 | Безопасно продължаване на сваляне | P0 | R1 | 7 | planned | SRC-003 | resume protocol |
| SRC-005 | Трайна state machine за импорт | P0 | R1 | 8 | planned | DAT-004, SRC-001 | import_jobs schema и transition service |
| SRC-006 | Lease и fencing на worker | P0 | R1 | 9 | planned | SRC-005 | worker ownership service |
| SRC-007 | Идемпотентни порции и checkpoints | P0 | R1 | 10 | planned | SRC-006 | chunk executor |
| SRC-008 | Валидатори преди публикуване | P0 | R1 | 11 | planned | SRC-007, DAT-005 | validation report и publish policy |
| SRC-009 | Надежден график | P1 | R1 | 9 | planned | SRC-005, ARC-007 | scheduler adapter и status |
| SRC-010 | Retry и оперативни състояния | P1 | R1 | 12 | planned | SRC-008 | retry policy и job API |
| SRC-011 | Повторна обработка без изтегляне | P1 | R1 | 12 | planned | SRC-008, DAT-003 | reprocess command и impact report |
| SRC-012 | Метрики за пълния import pipeline | P1 | R3 | 13 | planned | SRC-008, SRC-010 | import telemetry |

## SRC-001 · Каталог на източници и части

Опиши официалния портал, видове файлове, териториален/времеви scope и очакван набор от части; unknown schema се спира.

- **Приоритет / етап / вълна:** P0 / R1 / 4
- **Резултат:** source manifest contract
- **Зависимости:** ARC-002, ARC-005
- **Основание:** SY-02 · покрива: SY-02
- **Статус:** planned

**Приемане**

- Пълнота не се извежда само от брой редове или намерени URL
- Ръчното качване изисква същата декларация за обхват

**Конкретна проверка:** Пропусни една част от известен пакет и виж блокирана публикация.

## SRC-002 · Откриване на промяна по съдържание

Записвай URL, bytes hash, HTTP metadata, source date и fetch time; повторен файл се разпознава независимо от името.

- **Приоритет / етап / вълна:** P0 / R1 / 5
- **Резултат:** content-addressed download inventory
- **Зависимости:** SRC-001
- **Основание:** SY-01 · покрива: SY-01
- **Статус:** planned

**Приемане**

- Същият URL с нови байтове се обработва
- Нов URL със същите байтове не създава фалшива промяна

**Конкретна проверка:** Повтори двете комбинации и сравни job и dataset идентичностите.

## SRC-003 · Ограничен и проверен download transport

Валидирай схема, host, redirects, resolved IP, size, timeouts и MIME/content; настрой HTTP преди изпълнение.

- **Приоритет / етап / вълна:** P0 / R1 / 6
- **Резултат:** download adapter
- **Зависимости:** SRC-002
- **Основание:** SY-07 · покрива: SY-07
- **Статус:** planned

**Приемане**

- Не се следват redirects към вътрешна мрежа
- HTML error page не се приема за XLSX

**Конкретна проверка:** Тестове с redirect chain, частен IP, HTML 200 и oversized body.

## SRC-004 · Безопасно продължаване на сваляне

Свържи Range с валиден strong validator и провери Content-Range; при несъответствие започни отначало.

- **Приоритет / етап / вълна:** P0 / R1 / 7
- **Резултат:** resume protocol
- **Зависимости:** SRC-003
- **Основание:** SY-03 · покрива: SY-03
- **Статус:** planned

**Приемане**

- Две версии на файл не се съединяват
- 200 вместо 206 не се добавя към стар partial файл

**Конкретна проверка:** Смени ETag/съдържание по време на resume.

## SRC-005 · Трайна state machine за импорт

Опиши discovery→download→parse→validate→review→publish и failed/cancelled; състоянията и checkpoints са в DB.

- **Приоритет / етап / вълна:** P0 / R1 / 8
- **Резултат:** import_jobs schema и transition service
- **Зависимости:** DAT-004, SRC-001
- **Основание:** IM-05, SY-08 · покрива: IM-05, SY-08
- **Статус:** planned

**Приемане**

- Невалиден преход се отхвърля
- Cancel е отчетен преди всяка необратима стъпка

**Конкретна проверка:** Отмени job при parsing, validation и преди publish.

## SRC-006 · Lease и fencing на worker

Използвай атомарен lock, lease owner, heartbeat и fencing token; стар worker няма право да публикува.

- **Приоритет / етап / вълна:** P0 / R1 / 9
- **Резултат:** worker ownership service
- **Зависимости:** SRC-005
- **Основание:** IM-05, SY-04 · покрива: IM-05, SY-04
- **Статус:** planned

**Приемане**

- Два старта не създават две публикации
- Закъснял worker не записва след загубен lease

**Конкретна проверка:** Спрян worker оживява след поет от друг lease.

## SRC-007 · Идемпотентни порции и checkpoints

Обработвай ограничени порции; checkpoint след успешен commit; не препрочитай целия workbook за всяка порция.

- **Приоритет / етап / вълна:** P0 / R1 / 10
- **Резултат:** chunk executor
- **Зависимости:** SRC-006
- **Основание:** IM-02, IM-08 · покрива: IM-02, IM-08
- **Статус:** planned

**Приемане**

- Retry не дублира редове и събития
- Cursor не се мести при SQL failure

**Конкретна проверка:** Убий процес след запис и преди ack, после възстанови.

## SRC-008 · Валидатори преди публикуване

Провери manifest, EOF, schema, row accounting, conflicts и аномалии; праговете за спад са допълнителен сигнал.

- **Приоритет / етап / вълна:** P0 / R1 / 11
- **Резултат:** validation report и publish policy
- **Зависимости:** SRC-007, DAT-005
- **Основание:** IM-03, IM-09 · покрива: IM-03, IM-09
- **Статус:** planned

**Приемане**

- Всеки прочетен ред е accounted с причина
- Нула реда или голям спад изискват изричен преглед, не automatic done

**Конкретна проверка:** Пакет с 51% от старите записи остава непотвърден без доказана пълнота.

## SRC-009 · Надежден график

Пускай worker от реален scheduler с timezone Europe/Sofia; проверявай синтаксиса и показвай следващото изпълнение.

- **Приоритет / етап / вълна:** P1 / R1 / 9
- **Резултат:** scheduler adapter и status
- **Зависимости:** SRC-005, ARC-007
- **Основание:** SY-05, SY-06 · покрива: SY-05, SY-06
- **Статус:** planned

**Приемане**

- Няма зависимост от посетителски трафик
- Невалиден график не се записва с тиха подмяна

**Конкретна проверка:** Преминаване към лятно/зимно време и сайт без посещения.

## SRC-010 · Retry и оперативни състояния

Различавай retryable/terminal failures, exponential backoff, stopped schedule и cancelled job; покажи stale heartbeat.

- **Приоритет / етап / вълна:** P1 / R1 / 12
- **Резултат:** retry policy и job API
- **Зависимости:** SRC-008
- **Основание:** SY-08, SY-09 · покрива: SY-08, SY-09
- **Статус:** planned

**Приемане**

- Изключен график не се отчита като отменен активен job
- Изчерпан retry отива в опашка за намеса

**Конкретна проверка:** Timeout, 429, invalid schema и загубена връзка с status endpoint.

## SRC-011 · Повторна обработка без изтегляне

Избери запазени оригинали и нов parser/rule version; направи dry run и отдели промените в алгоритъм от source changes.

- **Приоритет / етап / вълна:** P1 / R1 / 12
- **Резултат:** reprocess command и impact report
- **Зависимости:** SRC-008, DAT-003
- **Основание:** DESIGN · покрива: DESIGN
- **Статус:** planned

**Приемане**

- Старите raw данни остават неизменни
- Повторната обработка не поражда ново уведомление от БАБХ

**Конкретна проверка:** Промени правило за категория при идентични файлове.

## SRC-012 · Метрики за пълния import pipeline

Записвай duration, throughput, memory, failed rows, source lag и последна успешна публикация по job.

- **Приоритет / етап / вълна:** P1 / R3 / 13
- **Резултат:** import telemetry
- **Зависимости:** SRC-008, SRC-010
- **Основание:** DESIGN · покрива: DESIGN
- **Статус:** planned

**Приемане**

- Зелено здраве означава успешни проверки и свежи данни
- Download success не се представя като import success

**Конкретна проверка:** Източникът е достъпен, но parser отказва; статусът остава проблемен.
