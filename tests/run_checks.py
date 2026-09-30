#!/usr/bin/env python
"""
Run every FlatMate static check.

    python tests/run_checks.py

These stand in for `php -l` plus the parts of review a linter cannot do,
because no PHP binary is available in this environment.
"""
import subprocess
import sys
from pathlib import Path

HERE = Path(__file__).resolve().parent
CHECKS = [
    ("SQL schema/seed parses, FKs + views + insert columns resolve", ".validate_sql.py"),
    ("INSERT column/value arity (MySQL error 1136)", "tests/check_insert_arity.py"),
    ("INSERT arity checker self-test", "tests/check_insert_arity_test.py"),
    ("MySQL-only restrictions (subquery LIMIT, error 1093, joined UNION ALL)", "tests/check_sql_restrictions.py"),
    ("PHP preamble (strict_types / UTF-8 BOM)", "tests/check_php_preamble.py"),
    ("PHP compiles (php -l, or Node php-parser)", "tests/check_php_syntax.py"),
    ("PDO placeholder bindings (no unbound :names)", "tests/check_bindings.py"),
    ("PDO placeholder reuse (no duplicate :names)", "tests/check_placeholders.py"),
    ("Cross-class references resolve", "tests/check_references.py"),
    ("Router handler wiring", "tests/check_routes.py"),
    ("Front-end JavaScript parses", "tests/check_js.py"),
]

failed = []

for label, script in CHECKS:
    print("=" * 78, flush=True)
    print(f"  {label}", flush=True)
    print(f"  {script}", flush=True)
    print("=" * 78, flush=True)
    result = subprocess.run([sys.executable, script], cwd=HERE.parent)
    print(flush=True)
    if result.returncode != 0:
        failed.append(label)

print("=" * 78)
if failed:
    print(f"FAILED {len(failed)} check(s):")
    for f in failed:
        print(f"  - {f}")
    sys.exit(1)

print("All checks passed.")