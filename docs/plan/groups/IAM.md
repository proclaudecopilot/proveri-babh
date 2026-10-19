<!-- Генерирано от docs/plan/source/proveri-babh-full-plan-bg-v1.0.html (sha256 7d78033aea6368252c3e1d6dbb4af70699799d7a94c886416184d70cfb6c3c5d) чрез docs/plan/tools/build_backlog.py. Не редактирай на ръка. -->

# IAM · Акаунти, организации, роли и собственост

**Отговорност:** Identity / backend инженер  
**Обхват:** Потребител ≠ организация абонат ≠ фирма в регистъра.

| ID | Задача | Пр. | Етап | Вълна | Статус | Зависимости | Резултат |
|---|---|---|---|---|---|---|---|
| IAM-001 | Вход и жизнен цикъл на акаунта | P0 | R3 | 4 | planned | ARC-005, ARC-007 | identity flow |
| IAM-002 | Сесии с реален срок и отнемане | P0 | R3 | 5 | planned | IAM-001 | session policy |
| IAM-003 | Миграция от обща парола | P0 | R3 | 6 | planned | IAM-002 | access migration plan |
| IAM-004 | Организации и memberships | P0 | R3 | 5 | planned | IAM-001, ARC-004 | organization schema/services |
| IAM-005 | Ролева матрица и platform capabilities | P0 | R3 | 6 | planned | IAM-004 | RBAC/permission matrix |
| IAM-006 | Tenant context навсякъде | P0 | R3 | 7 | planned | IAM-005, API-001 | authorization middleware |
| IAM-007 | Покани и управление на екипа | P1 | R4 | 8 | planned | IAM-006 | team service/UI |
| IAM-008 | Усилена защита за администратори | P0 | R3 | 7 | planned | IAM-002, IAM-005 | admin auth policy |
| IAM-009 | Заявяване на фирмен профил | P1 | R4 | 15 | planned | IAM-006, ENT-002 | claim workflow |
| IAM-010 | Отделни бизнес данни на платена фирма | P1 | R4 | 16 | planned | IAM-009 | business profile overlay |
| IAM-011 | Поверителност и потребителски данни | P0 | R3 | 8 | planned | IAM-006, DAT-011 | privacy workflows |
| IAM-012 | Настройки на акаунт и организация | P1 | R4 | 17 | planned | IAM-007, IAM-010, IAM-011 | account settings UI |

## IAM-001 · Вход и жизнен цикъл на акаунта

Избери поддържана WP identity интеграция; регистрация, verified email, login, logout и recovery без user enumeration.

- **Приоритет / етап / вълна:** P0 / R3 / 4
- **Резултат:** identity flow
- **Зависимости:** ARC-005, ARC-007
- **Основание:** AU-04 · покрива: AU-04
- **Статус:** planned

**Приемане**

- Съобщенията не разкриват съществуващ чужд акаунт
- Return URL е ограничен до разрешени адреси

**Конкретна проверка:** Грешна парола, expired recovery link, network failure.

## IAM-002 · Сесии с реален срок и отнемане

Сървърът проверява expiry/revocation; secure HttpOnly SameSite cookies и rotation при чувствителни промени.

- **Приоритет / етап / вълна:** P0 / R3 / 5
- **Резултат:** session policy
- **Зависимости:** IAM-001
- **Основание:** AU-02 · покрива: AU-02
- **Статус:** planned

**Приемане**

- Remember me срокът се прилага server-side
- Logout/revoke спират всички защитени endpoints

**Конкретна проверка:** Стара cookie след expiry и след password reset.

## IAM-003 · Миграция от обща парола

Отдели временно site gate от identity/PRO; документирай прехода без автоматично превръщане на общата парола в платени права.

- **Приоритет / етап / вълна:** P0 / R3 / 6
- **Резултат:** access migration plan
- **Зависимости:** IAM-002
- **Основание:** AU-01, AU-03 · покрива: AU-01, AU-03
- **Статус:** planned

**Приемане**

- Shared password не отключва платени API/AI квоти
- GET/POST и fallback route прилагат еднакви правила

**Конкретна проверка:** Съществуваща gate cookie опитва да изтегли private export.

## IAM-004 · Организации и memberships

User има много memberships; organization има owner/admin/analyst/viewer; избраната организация е явна.

- **Приоритет / етап / вълна:** P0 / R3 / 5
- **Резултат:** organization schema/services
- **Зависимости:** IAM-001, ARC-004
- **Основание:** DESIGN · покрива: DESIGN
- **Статус:** planned

**Приемане**

- Registry company не е billing tenant
- Един user може да работи в две независими организации

**Конкретна проверка:** Смени организацията без смесване на saved queries.

## IAM-005 · Ролева матрица и platform capabilities

Раздели platform admin, data editor/reviewer, support, billing и organization roles; default deny.

- **Приоритет / етап / вълна:** P0 / R3 / 6
- **Резултат:** RBAC/permission matrix
- **Зависимости:** IAM-004
- **Основание:** DESIGN · покрива: DESIGN
- **Статус:** planned

**Приемане**

- Ролята не е тарифен план
- Data editor няма финансови права по подразбиране

**Конкретна проверка:** Опитай publish, refund, invite и export с всяка роля.

## IAM-006 · Tenant context навсякъде

Enforce membership на REST, jobs, exports, caches и downloads; не се доверявай на org_id от клиента.

- **Приоритет / етап / вълна:** P0 / R3 / 7
- **Резултат:** authorization middleware
- **Зависимости:** IAM-005, API-001
- **Основание:** DESIGN · покрива: DESIGN
- **Статус:** planned

**Приемане**

- Всяка private операция валидира resource ownership
- Загубен membership спира и чакащи jobs

**Конкретна проверка:** Промени org_id/export_id към чужда организация.

## IAM-007 · Покани и управление на екипа

Scoped expiring invitation, accept/revoke, seat reservation и owner transfer; защита срещу последен owner.

- **Приоритет / етап / вълна:** P1 / R4 / 8
- **Резултат:** team service/UI
- **Зависимости:** IAM-006
- **Основание:** DESIGN · покрива: DESIGN
- **Статус:** planned

**Приемане**

- Поканата не дава права в друга организация
- Не може да остане организация без owner

**Конкретна проверка:** Покана е отнета преди приемане; два паралелни seat accept-а.

## IAM-008 · Усилена защита за администратори

MFA и step-up за publish/secret change/financial actions според роля; recovery и emergency access са одитирани.

- **Приоритет / етап / вълна:** P0 / R3 / 7
- **Резултат:** admin auth policy
- **Зависимости:** IAM-002, IAM-005
- **Основание:** DESIGN · покрива: DESIGN
- **Статус:** planned

**Приемане**

- Критично действие без необходимия step-up се отказва
- Recovery не заобикаля audit

**Конкретна проверка:** Изтекла step-up сесия при опит за масова корекция.

## IAM-009 · Заявяване на фирмен профил

Отдели company claim от paid subscription; доказателства и review дават ограничени права само върху бизнес профила.

- **Приоритет / етап / вълна:** P1 / R4 / 15
- **Резултат:** claim workflow
- **Зависимости:** IAM-006, ENT-002
- **Основание:** DESIGN · покрива: DESIGN
- **Статус:** planned

**Приемане**

- Плащане не доказва собственост на фирма
- Claim не разрешава редакция на source данните

**Конкретна проверка:** Двама кандидати заявяват една фирма; спорът остава за review.

## IAM-010 · Отделни бизнес данни на платена фирма

Описание, контакти и материали са owner-supplied, модерирани и визуално отделени от БАБХ; registry rankings са независими.

- **Приоритет / етап / вълна:** P1 / R4 / 16
- **Резултат:** business profile overlay
- **Зависимости:** IAM-009
- **Основание:** DESIGN · покрива: DESIGN
- **Статус:** planned

**Приемане**

- Платен клиент не променя регистърни факти или класация
- Премахване на overlay не трие source profile

**Конкретна проверка:** Редакция на адрес за контакт не изменя source address.

## IAM-011 · Поверителност и потребителски данни

Inventory на private fields, export/delete request, consent history и retention exceptions за одобрени законови нужди.

- **Приоритет / етап / вълна:** P0 / R3 / 8
- **Резултат:** privacy workflows
- **Зависимости:** IAM-006, DAT-011
- **Основание:** DESIGN · покрива: DESIGN
- **Статус:** planned

**Приемане**

- Потребителски export не включва чужди team secrets
- Изтриване има проследимо изпълнение и обосновани изключения

**Конкретна проверка:** Закрит акаунт с активен organization owner role и фактури.

## IAM-012 · Настройки на акаунт и организация

Един център за профил, екип, сигурност, уведомления и billing links; ясно активна организация.

- **Приоритет / етап / вълна:** P1 / R4 / 17
- **Резултат:** account settings UI
- **Зависимости:** IAM-007, IAM-010, IAM-011
- **Основание:** DESIGN · покрива: DESIGN
- **Статус:** planned

**Приемане**

- Няма дублирани настройки на различни места
- Смяна на организация обновява всички частни секции

**Конкретна проверка:** Потребител с различни роли и планове в две организации.
