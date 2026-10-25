---
name: itaja-sdk
description: Picks the right official Itaja package for the repository's language and framework — @itaja/node (npm), itaja (PyPI), itaja/itaja-laravel (Packagist) — and uses it — installation with the repo's own package manager, configuration, catalogue push, the one-line webhook route and the raw request escape hatch. Use before writing any Itaja code, to decide between an official SDK and a hand-built adapter (itaja-adapter).
license: MIT
metadata:
  author: itaja
  version: "2.0"
---

# Itaja official SDKs

Read `itaja-integration` first. Prefer an SDK over hand-written HTTP when the stack has one: it sets the key header, an `Idempotency-Key` on every write, retries, typed errors, and a webhook route that verifies signatures, deduplicates and ignores the other mode.

## Choose the package from the repository, not from habit

Inspect the server code's manifest **before** writing anything, and pick the first row that matches:

| Found in the repository | Package | Install with the repo's own tool |
|---|---|---|
| `composer.json` requiring `laravel/framework` 12 or 13, PHP ≥ 8.2 | `itaja/itaja-laravel` | `composer require itaja/itaja-laravel` |
| `pyproject.toml`, `requirements*.txt`, `Pipfile` or `setup.py`, Python ≥ 3.10 (Django, FastAPI, Starlette, Flask or none) | `itaja` | `uv add itaja` · `poetry add itaja` · `pip install itaja` (+ extra `[django]`, `[fastapi]`, `[flask]`) |
| `package.json` of a server runtime — Node ≥ 20, Bun, Deno, Cloudflare Workers (Next.js route handlers, Express, Hono, Elysia, Nest…) | `@itaja/node` | the repo's lockfile decides: `pnpm add` · `yarn add` · `bun add` · `npm i` `@itaja/node` |
| anything else — Go, Ruby/Rails, Java/Kotlin, C#, Rust, Elixir, Dart server, PHP without Laravel or below 8.2, Laravel < 12, Python < 3.10, Node < 20 | none | **build an adapter: load `itaja-adapter`** |

Rules for the choice:

- **Look at the code that will hold the key**, not the whole monorepo. A Next.js front end with a Go API → the Go service needs an adapter; a React SPA with a FastAPI back end → `itaja` in the back end, nothing in the SPA.
- **Respect the declared version constraints** (`engines`, `requires-python`, `php` and `laravel/framework` in `composer.json`). A version below the package's floor means the adapter path, not a forced upgrade — propose the upgrade to the merchant instead of doing it silently.
- **Client-only code gets no package**: browser, React Native, Flutter, Electron renderer, desktop apps. They call the merchant's server; licence checks use the public routes only (`itaja-licenses`).
- Several server languages in one repo → one package (or adapter) per service that talks to Itaja; never share a key across services that do not need it.
- **Only these three packages are Itaja's.** Never install another registry package named "itaja…" (`@itaja/licenses` is private and unpublished).
- State the choice and its reason to the merchant in one line ("FastAPI on Python 3.12 → `itaja[fastapi]`").

| Package | Registry | Source |
|---|---|---|
| `@itaja/node` | npm | `github.com/itaja/itaja-sdks` → `packages/node` |
| `itaja` | PyPI | `github.com/itaja/itaja-sdks` → `packages/python` |
| `itaja/itaja-laravel` | Packagist | `github.com/itaja/itaja-sdks` → `packages/laravel` |

**Server only.** Every SDK holds the secret API key. Never import it in browser, React Native or Flutter code.

## Configuration

All three read the same variables, from the environment or a git-ignored `.env`:

```bash
ITAJA_API_KEY=itjs_…              # sandbox; itjp_… is live
ITAJA_WEBHOOK_SECRET=whsec_…      # signing secret of the webhook endpoint (dashboard)
ITAJA_API_URL=https://api.getitaja.com/api/v1   # optional, this is the default
```

There is no environment option: the key's prefix decides sandbox or live. Commit an `.env.example` with the names only.

## The four gestures

1. **Declare** what is sold in code — features, plans, credit packs, licences — in one config file (`itaja.config.ts`, `itaja_config.py`, `itaja.php`). Rules of the catalogue: `itaja-catalog`.
2. **Push** it: `npx itaja push`, `itaja push`, `php artisan itaja:push`. Shows the diff and asks; `itaja status` exits 1 when code and Itaja differ. In CI: `--yes` (Node, Python) / `--force` (Laravel).
3. **Mount** one webhook route:

   ```ts
   // Next.js app/itaja/webhook/route.ts
   export const POST = itaja.handler;
   // Hono, Bun, Elysia, Workers
   app.post("/itaja/webhook", (c) => itaja.handler(c.req.raw));
   // Express: raw body on this path
   app.post("/itaja/webhook", express.raw({ type: "*/*" }), itaja.nodeHandler);
   ```

   Python: `webhook_view(itaja)` (Django), `webhook_router(itaja)` (FastAPI), `webhook_blueprint(itaja)` (Flask). Laravel: `POST /itaja/webhook` is mounted on install.
4. **React** to typed events: `on: { "checkout.paid": … }` (Node, Python) or Laravel event listeners (`Itaja\Laravel\Events\CheckoutPaid`). Test locally with a signed sample: `npx itaja trigger checkout.paid --url http://localhost:3000/itaja/webhook` (Laravel: `php artisan itaja:trigger checkout.paid`).

The handler answers `500` when the merchant's code throws, so Itaja redelivers. Behind several instances, give it a **shared** deduplication store (Redis); the default is in memory.

## Calls the SDKs wrap

| Need | Node | Skill with the rules |
|---|---|---|
| One-off cart | `itaja.checkout.create({ homeUrl, currency, lineItems, … })` | `itaja-checkout-session` |
| Credit pack sale | `itaja.credits.buy({ product, pack, customerId, homeUrl })` | `itaja-credits` |
| Spend / read coins | `itaja.credits.consume(…)`, `.balance(…)`, `.walletToken(…)` | `itaja-credits` |
| Licences | `itaja.licenses.issue(…)`, `.revoke(…)`, `.rotate(…)`, … | `itaja-licenses` |
| Subscriptions | `itaja.subscriptions.list/get/cancel/pause/resume` | `itaja-subscriptions` |
| Customers | `itaja.customers.create/get/update` — the merchant's own id works | `itaja-customers` |

Products, packs and plans are referenced **by their declared name** (`product: "AI tokens"`), never by an id. Python and Laravel expose the same calls in their idiom (`snake_case`, facades).

## The escape hatch — and `subscribe`

`itaja.request(method, path, { query, body, idempotencyKey })` (Python `itaja.request(...)`, Laravel `Itaja::request(...)`) calls any developer-API route with the envelope unwrapped and errors typed.

In SDK **0.3.0**, the `subscribe()` helper and `check()` / `report()` (entitlements) throw `NOT_YET_AVAILABLE`. `POST /subscriptions/subscribe` **is** served by the API: until a newer SDK wraps it, call it through `request`:

```ts
const result = await itaja.request("POST", "/subscriptions/subscribe", {
  body: { customerId: user.id, plan: "Pro", successUrl, cancelUrl },
  idempotencyKey: `subscribe-${user.id}-pro`,
});
```

Feature entitlements (`check`, `report`) are **not** served by the API: plans store their `includes`, but nothing enforces them yet. Do not build access control on them; see `itaja-subscriptions`.

## Errors

Every failure is a typed error carrying the stable `code`, the HTTP `status` and `details` (`ItajaValidationError`, `ItajaRateLimitError`, `ItajaSignatureError`, …). No error message contains the key. Writes and `429`/`5xx` are retried twice by the SDK with the same `Idempotency-Key`; pass your own `idempotencyKey` (an order id) to make **your** retries safe too.

## Done when

- [ ] The SDK is imported only in server code; the key comes from the environment.
- [ ] The catalogue is pushed from CI or a deliberate command, sandbox first.
- [ ] The webhook route is mounted with the raw body and a shared dedupe store when scaled out.
- [ ] Nothing calls a helper that throws `NOT_YET_AVAILABLE`.
