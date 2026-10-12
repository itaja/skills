# Itaja agent skills

[![skills.sh](https://skills.sh/b/itaja/skills)](https://skills.sh/itaja/skills)

Skills that let a coding agent integrate [Itaja](https://getitaja.com) payments into a merchant's app **securely** and within Itaja's constraints — strict `homeUrl`, server-side keys, sandbox before live, signed webhooks as the only proof of payment.

Works with Claude Code, Codex, Cursor, OpenCode and the other agents supported by the [`skills` CLI](https://github.com/vercel-labs/skills).

## Install

```bash
npx skills add itaja/skills                       # all skills
npx skills add itaja/skills --skill itaja-webhooks # one skill
```

## Skills

| Skill | Use it to |
|---|---|
| [`itaja-integration`](skills/itaja-integration/SKILL.md) | Start here: base URLs, auth, sandbox vs live, the non-negotiable constraints |
| [`itaja-checkout-session`](skills/itaja-checkout-session/SKILL.md) | Start a payment for a cart from the merchant's server |
| [`itaja-webhooks`](skills/itaja-webhooks/SKILL.md) | Verify events and fulfil orders exactly once, on live payments only |
| [`itaja-credits`](skills/itaja-credits/SKILL.md) | Sell and spend prepaid coins |
| [`itaja-go-live`](skills/itaja-go-live/SKILL.md) | Prepare the move to real money and audit an integration |

Not covered yet: licences, subscriptions, customer portal.

## The constraints in one screen

1. API key and webhook secret are **server-side only** — never in a browser, repo or log.
2. Build in **sandbox** (`itjs_` key); live money moves only when the merchant decides.
3. `homeUrl` is an absolute **`https`** URL without credentials, taken from server config, never from the request.
4. Only the **signed webhook** proves a payment — never the return page.
5. Stable `reference` / `idempotencyKey` on every creation and every money movement.
6. Production API host: `https://api.getitaja.com`.

## Source of truth

The generated OpenAPI document of the API (`/api/references/json`) is authoritative. If a skill disagrees with it, the skill is wrong — open an issue or a pull request.

## Maintaining

- A skill states only what the API does. Every claim is checked against the API reference or the code before it is written.
- No secret, no internal hostname, no private path, no internal vendor or issue number.
- Each skill lives in `skills/<name>/SKILL.md` with `name` equal to its folder and a `description` that starts with the trigger (`Use when …`).
- Conventional Commits; one change per pull request.

## License

MIT — see [LICENSE](LICENSE).
