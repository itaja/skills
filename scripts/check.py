#!/usr/bin/env python3
"""Repository checks the spec validator does not cover. Stdlib only; exits 1 on any finding.

- every skill folder carries SKILL.md, a `metadata.version` with the release marker, and is
  listed in release-please-config.json (otherwise its version would stop following releases);
  every skill carries the same version, equal to the manifest's once a release exists;
- every `itaja-*` skill named in a Markdown file exists, every relative link resolves;
- no secret shape, no private path, no internal hostname in the published text.
"""

from __future__ import annotations

import json
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
findings: list[str] = []

skills = sorted(p.name for p in (ROOT / "skills").iterdir() if p.is_dir())
config = json.loads((ROOT / "release-please-config.json").read_text())
manifest = json.loads((ROOT / ".release-please-manifest.json").read_text())
listed = {f["path"] for f in config["packages"]["."].get("extra-files", [])}

versions: dict[str, str] = {}
for name in skills:
    path = ROOT / "skills" / name / "SKILL.md"
    if not path.is_file():
        findings.append(f"skills/{name}: no SKILL.md")
        continue
    match = re.search(r'^  version: "([^"]+)" # x-release-please-version$', path.read_text(), re.M)
    if not match:
        findings.append(f"skills/{name}/SKILL.md: metadata.version without the x-release-please-version marker")
    else:
        versions[name] = match.group(1)
    if f"skills/{name}/SKILL.md" not in listed:
        findings.append(f"skills/{name}/SKILL.md: missing from extra-files in release-please-config.json")

for stale in sorted(listed - {f"skills/{n}/SKILL.md" for n in skills}):
    findings.append(f"release-please-config.json: extra-files lists {stale}, which does not exist")

if len(set(versions.values())) > 1:
    findings.append(f"skills disagree on their version: {versions}")
released = manifest.get(".")
if released and versions and set(versions.values()) != {released}:
    findings.append(f"skills carry {sorted(set(versions.values()))}, the manifest says {released}")

SECRET = re.compile(r"\b(itj[sp]_[A-Za-z0-9]{12,}|whsec_[A-Za-z0-9+/=]{16,}|gh[pousr]_[A-Za-z0-9]{20,}|AKIA[0-9A-Z]{16})")
PRIVATE = re.compile(r"(/Users/|/home/[a-z]|\.internal\b|api-dev\.|\.local\b)")

for md in sorted(ROOT.rglob("*.md")):
    if any(part.startswith(".") for part in md.relative_to(ROOT).parts) or md.name == "CHANGELOG.md":
        continue
    rel = md.relative_to(ROOT)
    text = md.read_text()
    for ref in sorted(set(re.findall(r"`(itaja-[a-z-]+)`", text))):
        if ref not in skills:
            findings.append(f"{rel}: names `{ref}`, which is not a skill here")
    for link in re.findall(r"\]\(([^)#\s]+)", text):
        if not re.match(r"[a-z]+:", link) and not (md.parent / link).exists():
            findings.append(f"{rel}: broken link {link}")
    for pattern, label in ((SECRET, "secret-shaped value"), (PRIVATE, "private path or host")):
        for hit in pattern.findall(text):
            findings.append(f"{rel}: {label}: {hit}")

for finding in findings:
    print(f"::error::{finding}")
print(f"{len(skills)} skills, {len(findings)} finding(s)")
sys.exit(1 if findings else 0)
