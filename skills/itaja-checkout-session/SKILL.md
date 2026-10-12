---
name: itaja-checkout-session
description: Use when a merchant's server must start an Itaja payment for a cart or order (POST /api/v1/checkout-sessions). Covers the request, the strict homeUrl rule, idempotency, amounts, errors and retries.
---

# Create a checkout session

`POST /api/v1/checkout-sessions` prices a **dynamic sale** — the caller's `lineItems` are the sale, no Itaja product behind it — and returns a hosted payment URL. Read `itaja-integration` first.

## Preconditions

- An **account-scoped** key. A product-scoped key is refused with `422`.
- The key's mode matches what you intend (`itjs_` = sandbox).
- The buyer's real e-mail address.
- A server-side `homeUrl` (below).

## Request

```bash
curl -sS -X POST "$ITAJA_API_BASE_URL/api/v1/checkout-sessions" \
  -H "content-type: application/json" \
  -H "x-itj-key: $ITAJA_API_KEY" \
  -d '{
    "currency": "XOF",
    "homeUrl": "https://boutique.example/retour",
    "lineItems": [{ "name": "Sandales — édition été", "quantity": 2, "unitPrice": 2500 }],
    "description": "Sandales — édition été",
    "customer": { "email": "acheteur@example.com", "first_name": "Awa" },
    "language": "fr",
    "reference": "order-1042"
  }'
```

| Field | Rule |
|---|---|
| `currency` | Required, 3 letters (case-insensitive, stored upper-case). Itaja prices XOF, XAF, EUR, USD, GBP, CAD, NGN, GHS, KES — check the OpenAPI document before using another. |
| `lineItems` | Required, 1–100. Each: `name` (≤256), `quantity` (integer 1–999), `unitPrice` (**per unit**, not the line total, major units). |
| `homeUrl` | Required. See below. |
| `customer.email` | Required (`422 CUSTOMER_EMAIL_REQUIRED` otherwise). `first_name` / `last_name` optional. |
| `reference` | The idempotency key, ≤128 chars. Use the merchant's order id. |
| `language` | `fr`, `en` or `es` today. |
| `description`, `metadata` | Optional. `metadata` is passed through verbatim to the payment provider: **no secrets, no personal data you would not print**. |

**Do not send** `productId`, `source`, `amount`, `embed` or `callbackUrl`. Unknown keys are refused with `422`, never ignored — this route sells only the dynamic sale, and the return destination is not the caller's to choose.

### Amounts

Prices are in **major units**. `XOF` / `XAF` take whole numbers; every other currency at most two decimals. A sub-minor amount is refused, never rounded. A merchant on the `ABSORBED` fee mode is refused a sale too small to cover the fee, rather than sold at a loss.

## `homeUrl` — the rule

- Absolute, `https`, at most 2048 characters, **no credentials** (`https://user:pass@…` is refused).
- `http` is tolerated **only by a non-production Itaja server** — the check runs on Itaja's side and depends on how that server runs, not on where the merchant's app runs. `api.getitaja.com` is production: **always `https`**. For local development against it, expose the app through an `https` tunnel instead of `http://localhost`. Do not rely on `http` working anywhere you did not test.
- Build it from a **server-side setting** (for example `SHOP_PUBLIC_URL`), never from the incoming `Host` header, a query parameter or the request body. A `homeUrl` assembled from user input is an open redirect.
- Point it at a stable page of the merchant's own site that can show an order status by looking it up server-side.

A refused `homeUrl` is a `422`. Nothing is written.

## Response

`201`:

```json
{ "success": true, "data": {
  "id": "…", "checkoutUrl": "https://pay.getitaja.com/c/…", "status": "PENDING",
  "source": "dynamic", "subtotal": 5000, "platformFee": 400, "total": 5400,
  "currency": "XOF", "expiresAt": "…", "reference": "order-1042" } }
```

- **Store `data.id` against the order, before redirecting.** It is the correlation key: the `checkout.paid` webhook carries it as `checkoutId`.
- Redirect the payer to `checkoutUrl` (top-level navigation). Show the payer `total`, not `subtotal`.
- The session lives **15 minutes** (`expiresAt`). Create a new one for a new attempt; reuse the same `reference` only for the same order.

### What the return does and does not tell you

When the payer comes back, the page reads the verdict from Itaja's own database. Any query parameter appended on the way is a **hint for the UI** — forgeable, never proof. Mark the order paid only from the signed webhook (`itaja-webhooks`).

## Idempotency

A retried creation with the same `reference` (scoped to the account) returns the **same** checkout and the **same** session; the payment provider is called once. So: derive `reference` from the order id, persist it, and reuse it on any retry. A random `reference` per attempt defeats the mechanism.

## Errors

| Status / `code` | Meaning | Do |
|---|---|---|
| `401` | Missing / invalid / inactive key | Fix the key. Do not retry. |
| `422` | Bad body: sub-minor amount, unsafe `homeUrl`, no line items, unknown key, product-scoped key, payload refused upstream | Fix the request. Do not retry. |
| `422 CUSTOMER_EMAIL_REQUIRED` | No `customer.email` | Collect the real address. |
| `422 CUSTOMER_BANNED` | This merchant does not sell to this buyer; the message carries the merchant's reason | Show it to the merchant/buyer as written. Do not work around it. |
| `422 MODE_MISMATCH` | Key mode vs. product mode | Use the right key. |
| `429 RATE_LIMITED` | 60 creations per minute **per account** (all keys together) | Wait `Retry-After` seconds, then retry the same `reference`. |
| `502` | Payment provider failed (no detail given) | Retry with backoff, **same `reference`**. |
| `503 CHECKOUT_NOT_CONFIGURED` | This deployment cannot take payments yet; nothing written | Stop and tell the merchant. |
| `503 SERVICE_UNAVAILABLE` | Infrastructure, `Retry-After: 5` | Retry with backoff, same `reference`. |

Retry only `429`, `502`, `503` and network timeouts, always with the same `reference`, with a cap (say 3 attempts). Never retry a `4xx` other than `429`.

## Done when

- [ ] Key and base URL come from the environment; nothing secret in the repo or the client.
- [ ] `homeUrl` comes from server config and is `https` in production.
- [ ] `data.id` is stored with the order before the redirect.
- [ ] `reference` is stable per order.
- [ ] The order is marked paid by the webhook handler only.
