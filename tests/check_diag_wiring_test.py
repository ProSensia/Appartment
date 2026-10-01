"""Negative test: prove check_diag_wiring.py catches a leaky diagnostics setup.

Copies the relevant files into a temp dir, breaks all three guarantees at once
(unprotected log directory, uncommittable log, wrong script order) and asserts
the checker exits non-zero. A hygiene check that silently matched nothing would
otherwise pass forever, and the property it guards -- not serving exception text
over HTTP -- is not something a reviewer notices by reading PHP.
"""
import shutil
import subprocess
import sys
import tempfile
from pathlib import Path

if hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8", errors="replace")

ROOT = Path(__file__).resolve().parent.parent
TMP = Path(tempfile.mkdtemp())

for d in ("storage", "includes", "config"):
    shutil.copytree(ROOT / d, TMP / d)
shutil.copy(ROOT / ".gitignore", TMP / ".gitignore")
shutil.copy(ROOT / "diag.php", TMP / "diag.php")

# (1) the ring log directory stops denying access
(TMP / "storage" / ".htaccess").write_text("# rules removed\n", encoding="utf-8")

# (2) and stops being uncommittable
(TMP / ".gitignore").write_text("node_modules/\n", encoding="utf-8")

# (3) diagnostics.js is no longer loaded, so it can no longer wrap fetch()
foot = TMP / "includes" / "foot.php"
foot.write_text(
    foot.read_text(encoding="utf-8").replace("js/diagnostics.js", "js/api.js"),
    encoding="utf-8",
)

driver = TMP / "run.py"
driver.write_text(
    f"src = open({str(ROOT / 'tests' / 'check_diag_wiring.py')!r}, encoding='utf-8').read()\n"
    f"src = src.replace('ROOT = Path(__file__).resolve().parent.parent', 'ROOT = Path(' + repr({str(TMP)!r}) + ')')\n"
    "exec(compile(src, 'check_diag_wiring.py', 'exec'), {'__file__': __file__, '__name__': '__main__'})\n",
    encoding="utf-8",
)

result = subprocess.run([sys.executable, str(driver)], capture_output=True, text=True)
out = result.stdout + result.stderr
print(out.strip()[:900])
print("-" * 60)

expected = ["does not deny access", "does not cover storage/", "never loads diagnostics.js"]
missing = [e for e in expected if e not in out]

if result.returncode == 0:
    print("FAIL - checker did not notice the leaky diagnostics setup")
    raise SystemExit(1)
if missing:
    print("FAIL - checker missed:", ", ".join(missing))
    raise SystemExit(1)

print(f"OK - checker flags all {len(expected)} broken diagnostics guarantees.")
shutil.rmtree(TMP, ignore_errors=True)