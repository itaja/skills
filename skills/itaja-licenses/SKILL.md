---
name: itaja-licenses
description: Sells software licence keys through Itaja — issuing and managing keys from the merchant's server, verifying and activating keys from the shipped app without any API key, offline entitlement tokens, rotation, suspension, reseller batches and key hand-off to the merchant's site. Use when a merchant sells desktop, mobile or self-hosted software unlocked by a key.
license: MIT
metadata:
  author: itaja
  version: "2.0"
---

# Itaja licences

A `LICENSE` product issues keys shaped `ITJ-XXXX-XXXX-XXXX`. Read `itaja-integration` first. Two audiences, two kinds of route — keep them apart.

| | Who calls | Credential |
|---|---|---|
| **Management** (`/api/v1/products/:productId/licenses…`) | the merchant's **server** | API key (`x-itj-key`) |
| **Verification** (`/api/v1/licenses/verify`, `activate`, `deactivate`) | the **app** the buyer runs | none — **the licence key itself is the credential** |

Never ship an API key inside the app to "make verification work". The public routes need none.

## How a buyer gets a key

A paid checkout on a `LICENSE` product issues the key and delivers it through **two channels**: the payment return page and a transactional e-mail (sent once per checkout). When the receipt is lost:

- **`resend`** — sends the **same** key to the purchase e-mail again. Devices already activated are untouched.
- **`rotate`** — revokes the key and issues a replacement, returned in clear exactly once. Every device must re-activate. It is a **security gesture** (leak, dispute, refund), not a convenience.

A refund revokes the issued key automatically.

## Management routes (server, API key)

| Method | Path | Purpose |
|---|---|---|
| `POST` | `/products/:productId/licenses` | Issue a key (clear key returned **once**) |
| `POST` | `/products/:productId/licenses/expire-due` | Mark keys past their end date as `EXPIRED` |
| `GET` | `/products/:productId/licenses` | List, **masked** keys, `limit` 1–100 (default 20), `cursor` |
| `GET` | `/products/:productId/licenses/:keyId` | One key and its activations |
| `POST` | `…/:keyId/revoke` | Terminal. Optional `reason`. |
| `POST` | `…/:keyId/suspend` · `…/resume` | Reversible hold |
| `POST` | `…/:keyId/rotate` | Revoke + replace |
| `POST` | `…/:keyId/resend` | Re-send to the purchase e-mail |
| `PATCH` | `…/:keyId` | Adjust `activationsMax` and/or `expiresAt` |
| `GET` / `PUT` | `/products/:productId/license-settings` | Duration and default activation limit |

(all under `/api/v1`)

- **Issuing** takes `{ customerId?, activationsMax?, expiresAt? }`; `customerId` is Itaja's id or the merchant's own (`itaja-customers`). A key bought on the pay page is issued automatically — do not also issue one by hand for the same sale.
- **Masked keys** look like `ITJ-••••-••••-XXXX`. They are not keys and never parse as one. **No read route ever returns a clear key again.** If the merchant did not store the clear key at issuance, the answer is `resend` or `rotate`, not another `GET`.
- **Paging:** walk `?cursor=<meta.nextCursor>` until it is `null`. An empty or malformed cursor is `422 INVALID_CURSOR`; it never restarts at page 1.
- **Transitions:** `REVOKED` is terminal — only a reissue brings a customer back. `EXPIRED` keys do not rotate (`409 LICENSE_EXPIRED`); renew them with `PATCH` and a future `expiresAt`, which also returns the key to `ACTIVE`. A repeated `revoke` is a `200` that writes nothing.
- `activationsMax`: integer ≥ 1, or `null` for unlimited. Issuance defaults to **1** when nothing is said.
- `duration` (settings): `lifetime`, `days-N` (clock starts at issuance) or `from-first-activation-days-N` (clock starts at the first successful activation).
- `resend` needs a purchaser (`409 LICENSE_NO_RECIPIENT` otherwise) and a configured mail transport (`503 LICENSE_DELIVERY_UNAVAILABLE`).

## Verification routes (app, no API key)

```http
POST /api/v1/licenses/verify
{ "key": "ITJ-…", "productId": "prd_…", "fingerprint": "sha256:…" }

POST /api/v1/licenses/activate
{ "key": "ITJ-…", "productId": "prd_…", "fingerprint": "sha256:…",
  "instanceId": "my-machine-1", "label": "Awa's Laptop" }

POST /api/v1/licenses/deactivate
```

- **Always send `productId`.** These routes find a key by its value alone, so a key bought for *any* Itaja product — a free sandbox one included — is otherwise "valid". With a `productId` that is not the key's, `verify` answers `valid:false, reason:"PRODUCT_MISMATCH"` and `activate` answers `422 PRODUCT_MISMATCH` before using a slot. Without it, you must compare the verdict's `productId` yourself.
- `fingerprint` is a **stable, non-identifying device handle** — hash it (`sha256:…`). Never send a serial number, MAC address or user name in clear. Re-activating a live instance rebinds its fingerprint and uses no extra slot.
- A suspended key is **never** lifted by `activate`. Only the merchant's `resume` does.
- Verdicts carry `valid`, `status`, `reason`, `productId`, `productName`, `mode`, `expiresAt`, `activationsMax` (`null` = unlimited), `activationsUsed`, and an optional `entitlementToken`.
- A sandbox product and a live product never share an id, so pinning `productId` also keeps sandbox keys out of a live app.
- **Rate limit:** 60 requests/minute per IP and per key (`429`, honour `Retry-After`). Do not call `verify` on every action — cache the verdict for a TTL, and retry 5xx/network failures with backoff.

### Offline entitlement token

`verify` and `activate` may return `entitlementToken`: a JWS (EdDSA / Ed25519) the app stores to prove the entitlement **offline**.

1. Fetch `GET /api/v1/licenses/.well-known/jwks.json` (public, cacheable). **Pin it in the build** and refresh only on an unknown `kid` — refreshing on every check would not be offline any more.
2. **Verify the signature before reading any claim.** A decoded-but-unverified `exp` is editable by the user.
3. Check `aud === your productId` (this is what proves "valid *for me*"). Grant while `now ≤ exp`; grant within grace while `now ≤ graceUntil`; otherwise revalidate online.

`exp ≤ iat + 72 h`; `graceUntil = exp + 72 h`, capped by the licence's own `expiresAt` — the grace covers a network outage, never an expired licence. A revoked key keeps working offline only until `exp`/`graceUntil` (at most ~6 days after issuance of the last token): that bound is what "rejected at the next heartbeat" means. Signing keys rotate every 90 days with an overlap window; clients that follow `kid` need no change.

### No client SDK for apps

`@itaja/licenses` is a **private, unpublished** package: do not install it or tell the merchant to. The server SDKs (`itaja-sdk`) wrap the management routes; an app that verifies keys calls the three public routes above directly (a thin HTTP client is enough) — it never embeds a server SDK, which holds the API key.

## Reseller and bulk batches

Mint keys **before** any sale (prepaid cards, resellers, campus bundles): `POST …/licenses/batch` with `{ count 1–500, activationsMax?, expiresAt?, label? }`.

- Gated by the product flag `allowResellerBatches`, **off by default** (`403 RESELLER_BATCHES_DISABLED`). It is the merchant's decision to turn on (`PUT …/license-batches/settings`).
- The clear keys come back **once**, in that one response. Persist them to the merchant's secure store immediately; no read route returns them again.
- Batch keys default to `activationsMax: 1`. Limits: 10 creations/minute/account plus a per-account quota (`429`).
- `void` revokes every **unclaimed** key and leaves claimed ones alone. A second void is `409 BATCH_ALREADY_VOID`. A batch key never rotates (`409 BATCH_KEY_NOT_ROTATABLE`).

## Delivering the key to the merchant's own site

Opt-in per product, off by default (`GET`/`PUT …/licenses/merchant-redirect`): when the product's pay-page success URL (set in the dashboard) carries `[license_key]`, Itaja substitutes a **single-use retrieval token** (`lkr_…`, 15-minute TTL), never the key. The merchant's **server** exchanges it once:

```http
POST /api/v1/licenses/retrieval/redeem      (API key required)
{ "token": "lkr_…" }
```

Failures are explicit: second use `409 LICENSE_RETRIEVAL_ALREADY_REDEEMED`, expired `410`, another account's key `403`, revoked with no successor `409`. **The clear key never appears in a URL**; do not "simplify" this into putting it in a query string, and do not call `redeem` from the browser.

## Webhooks

Nine topics: `license.issued`, `.activated`, `.deactivated`, `.revoked`, `.expired`, `.rotated`, `.suspended`, `.resumed`, `.updated`. Keys arrive masked, fingerprints only as `deviceFingerprintHash`. See `itaja-webhooks`.

## Done when

- [ ] The app holds no API key and always sends `productId`.
- [ ] Offline checks verify the Ed25519 signature against a pinned JWKS and check `aud`.
- [ ] Clear keys are stored once at issuance or never; logs only carry masked keys.
- [ ] Recovery uses `resend`/`rotate`, never a parallel store of clear keys.
- [ ] Batch creation is only used when the merchant enabled it.
