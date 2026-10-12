---
name: itaja-go-live
description: Use before a merchant's Itaja integration takes real money, or when reviewing an existing one. Explains the sandbox/live split, the account gates, and a pre-production checklist covering keys, homeUrl, webhooks and the production host.
---

# Going live with Itaja

Use this skill to **prepare** the move to real money and to **audit** an integration. It never performs the move for the merchant. Read `itaja-integration` first.

## How the mode works

- The key decides: `itjs_` = **sandbox** (no real charge), anything else = **live**.
- Products, checkouts and webhook events each carry their mode. A checkout created in sandbox stays `sandbox` forever, even if its product later goes live. A product of the other mode answers `422 MODE_MISMATCH`.
- Sandbox and live are separate worlds: separate keys, separate products, separate webhook secrets. Plan configuration for both.

## The account gates (do not try to bypass them)

Creating a live product or a live key is refused until the merchant's account has **all three** of:

1. account approved,
2. identity verified,
3. onboarding completed.

Until then the API answers `403` with `code: "LIVE_MODE_NOT_ALLOWED"` and `details.missing` listing what is lacking (`account_approved`, `identity_verified`, `onboarding_completed`). Nothing is written. The merchant's dashboard shows the same state.

What to do:

- Report `details.missing` to the merchant in plain words and stop. These steps happen in the dashboard and with the Itaja team; code cannot complete them.
- Do **not** retry in a loop, create a sandbox key and relabel it, or work around it by any other means.
- Sandbox work is never blocked by this gate — keep building in sandbox meanwhile.
- Live keys and products that already exist keep working; the gate applies to creation.

## Pre-production checklist

**Production host**
- [ ] `ITAJA_API_BASE_URL` is `https://api.getitaja.com`. If that host does not resolve yet, stop and tell the merchant — never send a live key to a different host.

**Secrets**
- [ ] The live key and the webhook secret are in the production secret manager, not in the repo, the image, the client bundle, the CI logs or an `.env` that is committed.
- [ ] The sandbox key is not deployed to production, and the live key is not on a developer laptop or in a preview deployment.
- [ ] Every place that reads the key is server-side. Search the front-end bundle for `itjs_` and the key prefix.
- [ ] A leaked key is replaced by regenerating it in the dashboard; the old one is dead. Nothing in the code depends on a specific key value.

**`homeUrl`**
- [ ] Comes from server configuration, set per environment.
- [ ] Absolute `https`, ≤ 2048 characters, no credentials. Against `api.getitaja.com` a `http://` or `localhost` value is refused.
- [ ] Never derived from the request (`Host`, `Origin`, query, body).

**Checkout**
- [ ] `reference` is stable per order; the returned checkout `id` is stored before the redirect.
- [ ] The order is never marked paid from the return page or its query parameters.
- [ ] `429` and `5xx` are retried with the same `reference`, with a cap; `4xx` are not.

**Webhooks**
- [ ] A live endpoint is registered in the dashboard with its own secret, on public `https`.
- [ ] Signature verified on the raw body, constant-time, timestamp window enforced.
- [ ] Event ids are deduplicated atomically with fulfilment.
- [ ] Production ignores `mode: "sandbox"` events.
- [ ] `amount` and `currency` are cross-checked against the stored order.

**Money handling**
- [ ] Amounts are major units; `XOF`/`XAF` integers, other currencies ≤ 2 decimals.
- [ ] The UI shows the response's `total`; the commission is never recomputed locally.
- [ ] `customer.email` is the buyer's real address.

## The first real payment

Once the gates are open and the checklist is green, **the merchant** makes one small real payment themselves and confirms that the order is fulfilled exactly once. An agent does not trigger live payments, and does not use real customer data for tests.

## Auditing an existing integration

Walk the checklist against the code, report each failed item with the file and line, and fix only what the merchant asked you to fix. The most common real-world failures, in order: a key reachable from the browser, fulfilment on the return page, a missing or non-atomic duplicate check on webhooks, a `homeUrl` built from the request, and a random `reference` per retry.
