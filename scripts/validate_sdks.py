#!/usr/bin/env python3
"""
validate_sdks.py — Run all SDK examples and verify they succeed.

Usage:
    python validate_sdks.py

Requires DIGIFACT_TAXID, DIGIFACT_USERNAME, DIGIFACT_PASSWORD environment
variables. If not set, examples will exit 0 (skipped) and the validator
reports them as skipped rather than failed.
"""
from __future__ import annotations

import os
import subprocess
import sys
import textwrap
from pathlib import Path

REPO_ROOT = Path(__file__).parent.parent  # scripts/ lives one level below root


def _python_exe() -> str:
    """Return the best available Python interpreter (prefer SDK venv)."""
    candidates = [
        str(REPO_ROOT / "python" / ".venv" / "bin" / "python"),
        str(REPO_ROOT / "python" / ".venv" / "bin" / "python3"),
        sys.executable,
        "python3",
        "python",
    ]
    for c in candidates:
        p = Path(c)
        if p.exists() and p.is_file():
            return str(p)
    return sys.executable


def _php_exe() -> str | None:
    """Return php executable if available, else None."""
    import shutil
    for candidate in ["php", "php8.3", "php8.2", "php8.1", "php8.0"]:
        if shutil.which(candidate):
            return candidate
    return None


EXAMPLES = [
    {
        "name": "Python",
        "cmd": [_python_exe(), "python/examples/basic.py"],
        "cwd": REPO_ROOT,
    },
    {
        "name": "PHP",
        "cmd": [_php_exe() or "php", "php/examples/basic.php"],
        "cwd": REPO_ROOT,
        "optional": True,  # skip cleanly if php not installed
    },
    {
        "name": "JavaScript",
        "cmd": ["node", "javascript/examples/basic.js"],
        "cwd": REPO_ROOT,
    },
]

WIDTH = 60


def run_example(entry: dict) -> tuple[bool, str, str]:
    """Run a single example. Returns (success, stdout, stderr)."""
    try:
        result = subprocess.run(
            entry["cmd"],
            cwd=entry["cwd"],
            capture_output=True,
            text=True,
            timeout=180,
            env={**os.environ},
        )
        success = result.returncode == 0
        return success, result.stdout, result.stderr
    except FileNotFoundError as exc:
        return False, "", f"Command not found: {exc}"
    except subprocess.TimeoutExpired:
        return False, "", "Timed out after 180 seconds"
    except Exception as exc:
        return False, "", str(exc)


def main() -> int:
    taxid = os.environ.get("DIGIFACT_TAXID", "")
    username = os.environ.get("DIGIFACT_USERNAME", "")
    password = os.environ.get("DIGIFACT_PASSWORD", "")
    has_creds = bool(taxid and username and password)

    print("=" * WIDTH)
    print("  Digifact FEL SDK Validator")
    print("=" * WIDTH)
    if not has_creds:
        print("  WARNING: credentials not set — examples will run in skip mode")
        print("  Set DIGIFACT_TAXID, DIGIFACT_USERNAME, DIGIFACT_PASSWORD")
    print()

    results = []
    for entry in EXAMPLES:
        print(f"  [{entry['name']:12s}] Running {' '.join(entry['cmd'][:2])}...")
        ok, stdout, stderr = run_example(entry)

        # "Skipping example" in stdout means no creds — treat as skip not fail
        skipped = "Skipping example" in stdout

        # If command not found: skip if optional, else FAIL
        cmd_not_found = not ok and "Command not found" in stderr
        is_optional = entry.get("optional", False)
        if skipped or (cmd_not_found and not has_creds) or (cmd_not_found and is_optional):
            status = "SKIP"
        elif ok:
            status = "OK"
        else:
            status = "FAIL"

        print(f"  [{entry['name']:12s}] {status}")
        if stdout.strip():
            for line in stdout.strip().splitlines():
                print(f"             {line}")
        if not ok and stderr.strip():
            print("  STDERR:")
            for line in stderr.strip().splitlines()[:20]:
                print(f"    {line}")

        results.append((entry["name"], status))

    print()
    print("=" * WIDTH)
    print("  Summary")
    print("=" * WIDTH)
    all_passed = True
    for name, status in results:
        icon = "✓" if status in ("OK", "SKIP") else "✗"
        print(f"  {icon} {name:12s} {status}")
        if status == "FAIL":
            all_passed = False

    print()
    if all_passed:
        print("  All SDK examples passed (or skipped).")
        return 0
    else:
        print("  One or more SDK examples FAILED.")
        return 1


if __name__ == "__main__":
    sys.exit(main())
