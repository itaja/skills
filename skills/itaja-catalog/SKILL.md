---
name: itaja-catalog
description: Declares an Itaja catalogue in code — features, subscription plans with trials and quotas, credit packs, licence products — and pushes it with PUT /api/v1/catalog (or an SDK's push command), including dry runs, name-based identity, plan versioning and archiving. Use when a merchant wants what they sell defined in their repository and kept in sync with Itaja.
license: MIT
metadata:
  author: itaja
  version: "2.0"
---

# Declaring the catalogue in code

`PUT /catalog` sends the whole catalogue the merchant declares in code; Itaja makes its own match it — creating, versioning and archiving. Read `itaja-integration` first. With an SDK, declare it in the config file and run its push command (`itaja-sdk`); this skill is the contract underneath. In a stack without a package, keep the document as `itaja-catalog.json` in the repo and push it with a small script or CI step calling `PUT /catalog` (`itaja-adapter`).

## The document

```json
{
  "features": [{ "name": "Messages", "type": "metered" }, { "name": "Export", "type": "boolean" }],
  "products": [
    { "type": "subscription", "name": "My App", "plans": [
      { "name": "Free", "default": true,
        "includes": [{ "feature": "Messages", "limit": 100, "reset": "month" }] },
      { "name": "Pro", "trial": { "days": 7 },
        "price": { "amount": 5000, "currency": "XOF", "interval": "month" },
        "includes": [{ "feature": "Messages", "limit": 2000, "reset": "month" }, { "feature": "Export" }] } ] },
    { "type": "credits", "name": "AI tokens", "coinName": "Tokens", "packs": [
      { "name": "100 tokens", "credits": 100, "price": { "amount": 1000, "currency": "XOF" } } ] },
    { "type": "license", "name": "Desktop app", "devices": 3, "duration": "days-365",
      "price": { "amount": 25000, "currency": "XOF" } }
  ]
}
```

| `type` | Becomes | Required | Price lives on |
|---|---|---|---|
| `subscription` | a `SUBSCRIPTION` product; one row per plan | `name`, `plans` (≥ 1) | each plan: `amount`, `currency`, `interval` (`week` · `month` · `quarter` · `year`), optional `intervalCount` 1–12 |
| `credits` | a `CREDITS` product sold in packs; `coinName` defaults to the name | `name`, `packs` (≥ 1; each `name`, `credits` ≥ 1, `price`) | each pack |
| `license` | a `LICENSE` product | `name`, `price`, `devices` (`null` = unlimited) | the product; `duration`: `lifetime` (default) · `days-N` · `from-first-activation-days-N` |

Features: `boolean` or `metered`. A plan's `includes[].limit` and `reset` (`day` · `week` · `month` · `year`) apply to metered features only. `trial.days` is 0–365. Amounts are major units; packs and licences respect the item price bounds (`itaja-products`); one credits product uses one currency.

Only a group's `default` plan may omit its price: a free fallback, never sold on the pay page.

## Identity is the name

There is **no id** in the document. An item is identified by its `name`, compared trimmed, inner spaces collapsed and case-insensitively (`Pro`, ` pro `, `PRO` are one item).

| Scope | Unique within |
|---|---|
| product | the account and mode, **across every type** |
| feature | the account and mode |
| plan | its product |
| pack | its credits product (the name is the label the buyer reads) |

- A **case-only edit** changes the displayed name in place.
- **Renaming declares a new item**: the old one is archived (its contracts, wallets and keys stay on it) and the new one is created. `subscribe` with the old plan name then answers `404 PLAN_NOT_FOUND`. Rename deliberately.
- A name may never change its product's `type` (`409 CATALOG_TYPE_CHANGE`): declare a new name instead.

## Pushing

```bash
curl -sS -X PUT "$ITAJA_API_URL/catalog?dryRun=true" \
  -H "content-type: application/json" -H "x-itj-key: $ITAJA_API_KEY" \
  --data @itaja-catalog.json
```

- **Account-scoped key only** (`403 PRODUCT_SCOPE_VIOLATION`). The key's mode picks the catalogue: `itjs_` pushes sandbox, `itjp_` live. A live push passes the account's live gate (`403 LIVE_MODE_NOT_ALLOWED`).
- `?dryRun=true` computes and returns the diff, writes nothing. **Always dry-run first and show the diff to the merchant before applying a live push.**
- Without it the diff is applied in one transaction and returned: `data.summary` (`created`, `changed`, `archived`, `unchanged`) and `data.changes[]` (`kind`, `key`, `product`, `action`, `version`, `fields`).
- Idempotent: the same document twice reports everything `unchanged`. It also accepts `Idempotency-Key`.

## What a push does to what exists

- **Versions.** A change of a plan's price, currency, interval or included features creates a **new version** and takes the old one off sale. Running subscriptions keep the version they were born on; new ones take the latest. Nobody is moved automatically.
- **Archive, never delete.** An item no longer declared is archived; declaring it again brings it back.
- **Only what the catalogue manages.** Products created by hand (`POST /products`) or in the dashboard are never read, changed or archived. A code-managed product edited in the dashboard is reported `changed` and overwritten by the next push — tell the merchant to edit in code.
- **Features are stored, not enforced.** `includes` is recorded on the plan; the API does not check or meter it yet. The merchant's app enforces its own limits.

## Errors

`422 CATALOG_INVALID` lists every broken rule in `details.issues[]` (`{ path, message }`): duplicate names in one scope, unknown feature, a limit on a boolean feature, a metered feature without a limit, two defaults in a group, a paid plan without a price, packs in two currencies, a price out of bounds, a bad duration. Nothing is written. Fix them all, re-run the dry run.

## Done when

- [ ] The catalogue lives in the repository and is pushed by a deliberate command or CI step.
- [ ] Sandbox is pushed and tested before live; a live push follows a reviewed dry run.
- [ ] Renames were intended, not typo fixes that archive a plan with subscribers.
- [ ] No product is managed both by the catalogue and by hand.
