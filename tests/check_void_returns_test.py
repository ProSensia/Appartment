"""Negative test: prove check_void_returns.py catches a void-return misuse.

Reintroduces the exact bug it exists for -- `Diag::clear()` declared `: void`
while the route table reads it as a boolean -- and asserts the checker exits
non-zero and names both the call site and the declaration. Without this, a
regex that silently stopped matching (renamed helper, reformatted signature)
would let the checker pass forever while catching nothing.
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

for pattern in ("src/*.php", "*.php", "api/*.php", "includes/*.php"):
    for p in ROOT.glob(pattern):
        dest = TMP / p.relative_to(ROOT)
        dest.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy(p, dest)

# Put the bug back.
diag = TMP / "src" / "Diag.php"
diag.write_text(
    diag.read_text(encoding="utf-8").replace(
        "public static function clear(): bool",
        "public static function clear(): void"),
    encoding="utf-8")

driver = TMP / "run.py"
driver.write_text(
    (ROOT / "tests" / "check_void_returns.py").read_text(encoding="utf-8").replace(
        "ROOT = Path(__file__).resolve().parent.parent",
        f"ROOT = Path({str(TMP)!r})"),
    encoding="utf-8")

result = subprocess.run([sys.executable, str(driver)], capture_output=True, text=True)
out = result.stdout + result.stderr
print(out.strip()[:900])
print("-" * 60)

problems = []
if result.returncode == 0:
    problems.append("checker did not flag the reintroduced void misuse")
if "clear()" not in out or "Diag.php" not in out:
    problems.append("checker did not name the offending function or declaration")

shutil.rmtree(TMP, ignore_errors=True)

if problems:
    for p in problems:
        print(f"FAIL - {p}")
    raise SystemExit(1)

print("OK - checker flags the reintroduced `: void` misuse, naming both ends.")