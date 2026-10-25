---
name: itaja-products
description: Creates, reads, updates and archives Itaja products through the developer API (POST /api/v1/products), and explains the three API monetization modes, why a mode never changes, sandbox vs live products and item price bounds. Use when a merchant's code manages products one by one; for a catalogue declared in code use itaja-catalog instead.
license: MIT
metadata:
  author: itaja
  version: "1.0.0" # x-release-please-version
---

# Itaja products

A **product** is the hub of the model: subscription plans, credit packs and licence keys hang off it. Read `itaja-integration` first.

## Two ways to create products — pick one per product

| Way | When | Skill |
|---|---|---|
| `PUT /catalog` — declare plans, packs and licences in code, push them | the merchant wants what they sell versioned with their code (recommended with an SDK) | `itaja-catalog` |
| `POST /products` + dashboard — create the product, then price it in the dashboard | a few hand-made products | this skill |

A push never reads, changes or archives a product created by hand or in the dashboard, and the reverse is true: do not manage the same product both ways.

## Routes

| Method | Path | Notes |
|---|---|---|
| `POST` | `/products` | Create. **Account-scoped key only.** |
| `GET` | `/products` | Products of the **key's mode only** (`?includeArchived`) |
| `GET` | `/products/:productId` | Read |
| `PATCH` | `/products/:productId` | Update the mutable fields |
| `POST` | `/products/:productId/archive` | Take off sale for good, history kept; idempotent |

## One product = one monetization mode, forever

`monetizationMode` is required at creation. On the developer API it is one of:

| Mode | Sells | Load |
|---|---|---|
| `SUBSCRIPTION` | recurring plans | `itaja-subscriptions` |
| `CREDITS` | prepaid coins (packs, or a credit subscription) | `itaja-credits` |
| `LICENSE` | licence keys | `itaja-licenses` |

A fourth mode, `PAYLINK` (a one-off price on a link), exists **in the dashboard only**: the API refuses to create one (`422`) and answers `404` for one made in the dashboard. A one-off sale from code is a dynamic checkout (`itaja-checkout-session`), not a product.

The mode **cannot change**. A `PATCH` carrying `monetizationMode` answers `200` and ignores the field. A sub-resource of the wrong mode answers `409 MONETIZATION_MODE_MISMATCH`. If the mode was wrong, create a new product and archive the old one.

## Create

```json
{ "name": "Atelier Awa — Desktop", "monetizationMode": "LICENSE", "currency": "XOF" }
```

| Field | Rule |
|---|---|
| `name` | Required, 1–120 chars. |
| `monetizationMode` | Required: `SUBSCRIPTION`, `CREDITS` or `LICENSE`. |
| `mode` | Omit it: with an API key the key's mode is imposed. |
| `currency` | Optional; checked even alone (`400 INVALID_CURRENCY`), stored upper-case. |
| `status` (`DRAFT` / `ACTIVE`), `slug`, `description`, `metadata`, `goodsCategory`, `goodsType` | Optional. |

Refused with `422`:

- `amount` — **a product carries no price.** Plans, packs and licences are priced in the dashboard or by `PUT /catalog`.
- `creditCoinName`, `creditForm` — the credit offer is configured in the dashboard or by the catalogue.

### Sandbox and live products

- A key creates products **of its own mode**; the other mode is out of reach (`422 MODE_MISMATCH`).
- Creating a live product is gated on the account being approved, identity-verified and onboarded: `403 LIVE_MODE_NOT_ALLOWED` with `details.missing`. See `itaja-go-live`. Existing live products keep working.
- Sandbox and live products never share an id. **Store product ids per environment.**

## `PATCH` and `metadata`

- An object **merges one level deep**: keys you do not name survive; a key you name is **replaced whole**.
- `null` clears all metadata; omitted leaves it alone; `{}` writes nothing.
- Never store secrets or buyer data in `metadata`. Do not write `metadata.license` yourself — use `PUT …/license-settings` (`itaja-licenses`).
- `portalActivation` (boolean, `LICENSE` products only) lets buyers activate the browser they use the customer portal from.

## Item price bounds

Licence prices and credit-pack prices: maximum 200,000 XOF, every other currency that amount converted; the minimum is the higher of 500 XOF converted and four times the flat fee. Indicative values:

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

The figures move with the exchange table: treat `INVALID_AMOUNT` / `INVALID_CURRENCY` as the authority. Subscription plan prices and dynamic checkout line items are outside these bounds, but their currency must be one of the nine above and respect its decimals.

## Done when

- [ ] The monetization mode was chosen deliberately and is never patched.
- [ ] Product ids are configured per environment, not hardcoded.
- [ ] No product is managed both by hand and by the catalogue.
- [ ] `403 LIVE_MODE_NOT_ALLOWED` is reported to the merchant, not retried.
