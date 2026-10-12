---
name: itaja-products
description: Use when a merchant's code must create or read Itaja products (POST /api/v1/products) — the four monetization modes, why the mode never changes, sandbox/live product creation, price bounds and supported currencies.
---

# Itaja products

A **product** is the hub of the model. Everything else (a share link, credit packs, licence keys, subscription plans) hangs off it. Read `itaja-integration` first.

## What the API does and does not do

| Done through the API (`x-itj-key`) | Done in the **dashboard** only |
|---|---|
| Create / read / update / archive a product | Create, edit or deactivate the **share link** |
| | Create subscription plans, credit packs and the coin |
| | Webhook endpoints, API keys, domains, brand colours |

A product is **born with its share link** (`POST /api/v1/products/`). Do not look for a payment-link route on the developer API: there is none.

## Routes

| Method | Path | Notes |
|---|---|---|
| `POST` | `/api/v1/products/` | Create |
| `GET` | `/api/v1/products/` | Lists the products of the **key's mode only** (`?includeArchived`, `?limit`) |
| `GET` | `/api/v1/products/:productId` | Read |
| `PATCH` | `/api/v1/products/:productId` | Update mutable fields |
| `POST` | `/api/v1/products/:productId/archive` | Soft delete, idempotent |

## One product = one monetization mode, forever

`monetizationMode` is required at creation and is one of:

| Mode | Sells | Load |
|---|---|---|
| `PAYLINK` | a one-off price on the share link | this skill |
| `CREDITS` | prepaid coins (packs, or a credit subscription) | `itaja-credits` |
| `LICENSE` | licence keys | `itaja-licenses` |
| `SUBSCRIPTION` | recurring plans | `itaja-subscriptions` |

The mode **cannot change**. A `PATCH` carrying `monetizationMode` answers `200` but the field is ignored. Attaching something to a product of the wrong mode answers `409 MONETIZATION_MODE_MISMATCH`. If the merchant chose the wrong mode, create a new product and archive the old one — never try to "migrate" it.

## Create

```json
{ "name": "Atelier Awa — Pro", "monetizationMode": "LICENSE", "currency": "XOF", "amount": 15000 }
```

| Field | Rule |
|---|---|
| `name` | Required, 1–120 chars. |
| `monetizationMode` | Required. |
| `mode` | `sandbox` or `live`. **Omit it with an API key**: the key's mode is imposed. (A dashboard session chooses; default `sandbox`.) |
| `amount` | Optional price of the born link. Omitted or `null` = unpriced link — normal for `SUBSCRIPTION`, whose prices live in plans. |
| `currency` | Optional; validated even without an amount (`400 INVALID_CURRENCY`); stored upper-case. |
| `creditCoinName`, `creditForm` | `CREDITS` only; refused on any other mode. |
| `status`, `slug`, `description`, `metadata`, `goodsCategory`, `goodsType` | Optional. |

A refused `amount` (`400 INVALID_AMOUNT`) leaves **neither a product nor a link**.

### Sandbox and live products

- A key creates products **of its own mode**. A sandbox key cannot reach live products and vice versa (`422 MODE_MISMATCH`).
- Creating a live product is gated on the account being approved, identity-verified and onboarded: `403 LIVE_MODE_NOT_ALLOWED` with `details.missing`. See `itaja-go-live`. Existing live products keep working.
- Sandbox and live products never share an id. **Store the product id per environment** (`ITAJA_PRODUCT_ID` differs between sandbox and production).

## Price bounds (links, born links, credit packs)

Maximum 200,000 XOF; other currencies are that amount converted. The minimum is the higher of 500 XOF converted and four times the flat fee.

| Currency | Min | Max | Decimals |
|---|--:|--:|--:|
| XOF, XAF | 500 | 200,000 | 0 |
| EUR | 2.00 | 304.89 | 2 |
| USD | 2.00 | 341.71 | 2 |
| GBP | 2.00 | 258.44 | 2 |
| CAD | 2.00 | 486.82 | 2 |
| NGN | 1,152.04 | 460,815.83 | 2 |
| GHS | 10.10 | 4,038.41 | 2 |
| KES | 111.78 | 44,710.29 | 2 |

These figures move with the exchange table. Treat a `400 INVALID_AMOUNT` / `INVALID_CURRENCY` as the authority and read the live table from the API reference rather than hardcoding it. Dynamic checkout line items (`itaja-checkout-session`) and subscription plan prices are outside these bounds, but their currency must still be in the table.

## `PATCH` and `metadata`

- An object **merges one level deep**: keys you do not name survive; a key you do name is **replaced whole**.
- `null` clears all metadata; omitted leaves it alone; `{}` writes nothing.
- Never use `metadata` to store secrets or buyer data. Do not write `metadata.license` directly — use `PUT …/license-settings` (`itaja-licenses`).

## Scope

A **product-scoped key** reads and writes only its own product (`403 PRODUCT_SCOPE_VIOLATION` for any other id, whether it exists or not). Prefer a product-scoped key for a service that only touches one product; use an account-scoped key only where needed (dynamic checkout sessions require one).

## Done when

- [ ] The monetization mode was chosen deliberately and is never patched.
- [ ] Product ids are configured per environment, not hardcoded.
- [ ] No code depends on creating share links or plans through the API.
- [ ] `403 LIVE_MODE_NOT_ALLOWED` is surfaced to the merchant, not retried.
