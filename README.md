# Itaja agent skills

[![skills.sh](https://skills.sh/b/itaja/skills)](https://skills.sh/itaja/skills)

Skills that let a coding agent integrate [Itaja](https://getitaja.com) payments into a merchant's app **securely** and within Itaja's constraints — strict `homeUrl`, server-side keys, sandbox before live, signed webhooks as the only proof of payment.

Works with Claude Code, Codex, Cursor, OpenCode and the other agents supported by the [`skills` CLI](https://github.com/vercel-labs/skills).

## Install

```bash
npx skills add itaja/skills                          # all skills
npx skills add itaja/skills --skill itaja-webhooks   # one skill
```

Start with `itaja-integration`: it is the entry point and routes the agent to the right skill.

## Skills

| Skill | Use it to |
|---|---|
| [`itaja-integration`](skills/itaja-integration/SKILL.md) | **Start here.** Base URLs, auth, sandbox vs live, the non-negotiable constraints |
| [`itaja-products`](skills/itaja-products/SKILL.md) | Create and read products; the four monetization modes; price bounds and currencies |
| [`itaja-checkout-session`](skills/itaja-checkout-session/SKILL.md) | Start a payment for a cart from the merchant's server |
| [`itaja-webhooks`](skills/itaja-webhooks/SKILL.md) | Verify events and fulfil orders exactly once, on live payments only |
| [`itaja-customers`](skills/itaja-customers/SKILL.md) | Customers by e-mail, merchant bans, the buyer portal |
| [`itaja-credits`](skills/itaja-credits/SKILL.md) | Sell and spend prepaid coins |
| [`itaja-licenses`](skills/itaja-licenses/SKILL.md) | Issue, verify and activate software licences, offline tokens, reseller batches |
| [`itaja-subscriptions`](skills/itaja-subscriptions/SKILL.md) | Recurring plans — and exactly what does **not** exist yet |
| [`itaja-go-live`](skills/itaja-go-live/SKILL.md) | Prepare the move to real money and audit an integration |

## Documentation

- **[FAQ](docs/FAQ.md)** — 90+ answers: keys and modes, checkout, webhooks, products, credits, licences, subscriptions, errors and limits, going live, working as an agent.
- **[Coverage and known limits](docs/COVERAGE.md)** — what each part of the API maps to, what is dashboard-only, and what Itaja does not offer yet.

## The constraints in one screen

1. API key and webhook secret are **server-side only** — never in a browser, repo or log.
2. Build in **sandbox** (`itjs_` key); live money moves only when the merchant decides.
3. `homeUrl` is an absolute **`https`** URL without credentials, taken from server config, never from the request.
4. Only the **signed webhook** proves a payment — never the return page.
5. Stable `reference` / `idempotencyKey` on every creation and every money movement.
6. A licence key is the credential of the licence routes: the app never carries an API key.
7. Production API host: `https://api.getitaja.com`.

## Source of truth

The generated OpenAPI document of the API (`/api/references/json`) is authoritative. If a skill disagrees with it, the skill is wrong — open an issue or a pull request.

## Maintaining

- A skill states only what the API does. Every claim is checked against the API reference or the code before it is written, and a gap (a topic that is advertised but not emitted, an SDK that is not published) is written down as a gap.
- No secret, no internal hostname, no private path, no internal vendor or issue number.
- Each skill lives in `skills/<name>/SKILL.md` with `name` equal to its folder and a `description` that starts with the trigger (`Use when …`).
- Code samples must run: the webhook verifier in `itaja-webhooks` is exercised against valid, rotated, tampered, stale and malformed inputs before it ships.
- Conventional Commits; one change per pull request.

## License

MIT — see [LICENSE](LICENSE).
