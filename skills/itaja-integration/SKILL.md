---
name: itaja-integration
description: Use when adding Itaja payments to a merchant's app, shop or backend. Start here — base URLs, authentication, sandbox vs live, the response envelope and the constraints no Itaja integration may break.
---

# Itaja integration — start here

Itaja lets a merchant take payments through a hosted checkout and a developer API. This skill is the entry point: read it first, then load the skill that matches the job.

## Pick the next skill

| The merchant wants to… | Load |
|---|---|
| Create or read products, understand the four monetization modes | `itaja-products` |
| Sell a cart / one-off order from their own code | `itaja-checkout-session` |
| Know that a payment succeeded and deliver the goods | `itaja-webhooks` |
| Look up or create customers, send buyers to the portal | `itaja-customers` |
| Sell prepaid coins and spend them per use | `itaja-credits` |
| Sell and verify software licences | `itaja-licenses` |
| Offer recurring plans (and know the current limits) | `itaja-subscriptions` |
| Move from sandbox to real money, or review an integration | `itaja-go-live` |

Payment links, subscription plans, credit offers (coin, packs) and webhook endpoints are created **in the merchant dashboard**, not through this API. Do not look for a route that creates them.

Questions that do not fit a skill: `docs/FAQ.md`.

## Environments

| Environment | API base URL | Hosted payment pages |
|---|---|---|
| Production | `https://api.getitaja.com` | `https://pay.getitaja.com` |
| Anything else | the URL the Itaja team gave the merchant | — |

Never hardcode the base URL: read it from `ITAJA_API_BASE_URL`. The developer API lives under `/api/v1`. The generated OpenAPI document is at `<base>/api/references` (JSON at `<base>/api/references/json`) — **if it disagrees with these skills, the OpenAPI document wins**, and the skill should be fixed.

`api.getitaja.com` is the production host. If it does not resolve yet, stop and tell the merchant. Do **not** point a live key at another host to get unblocked.

## Authentication and modes

- Send the key in the `x-itj-key` header (`x-api-key` is accepted as an alias; `x-itj-key` wins).
- The **key carries the mode**: keys starting `itjs_` are **sandbox** (no real charge), all others are **live**. A key only acts on products of its own mode — the other mode answers `422 MODE_MISMATCH`.
- A key is **account-scoped** or **product-scoped**. A product-scoped key can only touch its own product (`403 PRODUCT_SCOPE_VIOLATION` otherwise) and **cannot** create dynamic checkout sessions.
- Only `GET /api/v1/health` is public.
- `401` = missing or invalid key (the missing-key body is plain text). `503` = infrastructure, never a disguised `401`.

Configuration names these skills use (a convention, not an Itaja requirement):

```
ITAJA_API_BASE_URL   https://api.getitaja.com
ITAJA_API_KEY        the merchant's key (secret)
ITAJA_WEBHOOK_SECRET the signing secret of the webhook endpoint (secret)
```

## Envelope

Success: `{ "success": true, "data": … }`. Failure: `{ "success": false, "error": "<human message>", "code": "<STABLE_CODE>" }`. Branch on `code`, never on the message. Some refusals add `details`.

## Constraints no integration may break

1. **Server-side only.** The API key and the webhook secret never reach a browser, a mobile bundle, a repository, a log line or a chat. Read them from the environment or a secret manager; `.env` stays git-ignored. If a key is missing, ask the merchant to set it — never ask them to paste it to you.
2. **Sandbox first.** Build and test with an `itjs_` key. Do not use a live key to test.
3. **`homeUrl` is strict.** Absolute `https` URL, at most 2048 characters, no `user:pass@`. Whether `http` is tolerated depends on the **Itaja server** being non-production, not on the merchant's environment: against `api.getitaja.com` it is always refused. It comes from the merchant's server configuration — never from the request's `Host` header, query string or body. Details in `itaja-checkout-session`.
4. **Only the signed webhook proves a payment.** A return page, a redirect or any query parameter on it is payer-visible and forgeable. Never fulfil from them. See `itaja-webhooks`.
5. **Idempotency is not optional.** Every creation carries a stable `reference`; every money-moving call a stable `idempotencyKey`.
6. **Itaja prices the sale.** Read `subtotal`, `platformFee` and `total` from the response. Never recompute the commission.
7. **Never invent buyer data.** `customer.email` must be the real buyer's address.
8. **Never get around a gate.** `LIVE_MODE_NOT_ALLOWED`, `CUSTOMER_BANNED`, `MODE_MISMATCH`, `RATE_LIMITED` are answers, not obstacles. Report them; do not retry around them.
9. **Never trigger a real payment yourself.** Live money moves only when the merchant decides.
