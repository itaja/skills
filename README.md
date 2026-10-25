# Itaja agent skills

[![skills.sh](https://skills.sh/b/itaja/skills)](https://skills.sh/itaja/skills)

Skills that let a coding agent integrate [Itaja](https://getitaja.com) into a merchant's app **securely** and within Itaja's constraints — server-side keys, sandbox before live, strict return URLs on verified domains, signed webhooks as the only proof of payment.

Works with Claude Code, Codex, Cursor, OpenCode and every agent supported by the [`skills` CLI](https://github.com/vercel-labs/skills). The skills follow the [Agent Skills specification](https://agentskills.io/specification).

## Install

```bash
npx skills add itaja/skills                          # all skills
npx skills add itaja/skills --skill itaja-webhooks   # one skill
```

Start with `itaja-integration`: it is the entry point and routes the agent to the right skill. The agent then reads the repository's manifests (`package.json`, `pyproject.toml`, `composer.json`, `go.mod`…) to choose the official package for the stack, or builds a small adapter tested against Itaja's published conformance vectors when no package exists.

## Packages

| Stack | Package | Install |
|---|---|---|
| Node ≥ 20, Bun, Deno, Workers | [`@itaja/node`](https://www.npmjs.com/package/@itaja/node) | `npm i @itaja/node` |
| Python ≥ 3.10 (Django, FastAPI, Flask) | [`itaja`](https://pypi.org/project/itaja/) | `pip install itaja` |
| Laravel 12–13, PHP ≥ 8.2 | [`itaja/itaja-laravel`](https://packagist.org/packages/itaja/itaja-laravel) | `composer require itaja/itaja-laravel` |
| Anything else | an adapter in the merchant's repo | `itaja-adapter` skill; contract files in [`itaja/itaja-sdks/spec`](https://github.com/itaja/itaja-sdks/tree/main/spec) |

## Skills

| Skill | Use it to |
|---|---|
| [`itaja-integration`](skills/itaja-integration/SKILL.md) | **Start here.** Base URL, keys, sandbox vs live, idempotency, the non-negotiable constraints |
| [`itaja-sdk`](skills/itaja-sdk/SKILL.md) | Pick the right package for the repo's language — `@itaja/node`, `itaja` (Python), `itaja/itaja-laravel` |
| [`itaja-adapter`](skills/itaja-adapter/SKILL.md) | Build and test a small API wrapper for a language without a package (Go, Ruby, Java, C#, Rust…) |
| [`itaja-catalog`](skills/itaja-catalog/SKILL.md) | Declare plans, credit packs and licences in code and push them |
| [`itaja-products`](skills/itaja-products/SKILL.md) | Create and read products by hand; monetization modes; price bounds |
| [`itaja-checkout-session`](skills/itaja-checkout-session/SKILL.md) | Take a one-off payment for a cart; quote fees beforehand |
| [`itaja-webhooks`](skills/itaja-webhooks/SKILL.md) | Verify events and fulfil exactly once, on live payments only |
| [`itaja-customers`](skills/itaja-customers/SKILL.md) | Customers by e-mail, the merchant's own ids, bans, the buyer portal |
| [`itaja-credits`](skills/itaja-credits/SKILL.md) | Sell and spend prepaid coins |
| [`itaja-licenses`](skills/itaja-licenses/SKILL.md) | Issue, verify and activate software licences, offline tokens, reseller batches |
| [`itaja-subscriptions`](skills/itaja-subscriptions/SKILL.md) | Put customers on plans, upgrade, downgrade, cancel; renewals and their limits |
| [`itaja-go-live`](skills/itaja-go-live/SKILL.md) | Prepare the move to real money and audit an integration |

## Documentation

- **[FAQ](docs/FAQ.md)** — 100+ answers: keys and modes, SDKs, catalogue, checkout, webhooks, customers, credits, licences, subscriptions, errors and limits, going live, working as an agent.
- **[Coverage and known limits](docs/COVERAGE.md)** — what each part of the API maps to, what is dashboard-only, which webhook topics are really emitted, and what Itaja does not offer yet.

## The constraints in one screen

1. API key and webhook secret are **server-side only** — never in a browser, app, repo, log or chat.
2. Build in **sandbox** (`itjs_` key); live money moves only when the merchant decides.
3. Return URLs are absolute **`https`**, from server config, never from the request — and in live, on a domain Itaja **verified** for the account.
4. Only the **signed webhook** proves a payment — never the return page.
5. A stable `Idempotency-Key`, `reference` or `idempotencyKey` on every write and every money movement.
6. A licence key is the credential of the licence routes: the shipped app never carries an API key.
7. Production API: `https://api.getitaja.com/api/v1`.

## Source of truth

The generated OpenAPI document (`https://api.getitaja.com/api/references/json`) is authoritative. If a skill disagrees with it, the skill is wrong — open an issue or a pull request.

## Maintaining

- A skill states only what the API does. Every claim is checked against the OpenAPI document or the code before it is written; a gap (a topic advertised but not emitted, an SDK helper that throws `NOT_YET_AVAILABLE`) is written down as a gap.
- Frontmatter follows the [Agent Skills specification](https://agentskills.io/specification): `name` equals the folder (lowercase, hyphens, ≤ 64 chars); `description` (≤ 1024 chars, third person) says what the skill does **and** when to use it; `license` and `metadata.version` are set. Bodies stay well under 500 lines.
- Validate before every change: `uvx --from skills-ref agentskills validate skills/<name>` for each skill, `python3 scripts/check.py` (versions, references, links, leaks), and `npx -y skills add . --list` must list all of them. CI runs the same three on every pull request.
- Never edit `metadata.version` or `CHANGELOG.md` by hand, and never push a tag: the release workflow owns them. A new skill is added to `extra-files` in `release-please-config.json` (`scripts/check.py` fails otherwise).
- No secret, no internal hostname, no private path, no internal vendor or issue number.
- Code samples must run: the webhook verifier in `itaja-webhooks` is checked against Itaja's published signature vectors plus rotated, tampered, stale and malformed inputs.
- Conventional Commits; one change per pull request.

## Releases

Versions follow [SemVer](https://semver.org) and are cut by GitHub Actions from [Conventional Commits](https://www.conventionalcommits.org) on `main` ([release-please](https://github.com/googleapis/release-please)): `fix:` → patch, `feat:` → minor, `feat!:` or `BREAKING CHANGE:` → major. Each push to `main` updates a release pull request; merging it tags `vX.Y.Z`, writes `CHANGELOG.md` and publishes the [GitHub release](https://github.com/itaja/skills/releases) with an archive of the skills. Every `SKILL.md` carries the release version in `metadata.version`.

## License

MIT — see [LICENSE](LICENSE).
