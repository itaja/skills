---
name: itaja-updates
description: Checks whether the installed Itaja agent skills are behind the published release and tells the merchant what changed and how to update. Use at the start of an Itaja integration session, before going live, when an Itaja call behaves differently from what a skill says, or when the merchant asks if the skills are up to date.
license: MIT
metadata:
  author: itaja
  version: "1.0.0" # x-release-please-version
---

# Keeping the Itaja skills up to date

The skills describe a live API. When Itaja changes (a new endpoint, a renamed field, a new constraint), an outdated skill makes an agent write code against the old behaviour. This skill finds out whether the installed copy is behind and **tells the merchant**. It never updates anything by itself.

## When to run

- Once at the start of a session that touches Itaja, before loading another `itaja-*` skill for real work.
- Before `itaja-go-live`, so the pre-production audit uses the current checklist.
- When a response from the API contradicts a skill. Check here first, then follow `itaja-integration`: the OpenAPI document wins over any skill.
- When the merchant asks.

Run it **once per session**. Do not repeat it after the merchant declined an update.

## Step 1 — ask the skills CLI

```bash
npx skills check
```

It compares every installed skill with its source and lists the ones with an update. Run it from the merchant's project to cover project skills, and add `-g` to the related commands when the skills were installed globally (`npx skills list -g` shows which).

- No Itaja skill listed as outdated: the skills are current. Say so in one line and carry on.
- One or more `itaja-*` skills listed: go to Step 3.
- The command fails (offline, Node too old, no network, rate limit): go to Step 2. Do not retry in a loop.

## Step 2 — fallback: compare the versions yourself

Every skill of this repository carries the same `metadata.version` (they are released together).

1. Read `metadata.version` from the installed `itaja-integration/SKILL.md`.
2. Read the latest published one:
   - `https://api.github.com/repos/itaja/skills/releases/latest` (field `tag_name`, without the leading `v`); if the repository has no release yet (404), read `metadata.version` from `https://raw.githubusercontent.com/itaja/skills/main/skills/itaja-integration/SKILL.md`.
3. Different versions: the installed copy is behind. Same version: current.

If neither source is reachable, say the check could not be done and why. Never guess a result.

## Step 3 — tell the merchant

Say it plainly, before doing other Itaja work, in the merchant's language:

- **What**: "Your Itaja agent skills are out of date: installed `<old>`, latest `<new>`."
- **Why it matters**: skills describe Itaja's API and its safety rules; an old copy can lead to code that no longer matches the API.
- **What changed**: summarise the release notes of the newer versions (`https://github.com/itaja/skills/releases`) in a few bullets, or link them when they cannot be read. Do not invent a changelog.
- **How to update** (the merchant runs it, or says yes and you run it):

```bash
npx skills update            # all installed skills
npx skills update itaja-webhooks   # or one
```

Then **ask** whether to update now. Updating rewrites instruction files the agent works from.

## Rules

- **Notify, never auto-update.** Run `npx skills update` only after the merchant agrees in this conversation.
- **Never block the work.** An outdated skill is a warning, not a failure: if the merchant declines, continue and remind them of the gap only when a task depends on something that changed.
- **After an update, reload.** A skill already read in this session is stale; read the updated `itaja-integration` again before continuing.
- **The API stays the source of truth.** If the OpenAPI document (`https://api.getitaja.com/api/references/json`) and a skill disagree, follow the document and report the gap, whatever the version check says.
- Send nothing about the merchant's project or keys to any service. The check only reads public version information.
