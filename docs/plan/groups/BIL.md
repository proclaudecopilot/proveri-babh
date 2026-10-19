<!-- Генерирано от docs/plan/source/proveri-babh-full-plan-bg-v1.0.html (sha256 7d78033aea6368252c3e1d6dbb4af70699799d7a94c886416184d70cfb6c3c5d) чрез docs/plan/tools/build_backlog.py. Не редактирай на ръка. -->

# BIL · PRO архитектура, абонаменти и плащания

**Отговорност:** Billing / backend инженер  
**Обхват:** Capability service и payment adapter; UI не определя платения достъп.

| ID | Задача | Пр. | Етап | Вълна | Статус | Зависимости | Резултат |
|---|---|---|---|---|---|---|---|
| BIL-001 | Продуктова матрица FREE/PRO | P0 | R4 | 6 | planned | ARC-004, IAM-004 | commercial-policy.json |
| BIL-002 | Регистър на capabilities | P0 | R4 | 7 | planned | BIL-001, ARC-005 | feature-catalog.json |
| BIL-003 | Модел на достъпа по време | P0 | R4 | 8 | planned | BIL-002, IAM-006 | entitlement schema и resolver |
| BIL-004 | Сървърно прилагане на PRO | P0 | R4 | 9 | planned | BIL-003 | entitlement enforcement |
| BIL-005 | Атомарни квоти и usage ledger | P0 | R4 | 10 | planned | BIL-004 | quota service |
| BIL-006 | Избор и изолиране на платежен доставчик | P0 | R5 | 7 | planned | BIL-001, ARC-007 | payment provider ADR и sandbox setup |
| BIL-007 | Checkout от сървърна оферта | P0 | R5 | 9 | planned | BIL-006, BIL-003 | checkout service |
| BIL-008 | Сигурен webhook inbox | P0 | R5 | 8 | planned | BIL-006 | webhook ingress и processor |
| BIL-009 | Абонаментен lifecycle | P0 | R5 | 9 | planned | BIL-008, BIL-003 | subscription state machine |
| BIL-010 | Самообслужване за абонамента | P1 | R5 | 10 | planned | BIL-009, BIL-007 | billing portal integration |
| BIL-011 | Upgrade, downgrade, grace и изтичане | P0 | R5 | 11 | planned | BIL-009, BIL-005 | plan transition policy |
| BIL-012 | Refund и dispute policy | P0 | R5 | 10 | planned | BIL-009 | refund/dispute workflow |
| BIL-013 | Reconciliation и финансов журнал | P0 | R5 | 10 | planned | BIL-008, BIL-009 | reconciliation job и ledger |
| BIL-014 | Фактуриране и търговски условия | P0 | R5 | 10 | planned | BIL-006, BIL-009 | billing compliance checklist и templates |
| BIL-015 | Екран План и плащания | P1 | R5 | 12 | planned | BIL-010, BIL-011, BIL-014, UX-005 | plans/billing UI |
| BIL-016 | Пълно sandbox приемане на плащанията | P0 | R5 | 13 | planned | BIL-007, BIL-010, BIL-011, BIL-012, BIL-013, BIL-014, BIL-015 | billing acceptance evidence |

## BIL-001 · Продуктова матрица FREE/PRO

Определи базов достъп, PRO features, квоти, seats, trial/grace и manufacturer/trader use cases; цени и валути се одобряват отделно.

- **Приоритет / етап / вълна:** P0 / R4 / 6
- **Резултат:** commercial-policy.json
- **Зависимости:** ARC-004, IAM-004
- **Основание:** DESIGN · покрива: DESIGN
- **Статус:** planned

**Приемане**

- Роля производител/търговец не е равна на платен план
- Неуточнени цени/данъци не са hardcoded

**Конкретна проверка:** Една организация с двете роли получава един консистентен абонамент.

## BIL-002 · Регистър на capabilities

Стабилни feature keys, types, limits, availability и version; plan mapping е конфигурация.

- **Приоритет / етап / вълна:** P0 / R4 / 7
- **Резултат:** feature-catalog.json
- **Зависимости:** BIL-001, ARC-005
- **Основание:** AU-01 · покрива: AU-01
- **Статус:** planned

**Приемане**

- Нова PRO функция се добавя без нови scattered is_pro проверки
- Feature flag, role и entitlement са отделни условия

**Конкретна проверка:** Добави history.compare capability без промяна на всички screens.

## BIL-003 · Модел на достъпа по време

Entitlement има source, org, capability, starts_at, expires_at, state и limits; manual grant е отделен източник.

- **Приоритет / етап / вълна:** P0 / R4 / 8
- **Резултат:** entitlement schema и resolver
- **Зависимости:** BIL-002, IAM-006
- **Основание:** DESIGN · покрива: DESIGN
- **Статус:** planned

**Приемане**

- Изтекъл grant не остава active по cookie/cache
- При конфликт има документиран precedence

**Конкретна проверка:** Платен период изтича, но валиден admin grant остава само за една функция.

## BIL-004 · Сървърно прилагане на PRO

Централен policy check за API, export, AI, jobs и downloads; UI получава capabilities за представяне.

- **Приоритет / етап / вълна:** P0 / R4 / 9
- **Резултат:** entitlement enforcement
- **Зависимости:** BIL-003
- **Основание:** AU-01, AI-05 · покрива: AI-05, AU-01
- **Статус:** planned

**Приемане**

- Скрит бутон не е единствената защита
- Job проверява правата и при изпълнение/доставка

**Конкретна проверка:** Потребител запазва стар premium URL след downgrade.

## BIL-005 · Атомарни квоти и usage ledger

Резервирай/освобождавай usage по org/capability/period/request_id; обработи concurrency и failed work.

- **Приоритет / етап / вълна:** P0 / R4 / 10
- **Резултат:** quota service
- **Зависимости:** BIL-004
- **Основание:** DESIGN · покрива: DESIGN
- **Статус:** planned

**Приемане**

- При една останала квота само една заявка я взема
- Retry не начислява втори usage

**Конкретна проверка:** 20 конкурентни AI/export requests с quota=1.

## BIL-006 · Избор и изолиране на платежен доставчик

Потвърди merchant eligibility, валута, данъчен модел, фактури и reconciliation; adapter за hosted checkout/portal.

- **Приоритет / етап / вълна:** P0 / R5 / 7
- **Резултат:** payment provider ADR и sandbox setup
- **Зависимости:** BIL-001, ARC-007
- **Основание:** DESIGN · покрива: DESIGN
- **Статус:** planned

**Приемане**

- Test/live secrets и object IDs са отделени
- Приложението не приема или пази картови номера

**Конкретна проверка:** Sandbox token/price не може да се използва в live конфигурация.

## BIL-007 · Checkout от сървърна оферта

Allowlist на plan/price IDs, minor currency units, org ownership, idempotency key и валидирани return URLs.

- **Приоритет / етап / вълна:** P0 / R5 / 9
- **Резултат:** checkout service
- **Зависимости:** BIL-006, BIL-003
- **Основание:** DESIGN · покрива: DESIGN
- **Статус:** planned

**Приемане**

- Клиентът не може да подмени цена/валута/org
- Success redirect не дава entitlement

**Конкретна проверка:** Подправен price_id, двоен click и платен redirect без webhook.

## BIL-008 · Сигурен webhook inbox

Провери raw-body signature и среда; durable inbox с unique event ID преди ack; async processor и retry/DLQ.

- **Приоритет / етап / вълна:** P0 / R5 / 8
- **Резултат:** webhook ingress и processor
- **Зависимости:** BIL-006
- **Основание:** DESIGN · покрива: DESIGN
- **Статус:** planned

**Приемане**

- Дублиран event не се прилага два пъти
- Не се разчита на реда на пристигане или само на timestamp

**Конкретна проверка:** Разместени subscription/invoice events и две доставки на един event.

## BIL-009 · Абонаментен lifecycle

Моделирай trialing, active, past_due, unpaid, cancelled и incomplete според adapter; refresh canonical provider state при несигурност.

- **Приоритет / етап / вълна:** P0 / R5 / 9
- **Резултат:** subscription state machine
- **Зависимости:** BIL-008, BIL-003
- **Основание:** DESIGN · покрива: DESIGN
- **Статус:** planned

**Приемане**

- Payment success и subscription entitlement следват одобрена policy
- Старо събитие не възстановява отменен достъп

**Конкретна проверка:** invoice paid пристига преди subscription created; по-късно стар active event.

## BIL-010 · Самообслужване за абонамента

Hosted portal или ограничен UI за payment method, invoices, cancel/renew; пази org authorization и return state.

- **Приоритет / етап / вълна:** P1 / R5 / 10
- **Резултат:** billing portal integration
- **Зависимости:** BIL-009, BIL-007
- **Основание:** DESIGN · покрива: DESIGN
- **Статус:** planned

**Приемане**

- Потребител вижда само своя organization customer
- Cancel at period end показва точната крайна дата

**Конкретна проверка:** Друг org customer ID в portal request е отказан.

## BIL-011 · Upgrade, downgrade, grace и изтичане

Фиксирай proration, quota/seat overage и end-of-period правила; права се обновяват server-side и в caches.

- **Приоритет / етап / вълна:** P0 / R5 / 11
- **Резултат:** plan transition policy
- **Зависимости:** BIL-009, BIL-005
- **Основание:** DESIGN · покрива: DESIGN
- **Статус:** planned

**Приемане**

- Downgrade не трие веднага частните definitions
- Grace period не е неограничен и не е скрит

**Конкретна проверка:** Смяна на план със 7 seats към лимит 3 и pending export.

## BIL-012 · Refund и dispute policy

Запиши кога refund/chargeback променя достъп, кой може да действа и как се възстановява; не извеждай автоматично правило от думата refund.

- **Приоритет / етап / вълна:** P0 / R5 / 10
- **Резултат:** refund/dispute workflow
- **Зависимости:** BIL-009
- **Основание:** DESIGN · покрива: DESIGN
- **Статус:** planned

**Приемане**

- Финансово действие има actor, reason и provider reference
- Частичен refund не отменя произволно абонамента

**Конкретна проверка:** Partial refund, full refund и успешно оспорен dispute.

## BIL-013 · Reconciliation и финансов журнал

Сверявай provider subscription/payment/invoice с локалните записи и entitlements; immutable amounts/currency/provider refs.

- **Приоритет / етап / вълна:** P0 / R5 / 10
- **Резултат:** reconciliation job и ledger
- **Зависимости:** BIL-008, BIL-009
- **Основание:** DESIGN · покрива: DESIGN
- **Статус:** planned

**Приемане**

- Пропуснат webhook се открива и поправя контролирано
- Разминаване е видимо за admin

**Конкретна проверка:** Изключи webhook, плати sandbox invoice и пусни reconciliation.

## BIL-014 · Фактуриране и търговски условия

Събери billing identity, VAT данни ако са приложими и version на приети условия; счетоводител/правен reviewer потвърждава потоците.

- **Приоритет / етап / вълна:** P0 / R5 / 10
- **Резултат:** billing compliance checklist и templates
- **Зависимости:** BIL-006, BIL-009
- **Основание:** DESIGN · покрива: DESIGN
- **Статус:** planned

**Приемане**

- Цени, данъци, отказ/renewal са ясни преди покупка
- Не се предполага универсална VAT ставка или режим

**Конкретна проверка:** B2B сценарии по одобрените юрисдикции и invoice correction.

## BIL-015 · Екран План и плащания

Едно място за активен план, използване, лимити, следващо плащане, фактури и действия; contextual upgrade без шум.

- **Приоритет / етап / вълна:** P1 / R5 / 12
- **Резултат:** plans/billing UI
- **Зависимости:** BIL-010, BIL-011, BIL-014, UX-005
- **Основание:** DESIGN · покрива: DESIGN
- **Статус:** planned

**Приемане**

- Текстът съвпада с реалния entitlement/provider state
- Locked функция обяснява стойността и точния нужен план

**Конкретна проверка:** Past due, trial, cancel scheduled и over-quota на мобилен екран.

## BIL-016 · Пълно sandbox приемане на плащанията

Покрий success, decline, authentication, timeout, duplicate/out-of-order webhooks, renewal, cancellation и recovery.

- **Приоритет / етап / вълна:** P0 / R5 / 13
- **Резултат:** billing acceptance evidence
- **Зависимости:** BIL-007, BIL-010, BIL-011, BIL-012, BIL-013, BIL-014, BIL-015
- **Основание:** DESIGN · покрива: DESIGN
- **Статус:** planned

**Приемане**

- Няма неоторизирано отключване или двойно начисляване от приложението
- Live launch е отделна одобрена операция след преминал gate

**Конкретна проверка:** Пълен purchase→renew→past_due→recover→cancel→expire lifecycle.
