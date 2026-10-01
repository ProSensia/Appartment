"""Test: Diag::collapseRepeat() must fold repeats without corrupting the log.

A retry loop or a runaway page can emit the same event thousands of times and
push the one genuine failure out of the 256 KB ring buffer -- the report then
shows noise instead of the cause. `record()` therefore rewrites the final line
instead of appending when it is the same event inside LOG_DEDUPE_SEC.

Rewriting the tail of a log is the kind of change that is trivial to get subtly
wrong, and the failure mode is a truncated or corrupted diagnostics log -- which
is the exact thing you need when something is already broken. The interesting
cases are all boundary cases: a file larger than the read window, a partial
first line, a trailing line with no newline, an empty file, and a last line that
belongs to a different event.

So the offset arithmetic is ported here and checked against real files.
"""
import json
import os
import sys
import tempfile
import time
from pathlib import Path

if hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8", errors="replace")

ROOT = Path(__file__).resolve().parent.parent
MAX_BYTES = 262144
DEDUPE_SEC = 300
WINDOW = 8192


def entry(level, msg, action=""):
    return {"t": time.strftime("%Y-%m-%dT%H:%M:%S+00:00", time.gmtime()),
            "level": level, "msg": msg, "action": action}

def age_of(stamp):
    """Seconds since the entry, parsed without depending on the local zone."""
    import calendar
    parsed = time.strptime(stamp[:19], "%Y-%m-%dT%H:%M:%S")
    return time.time() - calendar.timegm(parsed)


def entry(level, msg, action=""):
    return {"t": time.strftime("%Y-%m-%dT%H:%M:%S+00:00", time.gmtime()),
            "level": level, "msg": msg, "action": action}


def encode(e):
    return json.dumps(e, ensure_ascii=False).replace("/", "/")


def collapse(path, new):
    """Port of Diag::collapseRepeat(); True when folded into the last line."""
    size = os.path.getsize(path)
    if size == 0 or size > MAX_BYTES:
        return False
    with open(path, "r+b") as fh:
        window = min(size, WINDOW)
        frm = size - window
        fh.seek(frm)
        tail = fh.read(window)
        if not tail.endswith(b"\n"):
            return False
        cut = len(tail) - 1
        prev = tail[:cut].rfind(b"\n")
        if prev < 0:
            if frm > 0:
                fh.seek(frm - 1)
                if fh.read(1) != b"\n":
                    return False
            start = 0
        else:
            start = prev + 1
        line_start = frm + start
        try:
            last = json.loads(tail[start:cut].decode("utf-8"))
        except (ValueError, UnicodeDecodeError):
            return False
        if not isinstance(last, dict) or "t" not in last or "msg" not in last:
            return False
        age = age_of(last["t"])
        if age < 0 or age > DEDUPE_SEC:
            return False
        if (last["msg"] != new["msg"] or last.get("level", "") != new["level"]
                or last.get("action", "") != new["action"]):
            return False
        merged = dict(new)
        merged["n"] = int(last.get("n", 1)) + 1
        fh.truncate(line_start)
        fh.seek(line_start)
        fh.write((encode(merged) + "\n").encode("utf-8"))
        return True


def write_lines(path, entries):
    # Binary, because PHP's fwrite emits a bare LF. Text mode on Windows would
    # turn these into CRLF and the harness would then be testing a file the
    # production code never produces.
    with open(path, "wb") as fh:
        for e in entries:
            fh.write((encode(e) + "\n").encode("utf-8"))


problems = []
tmp = tempfile.mkdtemp()


def case(label, existing, incoming, expect_fold, expect_lines=None, expect_n=None):
    path = os.path.join(tmp, "diag.jsonl")
    if existing is not None:
        write_lines(path, existing)
    try:
        folded = collapse(path, incoming)
    except Exception as exc:                       # noqa: BLE001
        problems.append(f"{label}: raised {exc!r}")
        return
    if folded != expect_fold:
        problems.append(f"{label}: expected fold={expect_fold}, got {folded}")
        return
    # record() appends whenever the fold is refused, so mirror that or the
    # expected line counts are meaningless.
    if not folded:
        with open(path, "ab") as fh:
            fh.write((encode(incoming) + "\n").encode("utf-8"))
    with open(path, encoding="utf-8") as fh:
        lines = [l for l in fh.read().splitlines() if l]
    if expect_lines is not None and len(lines) != expect_lines:
        problems.append(f"{label}: expected {expect_lines} line(s), got {len(lines)}")
    for line in lines:
        try:
            json.loads(line)
        except ValueError:
            problems.append(f"{label}: produced unparseable line {line[:70]!r}")
    if expect_n is not None:
        got = json.loads(lines[-1]).get("n")
        if got != expect_n:
            problems.append(f"{label}: expected n={expect_n}, got {got}")
    return path


err = entry("client:api", "SQLSTATE[HY093] Invalid parameter number")

# plain repeat folds into a count, and the file keeps exactly one line
case("single identical entry folds", [err], err, True, expect_lines=1, expect_n=2)

# a different message must append, never overwrite
case("different message appends",
     [entry("client:api", "first")], entry("client:api", "second"), False,
     expect_lines=2)

# same message but different action is a different event
case("different action appends",
     [err], entry("client:api", err["msg"], action="expense.list"), False,
     expect_lines=2)

# counts accumulate rather than resetting
p = case("count accumulates", [dict(err, n=7)], err, True, expect_lines=1, expect_n=8)

# empty log: nothing to fold, so record() appends and the file gains one line
case("empty file does not fold", [], err, False, expect_lines=1)

# a trailing line with no newline must be left alone
path = os.path.join(tmp, "nonl.jsonl")
with open(path, "wb") as fh:
    fh.write(encode(err).encode("utf-8"))   # no trailing newline
if collapse(path, err):
    problems.append("unterminated final line: expected no fold")
else:
    with open(path, encoding="utf-8") as fh:
        if len(fh.read().splitlines()) != 1:
            problems.append("unterminated final line: file was altered")

# the big one: a log far larger than the read window, where the tail is a
# partial line. This is where naive offset arithmetic truncates the file.
filler = [entry("noise", f"entry {i}") for i in range(400)]
big = case("file larger than window folds safely",
           filler + [err], err, True, expect_lines=401, expect_n=2)
if big:
    with open(big, encoding="utf-8") as fh:
        kept = [json.loads(l) for l in fh if l.strip()]
    if kept[0]["msg"] != "entry 0":
        problems.append("large file: the first entry was damaged")
    if len(kept) != 401:
        problems.append(f"large file: expected 401 entries, got {len(kept)}")

# an old entry outside the window must not be folded
old = dict(err, t=time.strftime("%Y-%m-%dT%H:%M:%S+00:00", time.gmtime(time.time() - 3600)))
case("stale entry is not folded", [old], err, False, expect_lines=2)

# a corrupt final line must not be folded or rewritten
path = os.path.join(tmp, "corrupt.jsonl")
with open(path, "wb") as fh:
    fh.write((encode(err) + "\n{not json\n").encode("utf-8"))
if collapse(path, err):
    problems.append("corrupt final line: expected no fold")
else:
    with open(path, encoding="utf-8") as fh:
        if len(fh.read().splitlines()) != 2:
            problems.append("corrupt final line: file was altered")

# ---- shipped-code guards -------------------------------------------------
diag = (ROOT / "src" / "Diag.php").read_text(encoding="utf-8")
for needle, why in [
    ("private static function collapseRepeat", "the fold helper is gone"),
    ("LOG_DEDUPE_SEC", "the dedupe window constant is gone"),
    ("ftruncate", "the rewrite no longer truncates before writing"),
    ("flock($fh, LOCK_EX)", "the rewrite is no longer locked"),
    ("if (self::collapseRepeat($file, $entry))", "record() no longer folds repeats"),
]:
    if needle not in diag:
        problems.append(f"src/Diag.php: {why}")

if problems:
    print(f"\nFAIL - {len(problems)} log-fold problem(s):\n")
    for p in problems:
        print(f"  {p}")
    sys.exit(1)

print("OK - log folding: repeats collapse, distinct entries and stale/corrupt/"
      "unterminated tails are left intact, large files keep every entry")