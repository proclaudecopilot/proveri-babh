<!-- Генерирано от docs/plan/source/proveri-babh-full-plan-bg-v1.0.html (sha256 7d78033aea6368252c3e1d6dbb4af70699799d7a94c886416184d70cfb6c3c5d) чрез docs/plan/tools/build_backlog.py. Не редактирай на ръка. -->

# SEC · Сигурност и защита на достъпа

**Отговорност:** Security / backend инженер  
**Обхват:** Threat model и конкретни проверки по реалните surfaces; без неограничен pentest на live сайта.

| ID | Задача | Пр. | Етап | Вълна | Статус | Зависимости | Резултат |
|---|---|---|---|---|---|---|---|
| SEC-001 | Threat model на реалните потоци | P0 | R0 | 3 | planned | ARC-003, ARC-007 | threat-model.md и verification map |
| SEC-002 | WordPress permissions и CSRF | P0 | R3 | 7 | planned | API-001, IAM-005 | permissions audit |
| SEC-003 | SQL injection и XSS защита | P0 | R3 | 22 | planned | API-006, APP-004, ADM-005 | input/output security checks |
| SEC-004 | Изолация между организации | P0 | R3 | 22 | planned | IAM-006, API-009 | tenant isolation tests |
| SEC-005 | Управление на секрети | P0 | R3 | 3 | planned | ARC-007 | secret handling policy и redaction |
| SEC-006 | Outbound и upload защити | P0 | R3 | 9 | planned | SRC-003, XLS-011 | I/O boundary tests |
| SEC-007 | Надежден одитен журнал | P0 | R3 | 8 | planned | IAM-005, DAT-007 | audit service |
| SEC-008 | Защити на web сесията | P1 | R3 | 6 | planned | IAM-002, SEC-001 | web security configuration |
| SEC-009 | Abuse и rate limits | P1 | R3 | 21 | planned | API-012, IAM-001 | abuse controls |
| SEC-010 | Проверка на платените граници | P0 | R5 | 23 | planned | BIL-016, SEC-004, AI-007 | paid security report |
| SEC-011 | Проверка преди production core | P0 | R3 | 23 | planned | SEC-002, SEC-003, SEC-004, SEC-005, SEC-006, SEC-007, SEC-008, SEC-009 | core security acceptance |

## SEC-001 · Threat model на реалните потоци

Опиши upload/parser, external source, admin, private tenant data, AI, exports и payments; подбери приложими ASVS проверки.

- **Приоритет / етап / вълна:** P0 / R0 / 3
- **Резултат:** threat-model.md и verification map
- **Зависимости:** ARC-003, ARC-007
- **Основание:** DESIGN · покрива: DESIGN
- **Статус:** planned

**Приемане**

- Всеки trust boundary има owner и защита
- Рисковете са свързани с конкретни endpoints/jobs

**Конкретна проверка:** Проследи untrusted XLSX до HTML, CSV, AI и admin review.

## SEC-002 · WordPress permissions и CSRF

Capabilities на всяка mutation; nonce е CSRF механизъм, не заместител на authentication/authorization; webhook има signature политика.

- **Приоритет / етап / вълна:** P0 / R3 / 7
- **Резултат:** permissions audit
- **Зависимости:** API-001, IAM-005
- **Основание:** DESIGN · покрива: DESIGN
- **Статус:** planned

**Приемане**

- Anonymous и low-role callers не могат да стартират import или променят настройки
- Проверени са REST, fallback и admin actions

**Конкретна проверка:** Direct POST без UI и с nonce от неподходяща роля.

## SEC-003 · SQL injection и XSS защита

Prepared SQL, allowlisted sort/columns, context escaping и sanitization на HTML; source cells се считат untrusted.

- **Приоритет / етап / вълна:** P0 / R3 / 22
- **Резултат:** input/output security checks
- **Зависимости:** API-006, APP-004, ADM-005
- **Основание:** DESIGN · покрива: DESIGN
- **Статус:** planned

**Приемане**

- Злонамерен source text не изпълнява скрипт
- Sort/filter payload не променя SQL структурата

**Конкретна проверка:** XLSX клетка със script/HTML и SQL payload в search/sort.

## SEC-004 · Изолация между организации

Negative authorization suite за IDs, lists, search, jobs, cached responses, exports и shared links.

- **Приоритет / етап / вълна:** P0 / R3 / 22
- **Резултат:** tenant isolation tests
- **Зависимости:** IAM-006, API-009
- **Основание:** DESIGN · покрива: DESIGN
- **Статус:** planned

**Приемане**

- Няма private data leakage между два tenant-а
- URL token не замества membership без изрична share policy

**Конкретна проверка:** User A опитва всички resources на B със сменени IDs.

## SEC-005 · Управление на секрети

Environment/secret store, ограничени credentials, rotation и redaction; забрани логване на tokens/full webhook secrets.

- **Приоритет / етап / вълна:** P0 / R3 / 3
- **Резултат:** secret handling policy и redaction
- **Зависимости:** ARC-007
- **Основание:** AD-04, GH-01 · покрива: AD-04, GH-01
- **Статус:** planned

**Приемане**

- Секрети липсват в DOM, CSV, logs и release zip
- Token scope е минимален

**Конкретна проверка:** Пусни secret scan и провери network/error logs при отказ.

## SEC-006 · Outbound и upload защити

Потвърди SSRF/redirect/DNS controls, MIME/size limits, isolated storage и непублични временни файлове.

- **Приоритет / етап / вълна:** P0 / R3 / 9
- **Резултат:** I/O boundary tests
- **Зависимости:** SRC-003, XLS-011
- **Основание:** SY-07 · покрива: SY-07
- **Статус:** planned

**Приемане**

- Source downloader не достига metadata/private networks
- Качен PHP/polyglot файл не се изпълнява

**Конкретна проверка:** Redirect към 127.0.0.1 и ZIP с path traversal.

## SEC-007 · Надежден одитен журнал

Append-only за операторите, отделени права/retention, actor/action/resource/before-after hash/reason/correlation ID.

- **Приоритет / етап / вълна:** P0 / R3 / 8
- **Резултат:** audit service
- **Зависимости:** IAM-005, DAT-007
- **Основание:** DESIGN · покрива: DESIGN
- **Статус:** planned

**Приемане**

- Привилегирована промяна винаги има audit event
- Секрети и ненужни лични данни не са в before/after

**Конкретна проверка:** Неуспешен и успешен bulk correction с един correlation ID.

## SEC-008 · Защити на web сесията

TLS, secure cookies, подходящи security headers/CSP и clickjacking защита, съобразени с WP и платежния flow.

- **Приоритет / етап / вълна:** P1 / R3 / 6
- **Резултат:** web security configuration
- **Зависимости:** IAM-002, SEC-001
- **Основание:** DESIGN · покрива: DESIGN
- **Статус:** planned

**Приемане**

- Защитите не счупват login или checkout redirects
- Cookie е ограничена до необходимия scope

**Конкретна проверка:** Cross-site request, embedded admin page и expired session.

## SEC-009 · Abuse и rate limits

Ограничи login, recovery, search, uploads и paid endpoints по подходящи идентичности; не само по IP.

- **Приоритет / етап / вълна:** P1 / R3 / 21
- **Резултат:** abuse controls
- **Зависимости:** API-012, IAM-001
- **Основание:** DESIGN · покрива: DESIGN
- **Статус:** planned

**Приемане**

- Shared IP не блокира безразборно всички фирмени потребители
- Distributed retry не заобикаля org cost лимита

**Конкретна проверка:** NAT офис, brute-force test и паралелни authenticated requests.

## SEC-010 · Проверка на платените граници

Провери entitlement bypass, checkout tampering, webhook forgery/replay, quota race и stale caches.

- **Приоритет / етап / вълна:** P0 / R5 / 23
- **Резултат:** paid security report
- **Зависимости:** BIL-016, SEC-004, AI-007
- **Основание:** DESIGN · покрива: DESIGN
- **Статус:** planned

**Приемане**

- Няма open Sev0/Sev1/Sev2 в платените потоци
- Всеки тест има доказателство, не само tick box

**Конкретна проверка:** Подправен webhook и стар premium download URL след revoke.

## SEC-011 · Проверка преди production core

Dependency/secret scan и целеви review на high-risk paths; triage доказани findings, не празно обещание 100% security.

- **Приоритет / етап / вълна:** P0 / R3 / 23
- **Резултат:** core security acceptance
- **Зависимости:** SEC-002, SEC-003, SEC-004, SEC-005, SEC-006, SEC-007, SEC-008, SEC-009
- **Основание:** DESIGN · покрива: DESIGN
- **Статус:** planned

**Приемане**

- Няма неизяснен критичен/висок риск или blocker
- Изключенията имат owner, expiry и конкретна компенсация

**Конкретна проверка:** Независим reviewer възпроизвежда поправките от доказателствата.
