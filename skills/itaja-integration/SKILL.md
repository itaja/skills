---
name: itaja-integration
description: Entry point for integrating Itaja payments (merchant of record for African businesses) into a merchant's app, shop or backend — base URL, API keys, sandbox vs live, the response envelope, idempotency and the constraints no integration may break. Use when adding Itaja to any codebase, before loading a more specific itaja-* skill.
license: MIT
metadata:
  author: itaja
  version: "1.0.0" # x-release-please-version
---

# Itaja integration — start here

Itaja lets a merchant sell one-off orders, subscriptions, prepaid credits and software licences through hosted payment pages and a developer API. Read this skill first, then load the one that matches the job.

## Pick the next skill

**First, always: `itaja-sdk`** — it reads the repository's manifests and picks the official package for the stack (`@itaja/node`, `itaja` for Python, `itaja/itaja-laravel`), or sends you to `itaja-adapter` to build a small tested wrapper when no package fits the language. Then:

| The merchant wants to… | Load |
|---|---|
| Choose and install the right package for this repo | `itaja-sdk` |
| Wrap the API in a language without an official package | `itaja-adapter` |
| Declare what they sell in code and push it (plans, packs, licences) | `itaja-catalog` |
| Create or read products by hand; the monetization modes | `itaja-products` |
| Sell a cart or one-off order priced by their own code; quote fees | `itaja-checkout-session` |
| Receive and trust payment and lifecycle events | `itaja-webhooks` |
| Create or find customers, use their own user id, the buyer portal | `itaja-customers` |
| Sell and spend prepaid coins | `itaja-credits` |
| Sell software licence keys | `itaja-licenses` |
| Sell recurring plans, upgrade, downgrade, cancel | `itaja-subscriptions` |
| Prepare the move to real money, or audit an integration | `itaja-go-live` |

Questions that do not fit a skill: `docs/FAQ.md`. What exists where, and what does not exist: `docs/COVERAGE.md`.

## Base URL and reference

| Environment | Developer API base URL |
|---|---|
| Production | `https://api.getitaja.com/api/v1` |

Read the base URL from configuration; never hardcode it. The generated OpenAPI 3.1 document is at `https://api.getitaja.com/api/references/json` (UI: `/api/references`). **If it disagrees with these skills, the OpenAPI document wins** — follow it and report the gap.

Never point a live key at another host to get unblocked. If the production host does not answer, stop and tell the merchant.

## Keys and modes

- Send the key in the `x-itj-key` header (`x-api-key` is an accepted alias; `x-itj-key` wins).
- **The key carries the mode**: `itjs_…` is **sandbox** (no real charge), `itjp_…` is **live**. A key acts only on products of its own mode; the other mode answers `422 MODE_MISMATCH`.
- A key is **account-scoped** or **product-scoped**, and some routes need one or the other:

| Route family | Key it needs |
|---|---|
| Create a product, push the catalogue, dynamic checkout session | account-scoped |
| Customers (`/customers…`) | **product-scoped** (`403 PRODUCT_SCOPE_VIOLATION` with an account key) |
| Anything under `/products/:productId/…`, subscriptions, quotes | either; a product-scoped key reaches only its own product |

- Only `GET /health` is public. `401` means a missing or invalid key (the missing-key body is plain text). `503` means infrastructure, never a disguised `401`.

Configuration names used across these skills — the same names the official SDKs read:

```
ITAJA_API_URL         https://api.getitaja.com/api/v1
ITAJA_API_KEY         the merchant's key (secret)
ITAJA_WEBHOOK_SECRET  the signing secret of the webhook endpoint (secret)
```

## Envelope and retries

Success: `{ "success": true, "data": … }`. Failure: `{ "success": false, "error": "<human message>", "code": "<STABLE_CODE>" }`, sometimes with `details`. Branch on `code`, never on the message. A resource that exists but belongs to someone else answers the same `404` as one that does not exist.

Retry only `429`, `502`, `503`, `409 IDEMPOTENCY_KEY_IN_PROGRESS` and network failures — with the **same** idempotency key, backoff, about 3 attempts, `Retry-After` honoured. Never retry another `4xx`.

## Idempotency — two layers

1. **`Idempotency-Key` header** on every write (`POST`, `PUT`, `PATCH`, `DELETE`): one value per logical operation, sent again on each retry.

   | Answer | Meaning |
   |---|---|
   | stored response + `Idempotent-Replayed: true` | the first call already ran; nothing ran twice |
   | `422 IDEMPOTENCY_KEY_REUSED` | same key, different request |
   | `409 IDEMPOTENCY_KEY_IN_PROGRESS` | first call still running; retry after `Retry-After: 1` |
   | `409 IDEMPOTENCY_KEY_OUTCOME_UNKNOWN` | the first call may have run: fetch the resource, then use a new key if it is missing |

   Keys live 24 h, scoped to the account and the key's mode. `5xx`, `429` and `404` are not stored, so a retry under the same key runs.
2. **Per-domain keys in the body** stay required where they exist: `reference` on checkouts, `idempotencyKey` on credit movements.

Derive both from the merchant's own ids (order id, job id). A random value per attempt defeats them.

## Constraints no integration may break

1. **Server-side only.** The API key and the webhook secret never reach a browser, a mobile bundle, a repository, a log line or a chat. Read them from the environment or a secret manager; `.env` stays git-ignored. If a key is missing, ask the merchant to set it — never ask them to paste it to you.
2. **Sandbox first.** Build and test with an `itjs_` key. Never test with a live key.
3. **Return URLs are strict.** `homeUrl` and the optional `successUrl` / `failureUrl` / `cancelUrl` are absolute `https`, at most 2048 characters, without `user:pass@`, built from server configuration — never from the request's `Host`, query string or body. In **live**, each host must be a domain the merchant declared and Itaja **verified** (`422 LIVE_HOST_NOT_ALLOWED`). Details in `itaja-checkout-session`.
4. **Only the signed webhook proves a payment.** A return page, a redirect or a query parameter on it is payer-visible and forgeable. See `itaja-webhooks`.
5. **Idempotency is not optional** (section above).
6. **Itaja prices the sale.** Read `subtotal`, `platformFee`, `total` from the response, or ask `POST /quotes` first. Never recompute the commission.
7. **Never invent buyer data.** The customer's e-mail is the real buyer's address.
8. **A refusal is an answer.** `LIVE_MODE_NOT_ALLOWED`, `LIVE_HOST_NOT_ALLOWED`, `CUSTOMER_BANNED`, `MODE_MISMATCH`, `RATE_LIMITED` are reported to the merchant, never worked around.
9. **Never trigger a real payment yourself.** Live money moves only when the merchant decides.
10. **Never invent a route.** If what the merchant needs is dashboard-only or missing, say so.
