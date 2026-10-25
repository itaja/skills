---
name: itaja-credits
description: Sells and spends Itaja prepaid credits (coins) — grant, consume, expire, read wallets and balances, sell credit packs, and let a browser or app spend a wallet with a short-lived token instead of the API key. Use when a merchant bills usage (AI tokens, messages, exports) with prepaid coins through Itaja.
license: MIT
metadata:
  author: itaja
  version: "1.0.0" # x-release-please-version
---

# Itaja credits

A `CREDITS` product holds one **wallet** of coins per customer. Coins are a prepaid service unit, **not money**, and a wallet belongs to one product: balances of two products are never summed. Read `itaja-integration` first.

**Package:** `@itaja/node` `itaja.credits.consume / grant / balance / walletToken / buy` (Python and Laravel expose the same calls) — or the adapter's own functions (`itaja-adapter`). Which one: `itaja-sdk`.

The offer (the coin's name, the packs, credit subscription plans) is declared in code with `PUT /catalog` (`itaja-catalog`) or configured in the dashboard. The routes below sell packs and move coins.

## Routes (developer API, API key)

| Method | Path | Purpose |
|---|---|---|
| `POST` | `/products/:productId/credits/grant` | Give coins (gift, compensation, sale made elsewhere) |
| `POST` | `/products/:productId/credits/consume` | Spend coins |
| `POST` | `/products/:productId/credits/expire` | Burn one customer's expired coins |
| `POST` | `/products/:productId/credits/expire-due` | Burn every customer's expired coins (Itaja already runs it daily) |
| `GET` | `/products/:productId/credits/:customerId` | Wallet + journal |
| `GET` | `/products/:productId/credits/:customerId/balance` | The number only; `0` for a customer who never bought |
| `POST` | `/products/:productId/credits/:customerId/wallet-token` | 15-minute token for the buyer's browser or app |
| `GET` | `/products/:productId/credit-packs` | Active packs (with their `catalogKey`) |
| `POST` | `/products/:productId/credit-packs/:packId/checkout` | Sell one pack |

`customerId` is Itaja's id or the merchant's own (`itaja-customers`). The product must be `CREDITS` (`409 MONETIZATION_MODE_MISMATCH`) and of the key's mode (`422 MODE_MISMATCH`).

## Grant and consume

```json
{ "customerId": "user_123", "amount": 50, "idempotencyKey": "job-8841-consume", "reason": "export-report" }
```

- `amount`: positive **integer**, at most 1,000,000,000 per movement.
- `idempotencyKey`: **required, ≥ 8 characters**, stable per business event (job id, order id), unique **per wallet**. A replay answers `replayed: true` and moves nothing; the same key with another amount or type is `409 IDEMPOTENCY_KEY_REUSED`.
- Response: `data.wallet` (`balance`, `currency`, `expiresAt`, `lowBalanceThreshold`), `data.transaction`, `data.replayed`, `data.lowBalance`.
- `409 INSUFFICIENT_CREDITS`: the wallet cannot cover the consume. Show it; do not retry.
- `grant` only reaches a customer of the product's account (`422 CUSTOMER_NOT_IN_ACCOUNT`).
- `grant` accepts `expiresAt` (future date, else `422 INVALID_DATE`) — it dates **that grant's coins only** — and `lowBalanceThreshold`.

Expired coins are never spendable: any movement first burns the coins whose date has passed. A debit takes the coins that expire first.

**Coins are not refundable.** There is no refund route. To make a customer whole, `grant` again — the journal tells the two apart. A refunded pack sale takes back what remains of its coins automatically (`credits.clawed_back`).

## Selling a pack

```json
{ "customerId": "user_123", "homeUrl": "https://app.example/credits",
  "successUrl": "https://app.example/credits/merci", "reference": "pack-order-77", "language": "fr" }
```

- Allowed keys: `customerId` (required), `homeUrl` (required), `successUrl`, `failureUrl`, `cancelUrl`, `reference`, `language`. **Any other key is `422 VALIDATION`** — no `amount`, no `quantity`, no `customer` object: the buyer's e-mail is read from the customer record.
- A **pack is the offer and the unit of sale**: price, currency and coins come from it. To sell more, sell a bigger pack.
- Return URLs follow the rule of `itaja-checkout-session`: absolute `https`, from server config, and in live on a **verified domain** (`422 LIVE_HOST_NOT_ALLOWED`).
- An unknown customer for this product is `422 CUSTOMER_NOT_FOUND`.
- Store the returned checkout `id`, redirect to `checkoutUrl`, fulfil from the webhook.
- Coins are credited **once**, when the payment is confirmed; price and coins are frozen at checkout creation. The delivery appears as `credits.granted` with `idempotencyKey = checkout:<checkoutId>`. **Do not grant manually for the same sale.**

## Letting a browser or app spend a wallet

Never put the API key in a client. The merchant's server calls `…/wallet-token` and hands the **15-minute token** to the client, which uses the key-less routes:

- `GET /public/credits/wallet?token=…`
- `POST /public/credits/consume` with `{ token, amount, idempotencyKey }`

60 requests per minute per IP and per token (`429 RATE_LIMITED`, honour `Retry-After`). The token is a bearer credential: never log it or share it in a URL.

## Credit subscriptions

A credits product can also sell **plans** that credit coins every paid period. Like every subscription today, renewal is manual: Itaja issues the period invoice 10 days ahead and reminds the buyer by e-mail. See `itaja-subscriptions`.

## Webhooks

`credits.granted`, `.consumed`, `.expired`, `.low` (balance crossed `lowBalanceThreshold`; no e-mail to the buyer), `.clawed_back`. Amounts are coin counts. See `itaja-webhooks`.

## Done when

- [ ] Every grant and consume carries a stable `idempotencyKey` (≥ 8 chars).
- [ ] Corrections are grants; no refund flow is invented.
- [ ] Pack sales send only the allowed keys and are fulfilled by the webhook, not by the return.
- [ ] A client only ever holds a wallet token, never the key.
