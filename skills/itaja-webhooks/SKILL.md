---
name: itaja-webhooks
description: Use when a merchant must receive and trust Itaja events (checkout.paid, license.*, credits.*) — signature verification, replay and duplicate handling, and fulfilling an order only on live, verified payments.
---

# Itaja outbound webhooks

The signed webhook is the **only proof of payment**. Read `itaja-integration` first.

## Registering the endpoint (a human step)

Endpoints are created in the merchant **dashboard**, not through the API key.

- The URL must be public `https`: no cleartext, no loopback, private or link-local address (`INVALID_URL` otherwise). A local server needs a tunnel.
- The signing secret (`whsec_…`) is shown **once**, at creation. Have the merchant store it as `ITAJA_WEBHOOK_SECRET` in a secret manager. If it is lost or leaked, create a new endpoint / rotate — do not look for a way to read it back.
- Use one endpoint per environment, each with its own secret.

## Verifying a delivery

Every delivery carries:

- `x-itaja-timestamp` — unix seconds.
- `x-itaja-signature` — `v1,<base64>`. During a secret rotation it carries one signature per valid secret: `v1,<current>,<previous>`.

The signature is `HMAC-SHA256(key = the raw secret string, message = "<timestamp>.<raw body>")`, base64-encoded. The key is the secret **as given**, `whsec_` prefix included — not decoded.

```ts
import { createHmac, timingSafeEqual } from "node:crypto";

export function verifyItajaSignature(
  rawBody: string,
  signatureHeader: string | null,
  timestampHeader: string | null,
  secret: string,
  toleranceSeconds = 300,
): boolean {
  if (!signatureHeader || !timestampHeader) return false;
  const ts = Number(timestampHeader);
  if (!Number.isInteger(ts)) return false;
  if (Math.abs(Date.now() / 1000 - ts) > toleranceSeconds) return false;

  const expected = createHmac("sha256", secret)
    .update(`${timestampHeader}.${rawBody}`)
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

1. **Verify the raw bytes.** Frameworks that parse JSON first (`express.json()`, body parsers) change the bytes. Capture the raw body for this route.
2. **Constant-time compare** (`timingSafeEqual`), never `===`.
3. **Check the timestamp window.** The signature covers the timestamp, so a replay is detectable — enforcing the window is the receiver's job.
4. **Reject on any failure with `400`** and process nothing. Do not log the secret or the full signature header.

## Handling an event

Envelope:

```json
{ "id": "itaja_evt_…", "type": "checkout.paid", "apiVersion": "v1", "mode": "live",
  "createdAt": "…",
  "data": { "checkoutId": "…", "productId": null, "transactionId": "…",
            "amount": 5400, "currency": "XOF", "paidAt": "…" } }
```

Order of operations in the handler:

1. Verify the signature (above). Fail → `400`.
2. **Deduplicate on `id`.** Insert the event id in a table with a unique constraint *in the same transaction* as the fulfilment. A duplicate returns `200` and does nothing. Design for at-least-once and out-of-order delivery.
3. **Check `mode`.** In production, fulfil only `"live"`. A `"sandbox"` event gets a `200` and a log line, nothing else. (The dashboard's ping and sample deliveries are always `"sandbox"`.)
4. **Correlate.** Find your order by `data.checkoutId` — the `data.id` you stored when you created the session (`itaja-checkout-session`). Unknown id → `200`, log, alert; do not invent an order.
5. **Cross-check** `amount` and `currency` against the `total` and `currency` you stored. A mismatch is an incident, not a fulfilment.
6. Mark paid / deliver, then answer `2xx` quickly. Do slow work (e-mails, provisioning) asynchronously.

`productId` is `null` for a dynamic checkout — it has no product.

## Other event families

All share the envelope; `data` always carries `productId`. Fields beyond that depend on the topic — read the OpenAPI document / API reference rather than guessing.

**Delivered today** (the platform really emits these):

| Family | Topics |
|---|---|
| Payment | `checkout.paid` |
| Licences | `license.issued`, `.activated`, `.deactivated`, `.revoked`, `.expired`, `.rotated`, `.suspended`, `.resumed`, `.updated` — keys arrive masked |
| Credits | `credits.granted`, `.consumed`, `.expired`, `.low`, `.clawed_back` — amounts are **coin counts**, not money |

**Listed in the dashboard's event picker but not emitted yet:** `payment.*` (`success`, `failed`, `refunded`, `pending`), `subscription.*` (`created`, `updated`, `cancelled`, `renewed`, `payment_failed`) and `client.*` (`created`, `updated`, `deleted`). A subscription to one of them is accepted and **never fires**. Do not build a flow that waits for them: today there is no generic payment-refund, subscription-renewal or failed-payment event (a confirmed refund does surface indirectly — a licence is revoked, so `license.revoked`; a credit pack's coins are taken back, so `credits.clawed_back`). If the integration needs a signal that does not exist, say so to the merchant instead of wiring a handler that stays silent.

Subscribe the endpoint only to the topics the integration handles. Ignore unknown `type`s with a `200` so new topics never break a deployed handler. `endpoint.ping` is the dashboard's test delivery: it is signed like the others and always carries `mode: "sandbox"`.

## Delivery guarantees

Deliveries are published through a hosted webhook transport. The retry schedule is **not documented** to integrators: design for at-least-once delivery, answer fast, and rely on the event id for deduplication rather than on any assumed retry count. If an event was missed, reconcile from the API (a customer's invoices or status, a licence's state) rather than waiting for a redelivery.

## Done when

- [ ] Raw body is verified with a constant-time compare and a timestamp window.
- [ ] Event ids are deduplicated atomically with the fulfilment.
- [ ] Production ignores `mode: "sandbox"`.
- [ ] Orders are matched by the stored checkout `id`, and the amount is cross-checked.
- [ ] The secret lives in a secret manager, one per environment.
