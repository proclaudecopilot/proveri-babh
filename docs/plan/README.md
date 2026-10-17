# Задание v1.0 (17.10.2026) — как се ползва тази папка

| Файл | Какво е | Редактира се? |
|---|---|---|
| `source/proveri-babh-full-plan-bg-v1.0.html` | Оригиналното задание (225 задачи, 19 групи), sha256 в `backlog.json` | Не (нова версия = нов файл) |
| `master-spec.md` | Спецификацията, раздели 1–16 | Генериран |
| `backlog.json` + `backlog.schema.json` | Всички задачи: ID, група, приоритет, етап, зависимости, приемане, конкретна проверка, основание, статус, вълна | Генериран (статусите от `status.json`) |
| `status.json` | Статус и бележка по задача (`planned`, `in_progress`, `blocked`, `in_review`, `done`, `dropped`) | **Да** — единственото място за напредък |
| `groups/<GROUP>.md` | Индекс и пълен текст на задачите по група | Генериран |
| `waves.md` | Топологични вълни по зависимости | Генериран |
| `gates.md` | Чеклисти за пускане (QA-010/011/012/013/014) | Генериран |
| `evidence.json` | LIVE/DESIGN основания и 90-те исторически констатации с task mapping | Генериран |
| `ownership.yml` | Кой владее кои файлове/договори | Да |
| `task-run-template.md` | Шаблон за отчет на задача | Да |
| `tools/build_backlog.py` | HTML → всички генерирани файлове | Да |
| `tools/validate_backlog.py` | Проверка на целостта (`--waves` показва реда) | Да |

```
python3 -I docs/plan/tools/build_backlog.py      # регенерира след промяна в status.json или нов source
python3 -I docs/plan/tools/validate_backlog.py   # 0 = OK
```

Проверката на констатациите срещу кода и baseline-ът са в `../baseline/`. Речникът е `../domain-glossary.md`. Правилата за работа по задачите са в `/AGENTS.md`.
