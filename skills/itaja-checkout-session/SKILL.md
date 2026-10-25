---
name: itaja-checkout-session
description: Starts an Itaja payment for a cart or one-off order priced by the merchant's own code (POST /api/v1/checkout-sessions), and quotes fees beforehand (POST /api/v1/quotes). Covers the strict homeUrl and return-URL rules, verified live domains, idempotency, amounts, errors and retries. Use when a merchant's server must take a one-off payment.
license: MIT
metadata:
  author: itaja
  version: "2.0"
---

# Create a checkout session

`POST /checkout-sessions` prices a **dynamic sale** — the caller's `lineItems` are the sale, no Itaja product behind it — and returns a hosted payment URL. Read `itaja-integration` first.

Selling something the catalogue already prices? Use the dedicated route instead: a credit pack (`itaja-credits`), a subscription plan (`itaja-subscriptions`).

## Preconditions

- An **account-scoped** key (a product-scoped key is refused `422`).
- The key's mode is the one intended (`itjs_` = sandbox).
- The buyer's real e-mail address.
- Server-side return URLs (below). In live, their hosts are verified domains of the account.

## Request

```bash
curl -sS -X POST "$ITAJA_API_URL/checkout-sessions" \
  -H "content-type: application/json" \
  -H "x-itj-key: $ITAJA_API_KEY" \
  -H "Idempotency-Key: order-1042" \
  -d '{
    "currency": "XOF",
    "homeUrl": "https://boutique.example/commande/1042",
    "successUrl": "https://boutique.example/commande/1042/merci",
    "cancelUrl": "https://boutique.example/panier",
    "lineItems": [{ "name": "Sandales — édition été", "quantity": 2, "unitPrice": 2500 }],
    "customer": { "email": "acheteur@example.com", "first_name": "Awa" },
    "language": "fr",
    "reference": "order-1042"
  }'
```

| Field | Rule |
|---|---|
| `currency` | Required, 3 letters: XOF, XAF, EUR, USD, GBP, CAD, NGN, GHS, KES. |
| `lineItems` | Required, 1–100. Each: `name` (≤256), `quantity` (integer 1–999), `unitPrice` (**per unit**, major units). |
| `homeUrl` | Required. The payer's default return address. Rules below. |
| `successUrl`, `failureUrl`, `cancelUrl` | Optional, same rules as `homeUrl`. One per outcome. |
| `customer.email` | Required (`422 CUSTOMER_EMAIL_REQUIRED`). `first_name`, `last_name` optional. |
| `reference` | The body idempotency key, ≤128 chars. Use the order id. |
| `language` | `fr`, `en` or `es`. |
| `description` (≤512), `metadata` | Optional. `metadata` is passed to the payment provider: no secrets, no personal data you would not print. |

**Unknown keys are refused `422`, never ignored.** In particular, do not send `productId`, `source`, `amount`, `embed` or `callbackUrl`.

Amounts are in **major units**. `XOF` / `XAF` take whole numbers, other currencies at most two decimals; a sub-minor amount is refused, never rounded. A merchant whose fee mode is `ABSORBED` is refused a sale too small to cover the fee.

## Return URLs — the rule

1. Absolute `https`, ≤ 2048 characters, **no credentials** (`https://user:pass@…` is refused). The production server refuses `http`.
2. Built from a **server-side setting** (for example `SHOP_PUBLIC_URL`), never from the incoming `Host` header, a query parameter or the body. A return URL assembled from user input is an open redirect.
3. **In live, every return URL's host must be a domain the account declared and Itaja verified.** Otherwise `422 LIVE_HOST_NOT_ALLOWED`, nothing written. The match is **exact**: `example.com` does not cover `shop.example.com`. `localhost`, `*.localhost`, `127.0.0.1` and `[::1]` are exempt. Sandbox is never concerned. Declaring and verifying a domain is a dashboard step (Settings → Domains) followed by Itaja's review — the agent cannot do it; tell the merchant which host to declare.

After payment, Itaja's return page reads the outcome from its database and sends the payer to `successUrl` (paid), `cancelUrl` (cancelled) or `failureUrl` (refused, expired, abandoned). An outcome without its own URL stays on Itaja's page. Itaja appends `itaja_checkout_id` and `itaja_status` (`success | failure | cancel`) and keeps your query string.

**Those parameters are payer-visible and forgeable.** The page that receives them shows a status looked up server-side; it never marks an order paid. Only the signed webhook does (`itaja-webhooks`).

## Response

`201`:

```json
{ "success": true, "data": {
  "id": "…", "checkoutUrl": "https://pay.getitaja.com/c/…", "status": "PENDING",
  "source": "dynamic", "subtotal": 5000, "platformFee": 500, "total": 5500,
  "currency": "XOF", "expiresAt": "…", "reference": "order-1042" } }
```

- **Store `data.id` with the order before redirecting.** The `checkout.paid` webhook carries it as `data.checkoutId`.
- Redirect the payer to `checkoutUrl` (top-level navigation, no iframe). On that page the payer accepts the Terms of sale before paying; do not try to skip it.
- Show the payer `total`, not `subtotal`.
- The session lives **15 minutes** (`expiresAt`). A new attempt is a new session.

## Quote before you create

`POST /quotes` with `{ "amount": 5000, "currency": "XOF" }` answers what the payer will be charged and what the merchant receives, from the merchant's fee mode. Nothing is created and nobody is charged.

```json
{ "currency": "XOF", "feeMode": "ABSORBED", "subtotal": 5000, "platformFee": 500,
  "total": 5100, "merchantNet": 4600,
  "fees": { "rate": { "percent": 8, "amount": 400 }, "fixed": { "amount": 100 },
            "paidByPayer": 100, "paidByMerchant": 400 } }
```

Use it to show a price "fees included" in a cart. A quote is not a promise: if the merchant changes the fee mode, the checkout follows the new mode. It refuses what the checkout would refuse (`422 INVALID_AMOUNT`, `INVALID_REQUEST`, `VALIDATION`).

## Idempotency

A retried creation with the same `reference` (scoped to the account) returns the **same** checkout and session; the provider is called once. Send an `Idempotency-Key` too. Derive both from the order id, persist them, reuse them on every retry.

## Errors

| Status / `code` | Meaning | Do |
|---|---|---|
| `401` | Missing, invalid or inactive key | Fix the key. Do not retry. |
| `422` | Bad body: sub-minor amount, unsafe URL, no line items, unknown key, product-scoped key, payload refused upstream | Fix the request. Do not retry. |
| `422 LIVE_HOST_NOT_ALLOWED` | A return URL's host is not a verified domain (live) | Tell the merchant which host to declare. Do not switch to another host. |
| `422 CUSTOMER_EMAIL_REQUIRED` | No `customer.email` | Collect the real address. |
| `422 CUSTOMER_BANNED` | This merchant does not sell to this buyer; the message is the merchant's reason | Show it as written. Do not work around it. |
| `429 RATE_LIMITED` | 60 creations per minute per account, all keys together | Wait `Retry-After`, retry with the same `reference`. |
| `502` | Payment provider failed | Retry with backoff, same `reference`. |
| `503 CHECKOUT_NOT_CONFIGURED` | This deployment cannot take payments; nothing written | Stop and tell the merchant. |
| `503 SERVICE_UNAVAILABLE` | Infrastructure | Retry with backoff, same `reference`. |

## Done when

- [ ] Key and base URL come from the environment; nothing secret in the repo or the client.
- [ ] Return URLs come from server config, are `https`, and in live use verified hosts.
- [ ] `data.id` is stored with the order before the redirect.
- [ ] `reference` (and `Idempotency-Key`) are stable per order.
- [ ] The order is marked paid by the webhook handler only — never by a return URL.
