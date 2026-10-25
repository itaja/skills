---
name: itaja-adapter
description: Builds a small, tested Itaja adapter that wraps the developer API when no official SDK exists for the repository's language or framework (Go, Ruby, Java/Kotlin, C#, Rust, Elixir, Dart server, plain PHP without Laravel…). Defines the contract the adapter must honour — auth header, Idempotency-Key, retries, typed errors, webhook signature verification — and how to prove it against Itaja's published conformance vectors. Use after itaja-sdk finds no official package for the stack.
license: MIT
metadata:
  author: itaja
  version: "2.0"
---

# Building an Itaja adapter

Read `itaja-integration` first, then `itaja-sdk`: if an official package fits the stack, **use it — do not write an adapter**. This skill is for the other stacks.

An adapter is one small module **inside the merchant's repository**, server-side only, with no dependency beyond the stack's standard HTTP client, HMAC and JSON. It wraps only the routes the integration uses. It is not published as a package.

## The contract (every adapter, every language)

**Transport**

1. Base URL from `ITAJA_API_URL`, default `https://api.getitaja.com/api/v1`; key from `ITAJA_API_KEY`. Refuse to start when the key does not start with `itjs_` or `itjp_`. Expose the mode (`itjs_` = sandbox).
2. Header `x-itj-key: <key>`; JSON bodies; no redirect following.
3. **Every write** (`POST`, `PUT`, `PATCH`, `DELETE`) sends `Idempotency-Key`. Generate one per call when the caller gives none, and **reuse the same value on each retry of that call**. Let the caller pass its own (an order id).
4. Retry at most twice, honouring `Retry-After`, on: network error, `429`, `502`, `503`, `409` with `code: IDEMPOTENCY_KEY_IN_PROGRESS`. Never on another status.
5. Unwrap the envelope: return `data` when `success: true`; otherwise raise a typed error carrying `code`, HTTP `status`, `details` and `retryAfter`. A body that is not JSON (the plain-text `401`) becomes code `HTTP_<status>`.
6. Never put the key, the secret or a request body in an error message or a log line.

**Webhooks**

7. Verify on the **raw body**: `HMAC-SHA256(secret as issued, "<x-itaja-timestamp>.<raw body>")`, base64, compared **constant-time** against **every** signature after the `v1,` prefix of `x-itaja-signature`; refuse a timestamp more than 300 s from now. On failure answer `400` and do nothing.
8. Hand the decoded event to the merchant's code only after verification, deduplicated on `id` (see `itaja-webhooks` for the handler order).

**Typed surface**

9. One function per route actually used, named after the job (`createCheckout`, `subscribe`, `consumeCredits`, `verifyLicense`…), plus a generic `request(method, path, body?, query?, idempotencyKey?)` escape hatch. Take request and response shapes from the OpenAPI document — generate types from it when the stack has a generator, otherwise write only the fields used.
10. Products, plans and packs: if the merchant declares a catalogue, push it as a JSON document with `PUT /catalog` from a script or CI step (`itaja-catalog`); the adapter does not need a DSL.

## Reference implementation

`assets/itaja-adapter.php` is a complete adapter in plain PHP 8.1+ (curl, json, no framework): client with retries and idempotency, typed `ItajaError`, and `ItajaWebhook::verify`. It passes the conformance checks below. Translate it to the target language rather than designing from scratch; keep the same behaviour, idioms of the target stack.

Equivalents to reach for:

| Stack | HTTP | HMAC + constant-time compare |
|---|---|---|
| Go | `net/http` | `crypto/hmac`, `hmac.Equal` |
| Ruby | `Net::HTTP` | `OpenSSL::HMAC`, `Rack::Utils.secure_compare` / `OpenSSL.fixed_length_secure_compare` |
| Java / Kotlin | `java.net.http.HttpClient` | `javax.crypto.Mac`, `MessageDigest.isEqual` |
| C# | `HttpClient` | `HMACSHA256`, `CryptographicOperations.FixedTimeEquals` |
| Rust | `reqwest` | `hmac` + `sha2`, `Mac::verify_slice` |
| Elixir | `Req` / `Finch` | `:crypto.mac`, `Plug.Crypto.secure_compare` |
| Dart (server) | `package:http` | `package:crypto` `Hmac`, constant-time loop |
| PHP | `curl` | `hash_hmac`, `hash_equals` |

## Conformance — prove it before using it

The contract files are public in `https://github.com/itaja/itaja-sdks/tree/main/spec`:

| File | Use |
|---|---|
| `signature-vectors.json` | `{ secret, vectors: [{ name, timestamp, body, signature }] }`, signed by Itaja's backend |
| `openapi.json` | request and response shapes |
| `catalog.schema.json` | the `PUT /catalog` document |

Write these tests in the merchant's test suite (raw URL: `https://raw.githubusercontent.com/itaja/itaja-sdks/main/spec/signature-vectors.json`, or a copy committed under the tests):

1. Every vector verifies (inject "now" = vector timestamp).
2. Each vector **fails** when the body gains one byte, with another secret, with the `whsec_` prefix stripped, 400 s later, and with a `v2,` prefix.
3. A rotated header `v1,<garbage>,<real>` verifies.
4. Against a local stub server: a `503` then `200` returns the data in two calls carrying the **same** `Idempotency-Key`; a `422` raises its `code` and `details`; a plain-text `401` raises once, without retry, without the key in the message.
5. A sandbox smoke test with a real `itjs_` key against a read route (`GET /products`) — only if the merchant provided the key in the environment.

The adapter is done when all of them pass in CI.

## Do not

- Do not wrap routes the integration does not use, nor invent routes absent from the OpenAPI document.
- Do not ship the adapter to a browser, a mobile app or a desktop app: those call the merchant's server (or, for licences, only the public `verify` / `activate` / `deactivate` routes — `itaja-licenses`).
- Do not install an unofficial "itaja" package from a registry; only the packages in `itaja-sdk` are Itaja's.

## Done when

- [ ] `itaja-sdk` was checked first and no official package fits.
- [ ] The adapter honours points 1–10 and lives in one module of the merchant's server code.
- [ ] The conformance tests pass in CI.
- [ ] Key and secret come from the environment; `.env.example` lists `ITAJA_API_KEY`, `ITAJA_WEBHOOK_SECRET`, `ITAJA_API_URL`.
