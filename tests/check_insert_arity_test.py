"""Negative test: prove check_insert_arity.py catches error 1136.

Copies sql/ into a temp dir, re-introduces the original bug (an extra `id`
column with no matching value), and asserts the checker exits non-zero.
"""
import re
import shutil
import subprocess
import sys
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
TMP = Path(tempfile.mkdtemp())

shutil.copytree(ROOT / "sql", TMP / "sql")

seed = TMP / "sql" / "seed.sql"
text = seed.read_text(encoding="utf-8")

# Re-introduce the historical bug: list `id` but do not supply a value for it.
broken = text.replace(
    "INSERT INTO `expenses`\n  (`apartment_id`,`reference_no`",
    "INSERT INTO `expenses`\n  (`id`,`apartment_id`,`reference_no`",
    1,
)
assert broken != text, "could not re-introduce the bug"

# The VALUES rows select literal apartment_id 1 into the `id` slot, so column
# count and value count now differ by one exactly like MySQL error 1136.
seed.write_text(broken, encoding="utf-8")

driver = TMP / "run_checker.py"
driver.write_text(
    "import sys\n"
    f"src = open({str(ROOT / 'tests' / 'check_insert_arity.py')!r}, encoding='utf-8').read()\n"
    f"src = src.replace('ROOT = Path(__file__).resolve().parent.parent', 'ROOT = Path(' + repr({str(TMP)!r}) + ')')\n"
    "exec(compile(src, 'check_insert_arity.py', 'exec'), {'__file__': __file__, '__name__': '__main__'})\n",
    encoding="utf-8",
)

result = subprocess.run(
    [sys.executable, str(driver)], capture_output=True, text=True
)
out = result.stdout + result.stderr
print(out.strip()[:900])
print("-" * 60)

if result.returncode == 0:
    print("FAIL - checker did not notice the reintroduced arity bug")
    raise SystemExit(1)

if "expenses" not in out or "12 value(s) vs 13 column(s)" not in out:
    print("FAIL - checker failed for an unexpected reason")
    raise SystemExit(1)

print("OK - checker flags the reintroduced `id` column (13 columns vs 12 values).")
shutil.rmtree(TMP, ignore_errors=True)