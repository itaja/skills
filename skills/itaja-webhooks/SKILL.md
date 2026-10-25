---
name: itaja-webhooks
description: Receives and trusts Itaja outbound webhooks (checkout.paid, invoice.*, subscription.past_due, subscription.canceled, license.*, credits.*) — signature verification on the raw body, replay windows, deduplication, sandbox vs live, and fulfilling an order exactly once. Use when a merchant's server must react to Itaja payment or lifecycle events.
license: MIT
metadata:
  author: itaja
  version: "1.0.0" # x-release-please-version
---

# Itaja outbound webhooks

The signed webhook is the **only proof of payment**. Read `itaja-integration` first. With an official SDK the handler below is one line (`itaja-sdk`); this skill is what that line does. Without an official package for the stack, build it as part of the adapter (`itaja-adapter`) and prove it against Itaja's published signature vectors.

## Registering the endpoint (a human step)

Endpoints are created in the merchant **dashboard**, not with the API key.

- The URL must be public `https`: no cleartext, loopback, private or link-local address. A local server needs an `https` tunnel.
- The signing secret (`whsec_…`) is shown **once**, at creation. The merchant stores it as `ITAJA_WEBHOOK_SECRET` in a secret manager. Lost or leaked: rotate or create a new endpoint — it cannot be read back.
- One endpoint and one secret per environment.
- Subscribe only to the topics the integration handles.

## Verifying a delivery

Every delivery carries:

- `x-itaja-timestamp` — unix seconds.
- `x-itaja-signature` — `v1,<base64>`. During a secret rotation it carries one signature per valid secret: `v1,<current>,<previous>`.

The signature is `HMAC-SHA256(key = the secret string as issued, message = "<timestamp>.<raw body>")`, base64. The key keeps its `whsec_` prefix and is not decoded. This is **not** the Standard Webhooks scheme: a Standard Webhooks library will not verify it.

```ts
import { createHmac, timingSafeEqual } from "node:crypto";

export function verifyItajaSignature(
  rawBody: string,
  signatureHeader: string | null,
  timestampHeader: string | null,
  secret: string,
  toleranceSeconds = 300,
  nowSeconds = Date.now() / 1000,
): boolean {
  if (!signatureHeader || !timestampHeader) return false;
  const ts = Number(timestampHeader);
  if (!Number.isInteger(ts)) return false;
  if (Math.abs(nowSeconds - ts) > toleranceSeconds) return false;

  const expected = createHmac("sha256", secret)
    .update(`${timestampHeader}.${rawBody}`, "utf8")
    .digest();

  const [version, ...candidates] = signatureHeader.split(",");
  if (version !== "v1") return false;
  return candidates.some((c) => {
    const given = Buffer.from(c, "base64");
    return given.length === expected.length && timingSafeEqual(given, expected);
  });
}
```

Rules that bite:

1. **Verify the raw bytes.** Body parsers (`express.json()`, framework JSON middleware) change them. Read the raw body on this route.
2. **Constant-time compare** (`timingSafeEqual`), never `===`.
3. **Enforce the timestamp window.** The signature covers the timestamp; rejecting old ones is the receiver's job.
4. **Compare against every signature** in the header, not only the first.
5. **Reject any failure with `400`** and process nothing. Never log the secret or the signature header.

## Handling an event

```json
{ "id": "itaja_evt_…", "type": "checkout.paid", "apiVersion": "v1", "mode": "live",
  "createdAt": "…",
  "data": { "checkoutId": "…", "productId": null, "transactionId": "…",
            "amount": 5500, "currency": "XOF", "paidAt": "…" } }
```

In the handler, in this order:

1. Verify the signature. Fail → `400`.
2. **Deduplicate on `id`.** Insert the event id under a unique constraint *in the same transaction* as the fulfilment. A duplicate answers `200` and does nothing. Delivery is at-least-once and unordered.
3. **Check the environment.** `checkout.paid`, `invoice.*` and `subscription.*` carry `mode`: production fulfils only `"live"`, and answers `200` + a log line to `"sandbox"`. `license.*` and `credits.*` envelopes carry **no** `mode`: tell the environment by `data.productId`, which is different for sandbox and live products.
4. **Correlate.** For `checkout.paid`, find the order by `data.checkoutId` — the `data.id` stored at checkout creation (`itaja-checkout-session`). Unknown → `200`, log, alert; never invent an order.
5. **Cross-check** `amount` and `currency` against the stored `total` and `currency`. A mismatch is an incident, not a fulfilment.
6. Fulfil, then answer `2xx` fast; do slow work asynchronously. If the fulfilment itself fails, answer `5xx`: a non-`2xx` answer is redelivered.

`data.productId` is `null` on a dynamic checkout (no product). `data.amount` on `checkout.paid` and `invoice.*` is money in major units.

## Topics actually delivered

| Family | Topics | Notes |
|---|---|---|
| Payment | `checkout.paid` | every paid checkout: dynamic, credit pack, subscription period |
| Subscription billing | `invoice.paid`, `invoice.payment_failed`, `subscription.past_due`, `subscription.canceled` | see `itaja-subscriptions` |
| Licences | `license.issued`, `.activated`, `.deactivated`, `.revoked`, `.expired`, `.rotated`, `.suspended`, `.resumed`, `.updated` | keys arrive **masked**; devices only as `deviceFingerprintHash` |
| Credits | `credits.granted`, `.consumed`, `.expired`, `.low`, `.clawed_back` | amounts are **coin counts**, never money |

`data` always carries `productId`. Other fields depend on the topic: read the OpenAPI document or the topic's skill rather than guessing. `subscription.canceled` is spelled with **one l**.

**Listed in the dashboard picker but never emitted:** `payment.*`, `client.*`, `subscription.created`, `.updated`, `.renewed`, `.payment_failed`. A subscription to them is accepted and never fires. Do not wire a handler that waits for them; there is no generic payment-refund event (a refund surfaces as `license.revoked` or `credits.clawed_back`). If the integration needs a signal that does not exist, tell the merchant.

Ignore unknown `type`s with a `200`, so a new topic never breaks a deployed handler. `endpoint.ping` is the dashboard's test delivery: signed like the others, always `mode: "sandbox"`.

## Delivery guarantees

At-least-once, unordered, redelivered on a non-`2xx` answer. The retry schedule and the source IPs are not documented: rely on the signature (no IP allowlist), on the event id for deduplication, and on reconciliation from the API (`GET /subscriptions/:id`, a licence, a wallet) if an event seems missing.

## Done when

- [ ] The raw body is verified, constant-time, every signature of the header, with a timestamp window.
- [ ] Event ids are deduplicated atomically with the fulfilment.
- [ ] Production ignores sandbox events (`mode`, or the product id where `mode` is absent).
- [ ] Orders are matched by the stored checkout id and the amount is cross-checked.
- [ ] No handler waits on a topic that is not emitted.
- [ ] The secret lives in a secret manager, one per environment.
