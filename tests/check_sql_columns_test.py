"""Negative test: prove check_sql_columns.py catches error 1054.

Copies the project into a temp dir, re-introduces the three column bugs that
actually shipped (a swapped alias and an invented column), and asserts the
checker exits non-zero naming them. Without this, a checker whose alias parsing
silently stopped matching would pass forever.
"""
import re
import shutil
import subprocess
import sys
import tempfile
from pathlib import Path

if hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8", errors="replace")

ROOT = Path(__file__).resolve().parent.parent
TMP = Path(tempfile.mkdtemp())

shutil.copytree(ROOT / "sql", TMP / "sql")
for d in ("src", "api", "config", "includes"):
    if (ROOT / d).exists():
        shutil.copytree(ROOT / d, TMP / d)

# Restore the exact query that broke the dashboard, the meals page and the
# chores page: `meals` has no `status`, `meal_plans` has no `locked`, and
# `meal_participants` has no `is_cooking`.
target = TMP / "src" / "DashboardService.php"
text = target.read_text(encoding="utf-8")

fixed = "                    m.locked, p.status AS plan_status,"
broken = "                    m.status, p.locked,"
assert fixed in text, "could not re-introduce the bug (m.status / p.locked)"

broken_text = text.replace(fixed, broken, 1).replace(
    "mp.status AS my_status, mp.responded_at",
    "mp.status AS my_status, mp.is_cooking",
    1,
)
assert broken_text != text, "could not re-introduce mp.is_cooking"
target.write_text(broken_text, encoding="utf-8")

driver = TMP / "run_checker.py"
driver.write_text(
    "import sys\n"
    f"src = open({str(ROOT / 'tests' / 'check_sql_columns.py')!r}, encoding='utf-8').read()\n"
    f"src = src.replace('ROOT = Path(__file__).resolve().parent.parent', 'ROOT = Path(' + repr({str(TMP)!r}) + ')')\n"
    "exec(compile(src, 'check_sql_columns.py', 'exec'), {'__file__': __file__, '__name__': '__main__'})\n",
    encoding="utf-8",
)

result = subprocess.run([sys.executable, str(driver)], capture_output=True, text=True)
out = result.stdout + result.stderr
print(out.strip()[:900])
print("-" * 60)

expected = ["meals.status does not exist",
            "meal_plans.locked does not exist",
            "meal_participants.is_cooking does not exist"]

missing = [e for e in expected if e not in out]
if result.returncode == 0:
    print("FAIL - checker did not notice the reintroduced column bugs")
    raise SystemExit(1)
if missing:
    print("FAIL - checker missed:", ", ".join(missing))
    raise SystemExit(1)

print(f"OK - checker flags all {len(expected)} reintroduced column references.")
shutil.rmtree(TMP, ignore_errors=True)