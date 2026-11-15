---
name: itaja-webhooks
description: Receives and trusts Itaja outbound webhooks (checkout.paid, invoice.*, subscription.past_due, subscription.canceled, license.*, credits.*) — Standard Webhooks signature verification on the raw body, replay windows, deduplication, sandbox vs live, and fulfilling an order exactly once. Use when a merchant's server must react to Itaja payment or lifecycle events.
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

Itaja signs every delivery with [Standard Webhooks](https://www.standardwebhooks.com). It is the only scheme. Every delivery carries:

- `webhook-id` — the unique id of the message. A redelivery of the same message keeps the same id: it is the deduplication key.
- `webhook-timestamp` — unix seconds.
- `webhook-signature` — one or more entries separated by a space, each `v1,<base64>`. For 24 hours after a secret rotation there are two entries (new secret, previous secret). One match is enough.
- `webhook-topic` — the event type. Informational and not signed: route on the `type` of the verified body.

How the signature is built:

- Signed content: `<webhook-id>.<webhook-timestamp>.<raw body>`. The body is the bytes received, never a re-serialised object.
- Key: strip `whsec_` from the secret, then **base64-decode** the rest. The key is those bytes.
- Signature: `HMAC-SHA256(key, signed content)`, base64.

An older secret made of `whsec_` and 48 hexadecimal characters stays valid without a rotation. Decode it as base64 like any other secret, never as hex.

**Prefer a library.** The official SDKs verify it (`itaja-sdk`), and so does any Standard Webhooks library (`standardwebhooks` on npm and PyPI, and the ports listed on standardwebhooks.com):

```ts
import { Webhook } from "standardwebhooks";

const wh = new Webhook(process.env.ITAJA_WEBHOOK_SECRET!);
// rawBody: the request body as received. headers: the three webhook-* headers.
const event = wh.verify(rawBody, headers); // throws when the delivery is not authentic
```

```python
from standardwebhooks import Webhook

event = Webhook(os.environ["ITAJA_WEBHOOK_SECRET"]).verify(raw_body, headers)  # raises on failure
```

Without a library, this is the whole check:

```ts
import { createHmac, timingSafeEqual } from "node:crypto";

export function verifyItajaWebhook(
  rawBody: string | Uint8Array,
  headers: { id: string | null; timestamp: string | null; signature: string | null },
  secret: string,
  toleranceSeconds = 300,
  nowSeconds = Date.now() / 1000,
): boolean {
  const { id, timestamp, signature } = headers;
  if (!id || !timestamp || !signature) return false;
  if (!/^\d+$/.test(timestamp)) return false;
  if (Math.abs(nowSeconds - Number(timestamp)) > toleranceSeconds) return false;

  const key = Buffer.from(secret.startsWith("whsec_") ? secret.slice(6) : secret, "base64");
  if (key.length === 0) return false;
  const expected = createHmac("sha256", key)
    .update(`${id}.${timestamp}.`, "utf8")
    .update(rawBody)
    .digest();

  return signature.split(" ").some((entry) => {
    const [version, value] = entry.split(",");
    if (version !== "v1" || !value) return false;
    const given = Buffer.from(value, "base64");
    return given.length === expected.length && timingSafeEqual(given, expected);
  });
}
```

Call it with `headers = { id: req.headers["webhook-id"], timestamp: req.headers["webhook-timestamp"], signature: req.headers["webhook-signature"] }`.

Rules that bite:

1. **Verify the raw bytes.** Body parsers (`express.json()`, framework JSON middleware) change them. Read the raw body on this route.
2. **Decode the key.** Strip `whsec_`, base64-decode the rest. Signing with the secret string itself fails.
3. **Sign all three parts**: id, timestamp and body, joined by dots.
4. **Constant-time compare** (`timingSafeEqual`), never `===`.
5. **Enforce the timestamp window**: refuse a timestamp more than 5 minutes from now.
6. **Try every entry** of `webhook-signature`, split on spaces, not only the first.
7. **Reject any failure with `400`** and process nothing. Never log the secret or the signature header.

### Test the verifier

Check any implementation against this known answer (a test secret, not a real one):

| Input | Value |
|---|---|
| secret | `whsec_aXRhamEta2F0` |
| `webhook-id` | `msg_test_1` |
| `webhook-timestamp` | `1760500000` |
| raw body | `{"id":"itaja_evt_test_1","type":"endpoint.ping","apiVersion":"v1","mode":"sandbox","createdAt":"2026-10-15T04:26:40.000Z","data":{}}` |
| `webhook-signature` | `v1,bvxByHGU9qj8jGhHh6KMKcMrVGS+OQZg8M7pF+Xdegk=` |

Tests to write, with "now" injected as the vector's timestamp:

1. The known answer verifies.
2. It fails when the body gains one byte, when `webhook-id` changes, with another secret, with the secret used as a raw string, 301 seconds later, and with a `v2,` entry.
3. A rotated header `v1,<other> v1,<real>` verifies.
4. A missing header fails.

To send a signed delivery to a local handler, sign with the same recipe (`new Webhook(secret).sign(id, new Date(), body)` in `standardwebhooks`) or use the SDK's `itaja trigger` command (`itaja-sdk`).

Migrating from the previous scheme: the headers `x-itaja-signature` and `x-itaja-timestamp` are no longer sent. A handler that reads them must move to the check above.

## Handling an event

```json
{ "id": "itaja_evt_…", "type": "checkout.paid", "apiVersion": "v1", "mode": "live",
  "createdAt": "…",
  "data": { "checkoutId": "…", "productId": null, "transactionId": "…",
            "amount": 5500, "currency": "XOF", "paidAt": "…" } }
```

In the handler, in this order:

1. Verify the signature. Fail → `400`.
2. **Deduplicate on `webhook-id`.** Insert the header's value under a unique constraint *in the same transaction* as the fulfilment. A duplicate answers `200` and does nothing. Delivery is at-least-once and unordered.
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

At-least-once, unordered, redelivered on a non-`2xx` answer. The retry schedule and the source IPs are not documented: rely on the signature (no IP allowlist), on `webhook-id` for deduplication, and on reconciliation from the API (`GET /subscriptions/:id`, a licence, a wallet) if an event seems missing.

## Done when

- [ ] Deliveries are verified as Standard Webhooks: raw body, base64-decoded key, constant-time, every entry of `webhook-signature`, 5-minute window.
- [ ] The verifier passes the known-answer tests above.
- [ ] `webhook-id` is deduplicated atomically with the fulfilment.
- [ ] Production ignores sandbox events (`mode`, or the product id where `mode` is absent).
- [ ] Orders are matched by the stored checkout id and the amount is cross-checked.
- [ ] No handler waits on a topic that is not emitted.
- [ ] The secret lives in a secret manager, one per environment.
