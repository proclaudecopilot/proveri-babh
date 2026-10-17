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
node tests/js/parse-comp.test.js
python3 -I docs/plan/tools/validate_backlog.py
