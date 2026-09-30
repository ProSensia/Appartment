"""Catch PHP files that will fatal the moment they are loaded.

    Fatal error: strict_types declaration must be the very first statement

PHP allows comments and whitespace before `declare(strict_types=1)`, but not
output -- and a UTF-8 BOM decodes to exactly that. The BOM is invisible in an
editor, survives copy/paste, and is typically introduced by re-uploading files
through a Windows tool. It bit this project four times at once (src/Database.php,
src/ActivityLog.php, src/NoticeBoard.php, expenses.php), which turned every page
into a blank 500.

Only files that actually declare strict_types are checked: includes/foot.php is
an HTML template partial with no PHP opening tag and must not trip this.
"""
import re
import sys
from pathlib import Path

if hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8", errors="replace")

ROOT = Path(__file__).resolve().parent.parent
BOM = b"\xef\xbb\xbf"
DECLARE = re.compile(r"^\s*declare\s*\(\s*strict_types", re.I)
OPEN_TAG = re.compile(r"^\s*<\?php", re.I)


def first_statement(text):
    """Return the first statement after <?php, skipping legal comment preamble."""
    rest = text.lstrip()[len("<?php"):]
    i = 0
    while i < len(rest):
        while i < len(rest) and rest[i].isspace():
            i += 1
        if rest.startswith("//", i) or rest.startswith("#", i):
            nl = rest.find("\n", i)
            if nl == -1:
                return ""
            i = nl + 1
            continue
        if rest.startswith("/*", i):
            end = rest.find("*/", i + 2)
            if end == -1:
                return ""
            i = end + 2
            continue
        break
    return rest[i:]


findings = []

for path in sorted(ROOT.rglob("*.php")):
    if any(part in {"node_modules", ".git", "vendor"} for part in path.parts):
        continue

    data = path.read_bytes()
    rel = path.relative_to(ROOT).as_posix()
    text = data.decode("utf-8", errors="replace")

    if "declare(strict_types" not in text and "declare( strict_types" not in text:
        continue                                    # template partial, not our problem

    if data.startswith(BOM):
        findings.append((rel, "starts with a UTF-8 BOM - fatal before the declare"))
        continue

    if not OPEN_TAG.match(text):
        findings.append((rel, "declares strict_types but does not open with <?php"))
        continue

    if not DECLARE.match(first_statement(text)):
        findings.append((rel, "strict_types is not the first statement"))

for rel, why in findings:
    print(f"  {rel}: {why}")

if findings:
    print(f"\nFAIL - {len(findings)} file(s) would fatal on load.")
    sys.exit(1)
print("OK - every file that declares strict_types can be loaded.")