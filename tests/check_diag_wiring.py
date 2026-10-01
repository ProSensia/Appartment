"""Static check: the diagnostics system must not become a data leak.

The ring log at storage/diag.jsonl records exception text, SQL fragments and
bound parameter values. That is exactly the material worth having in a bug report
and exactly the material worth keeping off the open web, so two things have to
hold, and neither is visible when reading the PHP:

  1. storage/ is denied over HTTP (its own .htaccess, so it still holds if the
     root .htaccess is ignored or the site is served by nginx).
  2. storage/ is gitignored, so the log never lands in a commit.

It also asserts the ordering that makes the client collector useful at all:
diagnostics.js must load before api.js and app.js, because it wraps window.fetch
and listens for errors raised by them. Loaded last, it would only ever see the
errors its own button caused.
"""
import re
import sys
from pathlib import Path

if hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8", errors="replace")

ROOT = Path(__file__).resolve().parent.parent
problems = []


def need(path):
    return ROOT / path


# ---- 1. storage/ must be denied over HTTP ---------------------------------
htaccess = need("storage/.htaccess")
if not htaccess.is_file():
    problems.append("storage/.htaccess is missing - the ring log would be web-readable")
else:
    rules = htaccess.read_text(encoding="utf-8", errors="replace").lower()
    denied = "require all denied" in rules or "deny from all" in rules
    if not denied:
        problems.append("storage/.htaccess does not deny access (need "
                        "'Require all denied' or 'Deny from all')")

# ---- 2. the log must not be committable -----------------------------------
ignore = need(".gitignore")
if not ignore.is_file():
    problems.append(".gitignore is missing - storage/*.jsonl could be committed")
elif "storage/" not in ignore.read_text(encoding="utf-8", errors="replace"):
    problems.append(".gitignore does not cover storage/")

# ---- 3. script ordering in the shared footer ------------------------------
foot = need("includes/foot.php")
if not foot.is_file():
    problems.append("includes/foot.php is missing")
else:
    text = foot.read_text(encoding="utf-8", errors="replace")
    order = {}
    for name in ("diagnostics.js", "api.js", "app.js"):
        m = re.search(r"asset\('js/" + re.escape(name) + r"'\)", text)
        if not m:
            problems.append(f"includes/foot.php never loads {name}")
        else:
            order[name] = m.start()
    if len(order) == 3:
        if not order["diagnostics.js"] < order["api.js"]:
            problems.append("diagnostics.js must load before api.js so it can wrap fetch()")
        if not order["api.js"] < order["app.js"]:
            problems.append("api.js must load before app.js")
        if "Diag.mount()" not in text:
            problems.append("includes/foot.php never calls Diag.mount() - no report button")
        # The mount must be guarded. An unguarded reference throws when the file
        # 404s, which aborts the DOMContentLoaded handler and takes the footer
        # clock and reminder bell with it -- on every page, for every resident.
        m = re.search(r"^.*Diag\.mount\(\).*$", text, re.M)
        if m and "typeof Diag" not in m.group(0):
            problems.append("includes/foot.php calls Diag.mount() unguarded - a "
                            "failed diagnostics.js load would break every page's shell")

# ---- 4. diag.php must not require a session -------------------------------
diag = need("diag.php")
if not diag.is_file():
    problems.append("diag.php is missing")
else:
    text = diag.read_text(encoding="utf-8", errors="replace")
    if "head.php" in text:
        problems.append("diag.php includes head.php - that is the signed-in shell, "
                        "and it would hide the report exactly when login is broken")
    if "Auth::check()" not in text:
        problems.append("diag.php does not branch on Auth::check(), so it cannot "
                        "produce the redacted anonymous view")

# ---- 5. the server must never leak the database password ------------------
# config.php is required as a PHP array, so it prints nothing when requested
# directly -- but only if it is genuinely a returning file, not a printing one.
cfg = need("config/config.php")
if cfg.is_file():
    body = cfg.read_text(encoding="utf-8", errors="replace")
    if re.search(r"^\s*(echo|print)\b", body, re.M):
        problems.append("config/config.php writes output - requestable credentials")

if problems:
    print(f"\nFAIL - {len(problems)} diagnostics-hygiene problem(s):\n")
    for p in problems:
        print(f"  {p}")
    sys.exit(1)

print("OK - the diagnostics log is web-protected and uncommittable, "
      "and scripts load in dependency order")