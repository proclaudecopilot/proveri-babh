# ADR-001 · Модулен WordPress плъгин вместо нова платформа (ARC-003)

Статус: **предложен** (in_review) · Дата: 2026-10-17 · Owner: ARC · Отнася се до: master-spec раздел 4

## Контекст

Текущият продукт е WordPress плъгин (PHP 7.4+, MySQL през `$wpdb`, vanilla JS/CSS, ~8 900 реда) на shared hosting с ограничения за дълги заявки (затова импортът е на порции през WP-Cron/AJAX/loopback). Няма Composer, npm, тестове или CI освен `php -l` в release workflow-а. Заданието изисква: неизменяем суров слой, версии на набора, атомарно публикуване, права по организация, плащания и известия — без да се налага незабавен rewrite.

Потвърдени ограничения на средата (от кода, не от измерване на хостинга):

| Ограничение | Доказателство | Последствие |
|---|---|---|
| Една PHP заявка ≈ 30–120 с | `@set_time_limit(120)` в `includes/etl.php:181`; порции по 1500 реда; download на порции ~1 мин (`includes/sync.php:395`) | Дълги задачи = durable job с checkpoint, не един процес |
| Няма гарантиран планировчик | WP-Cron се задейства от посещения; loopback kick (`includes/sync.php:714-760`) | Системен cron към `wp-cron.php` е задължителна част от environment contract (ARC-007) |
| DDL не е транзакционен в MySQL | `RENAME TABLE` за parties (`includes/etl.php:641`) | Публикуване = смяна на указател/таблица, не DDL вътре в транзакция |
| Неизвестен storage извън DB | файловете се трият след импорт (`includes/etl.php:419`) | Суровият слой трябва да получи място (uploads/babh6/raw или обектно хранилище) — решение в ARC-007/OPS |
| Хостинг лимити (памет, брой заявки, max_allowed_packet) | неизвестни | ARC-007 ги измерва; ADR-то не ги предполага |

## Решение

1. **Остава WordPress плъгин.** Няма доказана нужда от друга платформа; рискът на rewrite е по-голям от ползата. Миграцията към worker/service остава възможна, защото слоевете по-долу не зависят от WordPress.
2. **Пет слоя с еднопосочни зависимости** (Delivery → Application → Domain ← Parsing; Infrastructure реализира договорите на Application):

   | Слой | Какво съдържа | Правило | Целева папка |
   |---|---|---|---|
   | Source adapters | портал на БАБХ, downloads, ръчни uploads, manifests | само тук се познава външният адрес/формат | `src/Import/Source/` |
   | Parsing / normalization | workbook reader, schema detection, нормализатори, composition AST | детерминистични чисти функции; без `$wpdb`, без HTTP, без `get_option` | `src/Import/Parse/`, `src/Domain/Normalize/` |
   | Domain | регистрации, фирми, роли, съставки, source evidence, метрики, права (policy) | без WordPress и без доставчик на плащания | `src/Domain/` |
   | Application services | import, publish, query, export, review, watch, entitlement | транзакции, права, jobs, идемпотентност; говори с Infrastructure през интерфейси | `src/Application/` |
   | Infrastructure | `$wpdb` repositories, options/transients, WP-Cron/CLI worker, файлово/обектно хранилище, provider adapters | сменяеми реализации | `src/Infrastructure/WordPress/`, `src/Infrastructure/Billing/` |
   | Delivery | REST контролери, админ UI, публичен UI, WP-CLI команди | валидира вход, прилага права, представя резултат | `includes/rest*.php`, `includes/admin*.php`, `assets/`, `src/Delivery/` |

3. **Правило за чистота:** файл в `src/Domain` или `src/Import/Parse` не извиква функции с префикс `wp_`, `get_option`, `$wpdb`, `apply_filters`, `current_time` и не чете `$_GET/$_POST`. Проверка: `tests/run.sh` зарежда тези файлове без WordPress (вече важи за `includes/detect.php`, виж `tests/php/bootstrap.php`); нарушението е fatal error в теста.
4. **Миграцията е постепенна, по вертикален сценарий** (master-spec раздел 13): първият е „оригинален пакет → raw provenance → една валидирана регистрация → versioned publish → детайл със source evidence → fault injection/rollback“. Файлове се местят в `src/` само когато задачата ги докосва; `includes/*.php` остават до тогава и се описват в картата по-долу.
5. **Един deployment, отделни изпълнители:** WP-Cron остава резервен; основният worker е WP-CLI команда (`wp babh6 worker`), викана от системен cron (ARC-007). Durable jobs живеят в таблица, не в `wp_options`.
6. **Без микросървиси.** Домейн границите, интерфейсите и durable jobs са достатъчни; изнасяне на parser в отделен процес е възможно по-късно без промяна на UI договорите.

## Карта на текущите файлове към слоевете

| Файл (v6.9.0) | Слой днес | Какво е чисто | Какво е смесено | Целева посока |
|---|---|---|---|---|
| `includes/xlsx-reader.php` | Parsing | целият reader (ZipArchive/XMLReader) | връща `WP_Error` | `src/Import/Parse/WorkbookReader` с собствен Result/Exception; позиционните колони → schema detection (XLS-001/002) |
| `includes/detect.php` | Domain/Normalize | всички функции (без WP зависимости — доказано от `tests/php/detect.test.php`) | `babh6_eff_trader_sql` връща SQL (Infrastructure знание) | `src/Domain/Normalize/*`; SQL изразът → repository |
| `includes/etl.php` | Application + Infrastructure | — | lock-ове, job state в options, SQL, нормализация, бизнес правила за заличаване в един файл | `ImportService` (Application) + `WpdbRegistrationRepository` + `OptionsJobStore` → таблица за jobs (SRC-005) |
| `includes/sync.php` | Source adapter + Application + Delivery | parse на линкове, parts gap, подпис | HTTP, cURL, cron, admin секция, state в options | `src/Import/Source/BfsaPortalAdapter`; worker; admin UI отделно |
| `includes/infer.php` | Domain (правила за търговец по име) | — | SQL в правилата | `src/Domain/Entity/TraderInference` + repository |
| `includes/parties.php`, `includes/rank.php` | Delivery + Application + Infrastructure | — | REST + SQL + бизнес правила (co-occurrence, „изпреварвания“) | `MetricsService`/`QueryService` (Application), REST контролери (Delivery) |
| `includes/rest.php` | Delivery + Infrastructure | — | query builder, транслитерация (Domain), CSV, cache headers | query AST (ARC-005) + `QueryService`; `ExportService` като job (API-008) |
| `includes/ai.php` | Delivery + Infrastructure | | auth/token (Identity), AI клиент, rate limits | `src/Identity/` (IAM), `src/Application/Assessment/` (AI) с adapter |
| `includes/admin.php` | Delivery | | | остава Delivery; чете от Application services |
| `includes/schema.php` | Infrastructure | | dbDelta + ръчни индекси | `migrations/` с версии и rollback история (OPS-004) |
| `includes/pwa.php`, `includes/site.php`, `includes/frontend.php` | Delivery | | | остават |
| `includes/mu/logador-github-updater.php` | Infrastructure (ops) | | | остава; OPS-008/009/010 |
| `assets/babh6.js` | Delivery (UI) | `parseComp` (чиста; тествана в `tests/js/parse-comp.test.js`) | един IIFE с state, рутиране, рендер, API клиент | `frontend/components`, `frontend/features` (UX-001, APP-*); composition parsing се мести в бекенда (ING-*) |

## Последствия

- Положителни: домейн логиката става тестваема без WordPress (вече частично: 33+6 поведенчески проверки); договорите (ARC-005) са границата между слоевете; бъдещ worker/service не изисква смяна на UI.
- Отрицателни/рискове: двойна структура (`includes/` и `src/`) за известно време — картата по-горе и `ownership.yml` казват кое къде е; autoload без Composer означава ръчен `require` списък в bootstrap (или минимален PSR-4 autoloader в `src/bootstrap.php`).
- Неизвестни, които ADR-то не решава: лимити на хостинга, наличност на WP-CLI на production, място за суров слой — ARC-007 ги установява и при нужда това ADR се ревизира (ADR-002).

## Конкретна проверка (от задачата)

„Извикай parser и entitlement policy през CLI тест без браузър.“
- Parser: `sh tests/run.sh` → `tests/php/detect.test.php` зарежда `includes/detect.php` без WordPress и проверява рег. №, дати, фирмени ключове; `tests/js/parse-comp.test.js` изпълнява `parseComp` в Node. **Изпълнено.**
- Entitlement policy: няма такава днес (`babh6_pro_ok` е парола/администратор). Проверката се изпълнява, когато BIL-002/IAM-003 въведат policy в `src/Domain/Access/`; тестът ще бъде добавен към `tests/run.sh`. **Отложено с причина.**
