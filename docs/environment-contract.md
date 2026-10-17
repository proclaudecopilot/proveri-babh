# Договор за средите (ARC-007)

Статус: **in_review** · Owner: ARC/OPS. Определя какво трябва да има всяка среда, за да не може тест, агент или грешка да пише в production, да праща реални писма или да прави реални плащания.

## Среди

| Среда | Цел | База | Файлове/суров слой | Поща | Плащания | Портал на БАБХ | Кой пише |
|---|---|---|---|---|---|---|---|
| **local** | разработка, тестове без WordPress (`tests/run.sh`) и с локален WordPress | собствена MariaDB/MySQL (`babh6_local`) | `wp-content/uploads/babh6/` локално | mail sink (напр. Mailpit/MailHog) — нищо не излиза навън | sandbox ключове или без адаптер | fixture файлове от `tests/fixtures/` или реални пакети от ARC-002, никога директно сваляне в тест | разработчик/агент |
| **staging** | приемане на реални пакети, admin flows, mobile matrix, performance | отделна база с **sanitized** копие на production (без имейли, waitlist, ключове) | отделен bucket/папка; суров слой се пази | mail sink; `babh6_sync_notify_email` → sink | sandbox на доставчика; webhooks към staging URL | реален портал, но само през ръчно „Провери и обнови сега“ или staging cron; публикуване изисква review | QA/OPS |
| **production** | живият сайт | production база | production хранилище; суров слой с retention (DAT-011) | реална поща | реални ключове, само след QA-012/013 | автоматичен график (понеделник 06:00 / петък 18:00) само за позната, валидирана конфигурация | само release процес (OPS-007); никога агент от задача |

Твърди правила (acceptance на ARC-007):
1. **Отделни бази, ключове и storage** за всяка среда. Споделена база между staging и production е забранена; sanitized snapshot се прави от OPS (QA-008) и не съдържа `babh6_anthropic_key`, `babh6_password`, waitlist имейли, auth cookies.
2. **Тестовите известия стигат само до mail sink.** В local/staging `wp_mail` е пренасочен (плъгин/`phpmailer_init` към sink хост). Проверка: smoke сценарият по-долу.
3. **Няма случайни production writes от тестове.** Тестовете в `tests/` не зареждат WordPress; тестове с WordPress използват `WP_ENVIRONMENT_TYPE !== 'production'` guard и собствен префикс на таблиците.
4. Реални ключове не се записват в хранилището. Единственият пример е `.env.example` (без стойности). Секретите на production се задават през хостинга/`wp-config.php` константи, не през админ формата (ADM-012, SEC-005 ще преместят `babh6_anthropic_key` от options към константа/secret store).

## Какво трябва да установи OPS/ARC за production (неизвестно днес)

| Въпрос | Защо | Задача |
|---|---|---|
| PHP версия, `max_execution_time`, `memory_limit`, `max_allowed_packet`, брой едновременни PHP процеси | бюджет на порциите, размер на INSERT пакетите | OPS-001, QA-009 |
| Има ли системен cron към `wp-cron.php` или WP-CLI | без него импортът зависи от посещения (SY-05) | SRC-009, ARC-003 |
| Място за суров слой (диск/обектно хранилище) и backup прозорец | DAT-001, RPO 15 мин / RTO 60 мин | OPS-005, OPS-006 |
| Поддържа ли MySQL транзакции (InnoDB) за всички `babh6_*` таблици | атомарно публикуване (DAT-005) | DAT-004 |
| Достъп до портала на БАБХ от хостинга (IP блокиране, скорост ~10–15 KB/s по README) | SRC-003/004 | ARC-002 |

## Smoke сценарий (конкретната проверка от задачата)

1. В local/staging: качи fixture пакет (две части) през админа → импортът завършва; `babh6_import_done` праща известие.
2. Провери mail sink: писмото е там; провери, че реалният SMTP/`wp_mail` не е използван (логът на sink-а е единственият получател).
3. Пусни `POST /babh6/v1/waitlist` с тестов имейл → редът е в `babh6_waitlist` на staging базата, не в production (различен DB host/име).
4. (след BIL-*) пусни sandbox checkout → provider event в sandbox inbox; няма реална транзакция.
5. Запиши резултата в task run отчета с host имена на базите и sink-а (без секрети).

Днес (2026-10-17): сценарият **не е изпълнен** — няма staging и local WordPress в тази сесия; достъпът до портала от сесията е блокиран (HTTP 403 през прокси). Това блокира приемането на ARC-007 и ARC-002, не останалата R0 работа.

## Променливи на средата

Виж `.env.example` в корена. WordPress чете константите от `wp-config.php`; плъгинът днес чете настройки от options (`babh6_*`). Целта (SEC-005/ADM-012) е секретите да идват от константи: `BABH6_ANTHROPIC_KEY`, `BABH6_SITE_PASSWORD` (временно, до IAM), `BABH6_BILLING_*`, `BABH6_MAIL_SINK`.
