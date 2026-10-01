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
    ("SQL schema/seed/patch parses, FKs + views + insert columns resolve", ".validate_sql.py"),
    ("INSERT column/value arity (MySQL error 1136)", "tests/check_insert_arity.py"),
    ("INSERT arity checker self-test", "tests/check_insert_arity_test.py"),
    ("MySQL restrictions (subquery LIMIT, 1093, joined UNION ALL, additive patch)", "tests/check_sql_restrictions.py"),
    ("SQL columns resolve against the schema", "tests/check_sql_columns.py"),
    ("SQL column checker self-test", "tests/check_sql_columns_test.py"),
    ("PHP preamble (strict_types / UTF-8 BOM)", "tests/check_php_preamble.py"),
    ("PHP compiles (php -l, or Node php-parser)", "tests/check_php_syntax.py"),
    ("PDO placeholder bindings (no unbound :names)", "tests/check_bindings.py"),
    ("PDO placeholder reuse (no duplicate :names)", "tests/check_placeholders.py"),
    ("Placeholder checker self-test", "tests/check_placeholders.py", "--self-test"),
    ("Runtime binding assertion (catches HY093 causes)", "tests/check_db_bindings_runtime.py"),
    ("Cross-class references resolve", "tests/check_references.py"),
    ("No `: void` return value is used", "tests/check_void_returns.py"),
    ("Void-return checker self-test", "tests/check_void_returns_test.py"),
    ("Router handler wiring", "tests/check_routes.py"),
    ("Diagnostics wiring + log hygiene", "tests/check_diag_wiring.py"),
    ("Diagnostics wiring checker self-test", "tests/check_diag_wiring_test.py"),
    ("Diagnostics schema parser cross-check", "tests/check_diag_schema_parse_test.py"),
    ("Diagnostics log repeat folding", "tests/check_diag_log_fold.py"),
    ("Front-end JavaScript parses", "tests/check_js.py"),
]

failed = []

for entry in CHECKS:
    label, script = entry[0], entry[1]
    extra = list(entry[2:])          # optional extra argv for the checker
    print("=" * 78, flush=True)
    print(f"  {label}", flush=True)
    print(f"  {script} {' '.join(extra)}".rstrip(), flush=True)
    print("=" * 78, flush=True)
    result = subprocess.run([sys.executable, script, *extra], cwd=HERE.parent)
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