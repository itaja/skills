---
name: itaja-go-live
description: Prepares an Itaja integration for real money and audits existing ones — the sandbox/live split, the account gates (LIVE_MODE_NOT_ALLOWED), verified live domains (LIVE_HOST_NOT_ALLOWED), and a pre-production checklist for keys, return URLs, webhooks, idempotency and the production host. Use before an Itaja integration takes live payments, or when reviewing one.
license: MIT
metadata:
  author: itaja
  version: "2.0"
---

# Going live with Itaja

Use this skill to **prepare** the move to real money and to **audit** an integration. It never performs the move for the merchant. Read `itaja-integration` first.

## How the mode works

- The key decides: `itjs_` = **sandbox**, `itjp_` = **live**.
- Products, checkouts, catalogues and subscriptions each carry their mode; a key never reaches the other mode (`422 MODE_MISMATCH`). A sandbox checkout stays sandbox forever.
- Sandbox and live are separate worlds: separate keys, product ids, catalogue pushes, webhook endpoints and secrets. Configure both.

## Gate 1 — the account (`LIVE_MODE_NOT_ALLOWED`)

Creating a live product, pushing a live catalogue or creating a live key is refused until the account has **all three** of: account approved, identity verified (KYC/KYB file reviewed by Itaja), onboarding completed. The API answers `403 LIVE_MODE_NOT_ALLOWED` with `details.missing` (`account_approved`, `identity_verified`, `onboarding_completed`); nothing is written.

- Report `details.missing` to the merchant in plain words and stop. These steps happen in the dashboard and with the Itaja team.
- Never retry in a loop, relabel a sandbox key, or work around it.
- Sandbox is never blocked; keep building there.
- Existing live keys and products keep working: the gate applies to creation.

## Gate 2 — verified domains (`LIVE_HOST_NOT_ALLOWED`)

In live, a payer is only ever sent back to a host the account has **verified**. Every `homeUrl`, `successUrl`, `failureUrl` and `cancelUrl` of a live checkout or credit-pack sale must be on such a host, or the call is `422 LIVE_HOST_NOT_ALLOWED`, nothing written.

- The merchant declares each site in the dashboard (Settings → Domains); it is born `PENDING`, and only Itaja moves it to `VERIFIED` or `REJECTED` (with a reason shown in the dashboard).
- The match is **exact**: `shop.example.com` needs its own entry even if `example.com` is verified.
- `localhost` and loopback addresses are exempt, so a developer can try live from their machine; production URLs are not.
- An agent lists the hosts the code uses, tells the merchant which to declare, and waits. It never swaps to another host to pass the check.

## Pre-production checklist

**Production host**
- [ ] `ITAJA_API_URL` is `https://api.getitaja.com/api/v1` (or unset with an SDK). A live key is never sent elsewhere.

**Secrets**
- [ ] The live key and webhook secret are in the production secret manager — not in the repo, the image, the client bundle, CI logs, or a committed `.env`.
- [ ] The sandbox key is not deployed to production; the live key is not on a laptop or a preview deployment.
- [ ] Every place that reads the key is server-side. Search the built front-end for `itjs_` / `itjp_`.
- [ ] A leaked key is regenerated in the dashboard; nothing depends on a key's value.

**Return URLs**
- [ ] Built from server configuration per environment; never from `Host`, `Origin`, query or body.
- [ ] Absolute `https`, ≤ 2048 characters, no credentials.
- [ ] Every production host is declared and **verified** for the account.

**Catalogue (if used)**
- [ ] The live push followed a reviewed `?dryRun=true` diff; product ids or names are configured per environment.

**Checkout and subscriptions**
- [ ] `reference` and `Idempotency-Key` are stable per order; the checkout id is stored before the redirect.
- [ ] Nothing is fulfilled from a return URL or its query parameters.
- [ ] `429`, `5xx`, `409 IDEMPOTENCY_KEY_IN_PROGRESS` are retried with the same key and a cap; other `4xx` are not.
- [ ] A subscription `paymentUrl` is used at once, never cached past `paymentUrlExpiresAt`.

**Webhooks**
- [ ] A live endpoint with its own secret is registered on public `https`, subscribed only to handled topics.
- [ ] Raw-body signature verification, constant-time, every signature, timestamp window.
- [ ] Event ids deduplicated atomically with fulfilment (shared store if several instances).
- [ ] Production ignores sandbox events; `amount` and `currency` are cross-checked.

**Money**
- [ ] Amounts in major units; XOF/XAF whole, others ≤ 2 decimals.
- [ ] The UI shows `total` (or a `/quotes` answer); the commission is never recomputed.
- [ ] `customer.email` is the real buyer's address.

## The first real payment

Once both gates are open and the checklist is green, **the merchant** makes one small real payment and confirms the order is fulfilled exactly once. An agent never triggers live payments and never uses real customer data in tests.

## Auditing an existing integration

Walk the checklist against the code, report each failed item with file and line, and fix only what the merchant asked. The most frequent findings, in order: a key reachable from the browser; fulfilment on the return page; a missing or non-atomic duplicate check on webhooks; a return URL built from the request or on an unverified host; a random `reference` per retry; a handler waiting on a webhook topic that is never emitted.
