#!/usr/bin/env sh
# Всички локални проверки без WordPress: lint + поведенчески тестове + цялост на backlog-а.
# Изход 0 = всичко минава (xfail = известни дефекти, не провалят; xpass = поправен дефект, който трябва да се отбележи).
set -e
cd "$(dirname "$0")/.."
for f in $(git ls-files '*.php'); do php -l "$f" >/dev/null; done
node --check assets/babh6.js
echo "lint: OK"
php -d display_errors=1 tests/php/detect.test.php
php -d display_errors=1 tests/php/contracts.test.php
php -d display_errors=1 tests/php/corpus-excerpt.test.php
node tests/js/parse-comp.test.js
# Браузърни тестове (Chromium през Playwright) — пропускат се, ако playwright не е инсталиран (npm i -g playwright && npx playwright install chromium)
if node -e "require.resolve('playwright')" >/dev/null 2>&1; then
  for t in tests/browser/*.test.js; do node "$t"; done
else
  echo "browser tests: SKIPPED (няма playwright)"
fi
python3 -I docs/plan/tools/validate_backlog.py
