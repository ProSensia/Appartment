#!/usr/bin/env python
"""
Catch PHP that does not compile.

    python tests/check_php_syntax.py

This is the check that would have caught six `(sideEffect(...), ['ok' => true])[1]`
routes in api/index.php. PHP has no comma operator, so the whole API file was a
parse error: every request died before the router's own try/catch and the client
could only say "Request failed (500)". `check_references.py` reads the file as
text and never noticed because the file never ran.

`php -l` is authoritative and is used whenever a PHP binary is on PATH. With only
Node available it falls back to tests/check_php_syntax.js, which needs the
optional php-parser devDependency (`npm install` inside tests/). If neither is
present the check skips rather than blocks the suite.
"""
import shutil
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
SKIP_DIRS = {".git", "vendor", "node_modules"}

files = sorted(
    p for p in ROOT.rglob("*.php")
    if not any(part in SKIP_DIRS for part in p.parts)
)
if not files:
    print("FAIL - no PHP files found.")
    sys.exit(1)

php = shutil.which("php")

if php:
    failures = 0
    for path in files:
        result = subprocess.run([php, "-l", str(path)], capture_output=True, text=True)
        if result.returncode == 0:
            print(f"  OK    {path.relative_to(ROOT)}")
        else:
            failures += 1
            print(f"  FAIL  {path.relative_to(ROOT)}")
            detail = (result.stdout or result.stderr).strip().splitlines()
            for line in detail[:4]:
                print(f"        {line}")
    if failures:
        print(f"\nFAIL - {failures} PHP file(s) do not compile (php -l).")
        sys.exit(1)
    print(f"\nOK - all {len(files)} PHP files compile (php -l).")
    sys.exit(0)

node = shutil.which("node")
parser_installed = (ROOT / "tests" / "node_modules" / "php-parser" / "package.json").is_file()

if node and parser_installed:
    result = subprocess.run(
        [node, str(ROOT / "tests" / "check_php_syntax.js"), str(ROOT)],
        capture_output=True,
        text=True,
    )
    sys.stdout.write(result.stdout)
    if result.stderr.strip():
        sys.stderr.write(result.stderr)
    sys.exit(result.returncode)

print("SKIP - no `php` binary and php-parser is not installed.")
print("       best fix: install PHP for `php -l`; or: `npm install` inside tests/")
sys.exit(0)
