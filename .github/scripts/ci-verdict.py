"""Fail the aggregating gate unless every required lane succeeded.

A docs-only change skips the PHP lanes. Those skips are success. A skip caused
by an earlier failure is not: the PHP lanes are required whenever the path
filter reports code.
"""

from __future__ import annotations

import json
import os
import sys

needs = json.loads(os.environ["NEEDS"])
code = (needs.get("changes") or {}).get("outputs", {}).get("code")
always = {"changes", "docs", "ci-gate"}
php_lanes = set(needs) - always
errors: list[str] = []
if code not in {"true", "false"}:
    errors.append(f"Missing path classification ({code!r})")
required = always | (php_lanes if code == "true" else set())
required.discard("ci-gate")

summary_path = os.environ.get("GITHUB_STEP_SUMMARY")
lines = ["## Job results", "", "| Job | Result | Required |", "| --- | --- | --- |"]
for name, job in sorted(needs.items()):
    result = job.get("result", "missing")
    is_required = name in required
    lines.append(f"| `{name}` | {result} | {'yes' if is_required else 'no'} |")
    if result == "success":
        continue
    if result == "skipped" and not is_required:
        continue
    errors.append(f"{name}: {result}")

if summary_path:
    with open(summary_path, "a", encoding="utf-8") as summary:
        summary.write("\n".join(lines) + "\n")
for error in errors:
    print(f"::error::{error}")
sys.exit(1 if errors else 0)
