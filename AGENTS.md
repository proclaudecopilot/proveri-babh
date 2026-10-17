# AGENTS.md — договор за изпълнение на задачи от заданието (ARC-008)

Този файл е за всеки агент или човек, който взима задача от `docs/plan/backlog.json`. Той уточнява раздел 14 на `docs/plan/master-spec.md` за това хранилище и не може да отменя „никога“-правилата там.

## 1. Какво е това хранилище

WordPress плъгин **babh-register-v6** (PHP 7.4+, MySQL през `$wpdb`, vanilla JS/CSS). Структурата е плоска: `includes/*.php`, `assets/babh6.js|css`. Тестове без WordPress: `sh tests/run.sh` (lint + `tests/php/*.test.php` + `tests/js/*.test.js` + валидатор на backlog-а); `xfail` = известен дефект със задача. Няма Composer/npm. Release = вдигане на версията в `babh-register-v6.php` (header и `BABH6_VERSION`) и push в `main` → GitHub Action прави tag + zip. **Папките `docs/`, `tests/` и `*.md` не влизат в пакета** (виж `.github/workflows/release.yml`).

Къде е какво:

| Нужда | Къде |
|---|---|
| Заданието (спецификация, раздели 1–16) | `docs/plan/master-spec.md` (генериран; оригинал в `docs/plan/source/`) |
| 225 задачи с ID, зависимости, приемане | `docs/plan/backlog.json`; по групи `docs/plan/groups/<GROUP>.md`; ред по зависимости `docs/plan/waves.md` |
| Статус на задачите | `docs/plan/status.json` (единственото място; после `python3 -I docs/plan/tools/build_backlog.py`) |
| Критерии за пускане | `docs/plan/gates.md` |
| Констатации (90 исторически + 16 LIVE) → задачи | `docs/plan/evidence.json`; проверка срещу кода: `docs/baseline/findings-verification.md` |
| Baseline на кода (commit, hash-ове, схема, REST, options) | `docs/baseline/baseline-manifest.json` |
| Речник | `docs/domain-glossary.md` |
| Собственост на файлове/договори | `docs/plan/ownership.yml` |
| Шаблон за отчет на задача | `docs/plan/task-run-template.md` |
| Архитектурни решения | `docs/adr/` (ADR-001: слоеве и карта на файловете) |
| REST договори (as-is 1.0) | `contracts/openapi-babh6-v1.yaml`, `contracts/schemas/`, правила в `contracts/README.md` |
| Екрани и дублирания | `docs/screen-map.md` |
| Среди и секрети | `docs/environment-contract.md`, `.env.example` |
| История на версиите на плъгина | `README.md` |

## 2. Как се взима задача

1. Вземи **един** task ID. Прочети задачата в `groups/<GROUP>.md`, съответния раздел от `master-spec.md`, `domain-glossary.md`, този файл и `ownership.yml`.
2. Провери зависимостите в `backlog.json`: всички трябва да са `done` (или `dropped` с причина). Ако не са — задачата е `blocked`, запиши какво липсва и спри; не „заобикаляй“ зависимостта с временна реализация.
3. Провери baseline-а: работиш ли върху commit, съвместим с `docs/baseline/baseline-manifest.json`? Ако кодът се е променил съществено, първо обнови baseline-а (ARC owner).
4. Ако задачата поправя констатация, провери статуса ѝ в `findings-verification.md`: `fixed_claimed`/`unverified` се **възпроизвежда първо**; `confirmed_active` има посочени редове. Историческа констатация не се приема за текущ дефект без възпроизвеждане.
5. Запиши `status.json[ID] = {"status": "in_progress", "note": "<branch/кой>"}` и регенерирай backlog-а.

## 3. Как се работи

- **Минимална завършена промяна.** Само обхватът на задачата; не „докато съм тук“ рефакторинг. Ако нещо извън обхвата е дефект — отбележи го в отчета към съответната задача.
- **Договори.** Промяна в `includes/schema.php`, REST договорите (`includes/rest.php`, `parties.php`, `rank.php`, `ai.php`), събития или маршрути (`#p=`, `#f=`, `#tab=`) изисква review от owner-а по `ownership.yml` и версия/миграция (ARC-005). Фронтенд и бекенд промяна на договор са в един PR.
- **Данни.** Суровите данни не се трият и не се „оправят“; корекциите са отделен слой. Неизвестното остава неизвестно (не 0, не най-старо, не собствено производство).
- **Миграции.** Всяка промяна на схема има failure/rollback история и се описва в PR-а; `BABH6_RULES_VERSION` се вдига само при промяна на производни правила, `BABH6_VERSION` — при всеки release.
- **Тестове.** Тестът проверява поведение и пази регресията; не копира имплементацията. Промяна на очакване само за да стане зелено е невалидна. Тестовете живеят в `tests/` (QA owner) и се пускат без WordPress, където е възможно (чисти функции), или с описан stub.
- **UI.** Промяна в `assets/` се проверява по device/state матрицата от раздел 8 на спецификацията — поне на 390 px и 1280 px, с описание какво е реално тествано и какво не.
- **Текстове.** Български; термините по `domain-glossary.md`. Автоматичен извод винаги е обозначен като такъв.

## 4. Какво не се прави без изрично одобрение (не от агент)

- Production deploy, реални имейли, реални плащания, промяна на live данни.
- Заобикаляне на permission/entitlement проверки; „временни“ пароли или ключове в кода.
- Измисляне на данни, цени, квоти, официални правила или регулаторни статуси.
- Скрити fallbacks, които превръщат грешка в „успех“; кеширане на неуспешен отговор.
- Изтриване на суров източник или история.
- Скриване/изключване на тест, за да мине CI.

## 5. Как се предава

- PR към `main` от клон `task/<ID>-<кратко>`. Описанието следва `docs/plan/task-run-template.md` (предпоставки, възпроизвеждане, промяна, доказателства по всеки критерий, проверки, непроверено).
- Преди push: `php -l` на всички PHP файлове, `node --check assets/babh6.js`, `python3 -I docs/plan/tools/validate_backlog.py`, тестовете в `tests/`.
- `status.json[ID]` → `in_review` с бележка къде е доказателството. **`done` се записва само от независим reviewer** след проверка; самооценката „готово“ не е sign-off. Release gate-овете (`gates.md`) се отмятат от QA owner-а.
- `blocked` съдържа точно какво липсва (вход от раздел 15, решение, достъп) и кои други задачи могат да продължат.
- Ако задачата променя статус на констатация, обнови реда ѝ в `findings-verification.md` със същия PR.

## 6. Паралелна работа

Задачите в една вълна (`waves.md`) могат да вървят паралелно. Общите договори имат един owner (`ownership.yml`): два паралелни PR-а, които променят `schema.php`/REST договор, се сливат само след review на owner-а и ребейз на втория. При конфликт печели договорът с версия; не се пазят две паралелни истини.

## 7. Генерирани файлове

`backlog.json`, `evidence.json`, `master-spec.md`, `gates.md`, `groups/*.md`, `waves.md` се генерират от `docs/plan/tools/build_backlog.py` и **не се редактират на ръка**. Нова версия на заданието = нов HTML в `docs/plan/source/` + нов sha256 в `backlog.json` + преглед на `status.json`.
