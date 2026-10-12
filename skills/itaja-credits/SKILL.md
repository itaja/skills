---
name: itaja-credits
description: Use when a merchant sells prepaid coins (credits) and spends them per use through Itaja — granting, consuming, reading balances, selling credit packs and letting a browser read a wallet safely.
---

# Itaja credits

A `CREDITS` product holds **wallets** of coins per customer. Coins are a prepaid service unit, **not money**. Read `itaja-integration` first.

The offer itself (the coin's name, its form, the packs) is created in the **dashboard**. The API lists packs, sells them, and moves coins.

## Routes (developer API, key required)

| Method | Path | Purpose |
|---|---|---|
| `POST` | `/api/v1/products/:productId/credits/grant` | Give coins to a customer |
| `POST` | `/api/v1/products/:productId/credits/consume` | Spend coins |
| `GET` | `/api/v1/products/:productId/credits/:customerId` | Wallet + journal |
| `GET` | `/api/v1/products/:productId/credits/:customerId/balance` | Balance only |
| `POST` | `/api/v1/products/:productId/credits/:customerId/wallet-token` | 15-minute token for a browser |
| `GET` | `/api/v1/products/:productId/credit-packs` | Active packs |
| `POST` | `/api/v1/products/:productId/credit-packs/:packId/checkout` | Start a checkout from a pack |

The product must be in `CREDITS` mode (`409 MONETIZATION_MODE_MISMATCH` otherwise) and of the key's mode (`422 MODE_MISMATCH`).

## Grant and consume

```json
{ "customerId": "cus_…", "amount": 50, "idempotencyKey": "order-1042-consume", "reason": "export-report" }
```

- `amount` is a positive **integer**.
- `idempotencyKey` is **required, at least 8 characters**, and must be stable per business event (an order id, a job id). A replay returns `replayed: true` and moves nothing a second time. A random key per attempt defeats it.
- Response: `data.wallet` (`balance`, `currency`, …), `data.transaction`, `data.replayed`, `data.lowBalance`.
- `409 INSUFFICIENT_CREDITS` on a consume the wallet cannot cover — show it to the user; do not retry.
- `grant` also accepts optional `expiresAt` (applied only when the wallet is created) and `lowBalanceThreshold`.

**Coins are not refundable.** There is no refund route. To make a customer whole, `grant` coins again — the journal tells the two apart.

## Selling a pack

```json
{ "customerId": "cus_…", "homeUrl": "https://app.example/credits/merci",
  "customer": { "email": "acheteur@example.com" }, "reference": "pack-order-77", "language": "fr" }
```

- A **pack is the offer**: price and currency come from it. A body carrying `amount` or `quantity` is a `422`. To sell more, sell a bigger pack or another sale.
- `homeUrl` follows the strict rule in `itaja-checkout-session` (absolute `https`, no credentials, from server config).
- `customer.email` is required (`422 CUSTOMER_EMAIL_REQUIRED`); `customerId` must be reachable from the product's account (`422 CUSTOMER_NOT_FOUND`).
- Store the returned checkout `id` with your order, redirect to `checkoutUrl`, and fulfil from the webhook only.
- Coins are credited **once**, when payment is confirmed, and the amount is frozen at checkout creation — editing the pack later does not change what a pending sale delivers. The delivery shows up as `credits.granted` with `idempotencyKey` = `checkout:<checkoutId>` (see `itaja-webhooks`). Do not also grant manually for the same sale.

## Letting a browser read or spend a wallet

Never put the API key in a browser. Instead, your server calls `…/wallet-token` and hands the customer the **15-minute token**. The browser then uses the key-less routes:

- `GET /api/v1/public/credits/wallet?token=…`
- `POST /api/v1/public/credits/consume` with `{ token, amount, idempotencyKey }`

These are limited to 60 requests per minute per IP and per token (`429 RATE_LIMITED`, honour `Retry-After`). Treat the token as a short-lived secret: do not log it or put it in a URL you share.

## Scope and rate rules

- A product-scoped key reaches only its own product (`403 PRODUCT_SCOPE_VIOLATION`).
- Credit movements publish `credits.*` webhooks asynchronously; a consume never waits on the network.

## Done when

- [ ] Every grant/consume carries a stable `idempotencyKey` (≥ 8 chars).
- [ ] No refund flow exists; corrections are grants.
- [ ] Pack sales are fulfilled by the webhook, not by the return.
- [ ] The browser only ever holds a wallet token, never the key.
