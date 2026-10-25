# Itaja integration — FAQ

Answers for the questions an agent (or a merchant's developer) actually hits. Each answer says which skill holds the detail. Where something is **not documented**, the answer says so instead of guessing; the generated OpenAPI document (`https://api.getitaja.com/api/references/json`) wins over this page.

**Sections:** [Getting started](#getting-started) · [Keys and modes](#keys-and-modes) · [SDKs and catalogue](#sdks-and-catalogue) · [Checkout](#checkout) · [Webhooks](#webhooks) · [Products and customers](#products-and-customers) · [Credits](#credits) · [Licences](#licences) · [Subscriptions](#subscriptions) · [Errors and limits](#errors-and-limits) · [Going live and security](#going-live-and-security) · [For agents](#for-agents)

---

## Getting started

**Which skill do I load first?**
`itaja-integration`. It holds the base URL, the key rules, idempotency and the ten constraints, and its table routes you to the right skill for the job.

**What can the developer API do, and what is dashboard-only?**
The API pushes the catalogue (plans, packs, licences), creates products, customers and checkouts, moves customers between plans, manages subscriptions, licences and credit wallets, and quotes fees. The **dashboard** owns payment links, webhook endpoints, API keys, verified domains, KYC and brand colours. If you cannot find a route, check `docs/COVERAGE.md` before concluding the API is incomplete.

**What is the production API host?**
`https://api.getitaja.com/api/v1`. Keep it in `ITAJA_API_URL` (the SDKs default to it). If the host does not answer, stop and tell the merchant — never send a live key somewhere else.

**Is there an OpenAPI document?**
Yes: `https://api.getitaja.com/api/references` (UI) and `/api/references/json` (OpenAPI 3.1). It is generated from the code, so it is the authority when a skill disagrees.

**Is there an official SDK?**
Yes, for servers: `@itaja/node` (npm), `itaja` (PyPI) and `itaja/itaja-laravel` (Packagist). See `itaja-sdk`. Other stacks call the HTTP routes. `@itaja/licenses` is private and unpublished — never install it.

**I have no backend — can I still take payments?**
Two options. (1) The **payment link** of a product, created in the dashboard, can simply be shared; no code. (2) For checkout from your own app you need a server component (a serverless function is fine), because the API key must never reach a browser.

**Can an agent build the whole integration unattended?**
Up to sandbox, yes. Everything that moves real money or needs the account to be approved (live key, live product, first real payment) stays a human decision — see `itaja-go-live`.

**What do I need from the merchant before starting?**
A sandbox key (`itjs_…`) set in the environment, the public URL of their site (for return URLs — in live it must be a verified domain), and a public `https` endpoint for webhooks. Ask for them; never ask for a key to be pasted into chat.

---

## Keys and modes

**How does Itaja tell sandbox from live?**
By the key. `itjs_` keys are sandbox (no real charge); `itjp_` keys are live. Products, checkouts and webhook events each carry their mode, and a key only acts on products of its own mode (`422 MODE_MISMATCH` otherwise).

**Where do I get a sandbox key?**
From the merchant's onboarding / dashboard. An agent cannot mint one with an API key.

**What is the difference between an account-scoped and a product-scoped key?**
An account-scoped key reaches every product of the account. A product-scoped key reaches only its own product (`403 PRODUCT_SCOPE_VIOLATION` otherwise). Creating products, pushing the catalogue and dynamic checkout sessions need an account-scoped key; the `/customers` routes need a product-scoped one.

**Which header carries the key?**
`x-itj-key`. `x-api-key` is accepted as an alias; if both are present, `x-itj-key` wins.

**A key leaked. What now?**
Regenerate it in the dashboard; the old key stops working and a replacement is issued. Then purge the leak (history, logs, CI output). Code that depends on a specific key value is a bug: keys come from the environment.

**Can I use my sandbox product id in production?**
No. Sandbox and live products never share an id. With the catalogue, refer to products by **name** instead: the same name exists in both modes.

**Why does `GET /products` not list all my products?**
It lists the products of the **key's mode only**. Use a live key to see live products.

---

## SDKs and catalogue

**Which SDK for which stack?**
Read the server code's manifest. Node ≥ 20 / Bun / Deno / Workers: `@itaja/node`. Python ≥ 3.10 (Django, FastAPI, Flask): `itaja`. Laravel 12–13 on PHP ≥ 8.2: `itaja/itaja-laravel`. Anything else: an adapter (`itaja-adapter`). See `itaja-sdk`.

**My backend is in Go (Ruby, Java, C#, Rust…). What now?**
Build a small adapter in your repository following `itaja-adapter`: auth header, `Idempotency-Key` on writes, bounded retries, typed errors, webhook verification. Prove it with the published vectors (`https://github.com/itaja/itaja-sdks/tree/main/spec`). A plain-PHP reference implementation ships with the skill.

**My monorepo has a JavaScript front end and a Python API. Which package?**
The one for the service that holds the key — `itaja` in the API. The front end gets no package.

**Can the SDK run in the browser or a mobile app?**
No. It holds the secret key. Only the merchant's server imports it.

**How do I declare plans, packs and licences in code?**
In the SDK config file (or a JSON document), then push: `npx itaja push`, `itaja push`, `php artisan itaja:push`, or `PUT /api/v1/catalog`. Always dry-run first. See `itaja-catalog`.

**What happens if I rename a plan in code?**
It is a new plan: the old one is archived (its subscribers keep it) and `subscribe` with the old name answers `404 PLAN_NOT_FOUND`. A case-only change is not a rename.

**I changed a plan's price. Are subscribers moved?**
No. A new version of the plan is created for new subscriptions; running ones keep their version.

**Will a push delete products I made in the dashboard?**
No. A push only manages what it created. It never deletes: what you stop declaring is archived.

**Does Itaja enforce plan features and quotas?**
Not yet. `includes` is stored on the plan, but there is no check or metering endpoint, and the SDK helpers `check()` / `report()` throw `NOT_YET_AVAILABLE`. Your app enforces its own limits.

---

## Checkout

**How do I start a payment for an order?**
`POST /api/v1/checkout-sessions` from your server, then redirect the payer to `checkoutUrl`. Full details and an example: `itaja-checkout-session`.

**What `homeUrl` do I send?**
An absolute `https` URL, at most 2048 characters, no `user:pass@`, taken from a **server-side setting** — never from the request's `Host`, query string or body. In **live**, its host must be a domain the account declared and Itaja verified (`422 LIVE_HOST_NOT_ALLOWED`); `localhost` is exempt. Sandbox accepts any `https` host.

**Why is `homeUrl` so strict?**
It is the payer's return address. One built from user input is an open redirect that sends a buyer anywhere after paying. The check refuses unsafe schemes, credentials and over-long values before anything is written.

**Can I send the payer to different pages on success, failure and cancel?**
Yes: `successUrl`, `failureUrl`, `cancelUrl` (optional, same rules as `homeUrl`). Itaja appends `itaja_checkout_id` and `itaja_status`. Those parameters are forgeable: never fulfil from them.

**What is `LIVE_HOST_NOT_ALLOWED`?**
A live return URL is on a host the account has not verified. The merchant declares it in the dashboard (Settings → Domains) and waits for Itaja's review. The match is exact: each subdomain is declared separately.

**The buyer returned to my `homeUrl`. Is the order paid?**
Not necessarily — and you must not decide from the return. The return and any query parameters on it are visible to and editable by the payer. Mobile-money confirmations can even arrive *after* the payer returns. Mark an order paid only from the signed `checkout.paid` webhook (`itaja-webhooks`). The page can show "payment being confirmed" while you wait.

**How long does a checkout session live?**
15 minutes (`expiresAt`). For a new attempt, create a new session.

**What is `reference` for?**
It is the idempotency key, scoped to your account. Retrying a creation with the same `reference` returns the **same** checkout and session and calls the payment engine once. Derive it from your order id and persist it; a random value per attempt defeats it. If the order changes, use a new reference rather than expecting changed fields to be applied.

**What amount do I show the buyer?**
`data.total` from the response. `subtotal` is the merchant's sale, `platformFee` is Itaja's commission, `total` is what the payer is charged. Never recompute the fee: to show it before creating anything, call `POST /api/v1/quotes` (`itaja-checkout-session`).

**Which currencies are supported?**
XOF, XAF, EUR, USD, GBP, CAD, NGN, GHS and KES today. Prices are in major units: whole numbers for XOF/XAF, at most two decimals elsewhere. A sub-minor amount is refused, not rounded. Check the OpenAPI document before using anything else.

**Is there a minimum or maximum amount?**
Licence and credit-pack prices have bounds (e.g. 500–200,000 XOF; EUR 2.00–304.89); see `itaja-products`. Dynamic checkout line items are outside those bounds, but the merchant's fee mode can refuse a sale too small to cover the fee (`ABSORBED` merchants are refused rather than sold at a loss).

**Can I embed the checkout in an iframe?**
No. The payer leaves for the hosted checkout through a top-level navigation; the hosted checkout refuses framing. Itaja also has a pop-up widget; ask the team for its integration guide — these skills do not cover it.

**Which payment methods does the buyer see?**
Whatever the hosted checkout offers for the buyer's country. The integration does not choose them.

**Which languages are supported?**
`language` accepts `fr`, `en` and `es` today.

**Can I send `productId`, `amount` or `callbackUrl` with the session?**
No. These are unknown keys and are refused with `422`. A dynamic sale is described by `currency`, `lineItems` and the return URLs.

**Do I have to send the buyer's e-mail?**
Yes, the real one: `422 CUSTOMER_EMAIL_REQUIRED` otherwise. It is the buyer's identity and the delivery target for receipts, licence keys and invoices.

**What does `CUSTOMER_BANNED` mean?**
This merchant does not sell to this buyer. The message carries the merchant's reason, written for the buyer — show it as is. The ban is per merchant; the buyer can still buy elsewhere.

---

## Webhooks

**Where do I register the endpoint?**
In the merchant dashboard. The URL must be public `https` (no cleartext, loopback, private or link-local address). The signing secret is shown once.

**What if I never registered an endpoint?**
Nothing is published for that account. A missing event is usually a missing endpoint, not a delivery failure.

**How do I verify a delivery?**
HMAC-SHA256, base64, over `"<x-itaja-timestamp>.<raw body>"`, keyed with the raw secret string; compare in constant time; compare against **every** signature in the `v1,<a>,<b>` header (rotation windows carry several). A tested TypeScript implementation is in `itaja-webhooks`.

**Can I use a Standard Webhooks library?**
No. Itaja deliberately keeps its own scheme: headers `x-itaja-signature` and `x-itaja-timestamp`, signed content `<timestamp>.<body>`. The Standard Webhooks scheme (`webhook-id` / `webhook-timestamp` / `webhook-signature`, signed `<id>.<timestamp>.<body>`) will not verify.

**My signature check fails. What are the usual causes?**
(1) A body parser changed the bytes — verify the raw body. (2) The `whsec_` prefix was stripped or the secret decoded; the key is the string as issued. (3) The secret belongs to another endpoint or environment. (4) The timestamp window rejects a skewed clock. (5) Only the first signature in the header was compared during a rotation.

**Which events does Itaja really send?**
`checkout.paid`; `invoice.paid`, `invoice.payment_failed`, `subscription.past_due`, `subscription.canceled`; nine `license.*` and five `credits.*` topics. `payment.*`, `client.*`, `subscription.created`, `.updated`, `.renewed` and `.payment_failed` appear in the dashboard picker but are **never emitted**: subscribing is accepted and never fires.

**Does a sandbox checkout send webhooks?**
Yes. Payment and billing events carry `"mode": "sandbox"`; `license.*` and `credits.*` carry no `mode`, so tell them apart by product id. Production code logs and ignores sandbox events. The dashboard's test deliveries are always `sandbox`.

**Can I deduplicate on something other than the event id?**
The id (`itaja_evt_…`) is the identity — it is derived from the transaction, so a retried publish of the same payment reuses it. Insert it with a unique constraint in the same transaction as the fulfilment.

**What is the retry policy?**
A non-`2xx` answer is redelivered; the schedule is not documented. Design for at-least-once delivery, answer quickly, answer `5xx` only when you want a redelivery, and reconcile from the API if you suspect a miss.

**Do deliveries come from fixed IP addresses?**
Not documented. Do not rely on an IP allowlist; the signature is the control.

**Why did the webhook arrive before/after my redirect?**
Order is not guaranteed. Make the fulfilment independent of the return, and make the return page tolerant of "not confirmed yet".

**Can one endpoint serve sandbox and live?**
You can, but keep one endpoint and one secret per environment and check `mode` anyway.

**How do I test my handler?**
Use the dashboard's ping and sample deliveries (signed, always `sandbox`), and a real sandbox checkout. A local server needs an `https` tunnel.

---

## Products and customers

**Can I change a product's monetization mode?**
No — it is fixed at creation. A `PATCH` carrying it answers `200` but ignores it. Create a new product and archive the old one.

**What are the modes?**
On the API: `SUBSCRIPTION`, `CREDITS`, `LICENSE`. A fourth, `PAYLINK` (a one-off price on a link), exists in the dashboard only. A one-off sale from code is a dynamic checkout. See `itaja-products`.

**Can the API create a payment link?**
No. Payment links are created, edited and deactivated in the dashboard. A product carries one active link at a time.

**How are customers identified?**
By e-mail, globally across Itaja; each merchant sees only their own relationship with a customer. See `itaja-customers`.

**Does a phone number create a customer?**
No. `name` and `email` are required; the phone is an optional attribute.

**Do I have to create customers before checkout?**
No — a dynamic checkout resolves the customer from `customer.email`. Create one when you need a `customerId` (credits, licences, `subscribe`), ideally with `merchantCustomerId` set to your own user id.

**Do I have to store Itaja's customer id?**
No. Set `merchantCustomerId` (your user id) on the customer and pass your id wherever a route takes `customerId`. See `itaja-customers`.

**Why does `/customers` answer `403 PRODUCT_SCOPE_VIOLATION`?**
The customer routes need a key scoped to a product. An account-scoped key is refused there.

**Where do buyers manage their purchases?**
In Itaja's customer portal (`portal.getitaja.com`), with Itaja's own sign-in; it never takes your API key. Link to it from your confirmation e-mails instead of building a parallel account page.

---

## Credits

**Are coins money?**
No. They are a prepaid unit of the product's own coin; amounts and balances are coin counts, never currency.

**Can I refund coins?**
No: there is no refund route. To make a customer whole, `grant` coins again — the journal tells the two apart. A confirmed refund of a pack sale takes back what remains of the coins automatically (`credits.clawed_back`).

**What makes a grant or consume idempotent?**
A stable `idempotencyKey` of at least 8 characters, per business event. A replay answers `replayed: true` and moves nothing twice.

**Can a customer spend coins from a browser?**
Not with your key. Your server requests a 15-minute **wallet token** and gives it to the browser, which then uses the key-less `/api/v1/public/credits/*` routes.

**Do coins expire?**
They can: `expiresAt` on a grant dates that grant's coins only. Expired coins are burned before any movement and by a daily sweep (`credits.expired`).

**Does the customer get an e-mail when the balance is low?**
No. `credits.low` is a webhook for the merchant; no e-mail is sent to the customer by Itaja.

**If I edit a pack, what do pending sales deliver?**
What was sold. The price and the coins are frozen on the checkout when it is created.

**Can I sell several packs in one sale?**
No — a pack is the unit of sale. The body takes `customerId`, the return URLs, `reference` and `language` only; `quantity`, `amount` or a `customer` object are refused `422`.

---

## Licences

**Does the app need an API key to verify a licence?**
No. `verify`, `activate` and `deactivate` take none: the licence key is the credential. Putting an API key in the app is a leak.

**Why must I send `productId` on verify and activate?**
Because those routes find a key by its value alone — a key from any Itaja product, a free sandbox one included, would be "valid". With a mismatching `productId`, `verify` answers `valid:false, reason: PRODUCT_MISMATCH` and `activate` answers `422`.

**What do I put in `fingerprint`?**
A stable, non-identifying device handle — a hash (`sha256:…`). Never a serial number, MAC address or user name in clear.

**Can one key run on several devices?**
Only up to `activationsMax`, which defaults to **1** at issuance. `null` means unlimited; a product-level setting changes the default.

**How long does offline validation last?**
The entitlement token is valid for at most 72 hours, then a 72-hour grace window — about six days after the last successful check — and never beyond the licence's own expiry. Pin the JWKS in the build and verify the signature before reading any claim.

**A buyer lost their key.**
`resend` sends the same key to the purchase e-mail. `rotate` revokes and replaces it (devices must re-activate). Neither returns an old clear key; the list routes only ever show masked keys.

**Can I read a clear key again later?**
No. A key is returned in clear once, at issuance, or in a batch's creation response. Store it then or recover with `resend`/`rotate`.

**What is the difference between suspend and revoke?**
Suspend is reversible (`resume`), and `activate` will not lift it. Revoke is terminal — only a reissue brings the customer back.

**How do I renew an expired key?**
`PATCH` it with a future `expiresAt`: it returns to `ACTIVE`. `rotate` is not a renewal (`409 LICENSE_EXPIRED`).

**Can I pre-generate keys for a reseller?**
Yes, if the merchant enabled `allowResellerBatches` (off by default): up to 500 per batch, clear keys returned once, unclaimed keys voidable.

**How do I deliver the key straight to my own site after payment?**
Opt in per product, put `[license_key]` in the product's pay-page success URL (dashboard), and exchange the single-use token (`lkr_…`, 15 minutes) from your **server** with `POST /api/v1/licenses/retrieval/redeem`. The clear key never travels in a URL.

---

## Subscriptions

**How do I subscribe a customer from my app?**
`POST /api/v1/subscriptions/subscribe` with `customerId` and `plan` (name) or `planId`. For a paid plan it answers a `paymentUrl` (valid 24 h); the subscription is born when the buyer pays. The same call upgrades, downgrades and cancels to the free plan. See `itaja-subscriptions`.

**Is there a free trial?**
Yes, per plan (`trial.days`). It starts on the pay page without payment, once per customer and product.

**Do cards renew automatically?**
Not today. The card rail is decided but not sellable end to end; every existing contract is `MANUAL` — the buyer pays each period's invoice after a reminder. Don't promise automatic card renewals.

**When is a renewal invoice issued?**
10 days before the period ends, with a payment link. After the due date: reminders (default days 1, 3, 5), a 7-day grace, then suspension. A payment at any point recovers it.

**Can I test renewals in sandbox?**
No. Sandbox renews nothing; renewals only run for live products.

**Which webhook tells me a subscription renewed?**
`invoice.paid`. There is no `subscription.renewed`. Dunning raises `invoice.payment_failed` and `subscription.past_due`; the end raises `subscription.canceled`.

**Can I cancel, pause or resume from my code?**
Yes: `POST /api/v1/subscriptions/:id/cancel` (`immediately` optional), `/pause`, `/resume`. A card-billed subscription refuses them (`409 CONTRACT_HELD_BY_PROVIDER`); today every subscription is manual.

---

## Errors and limits

**What is the error shape?**
`{ "success": false, "error": "<message>", "code": "<STABLE_CODE>" }`, sometimes with `details`. Branch on `code`. Two exceptions: a missing key answers a plain-text `401`, and `503` infrastructure failures carry a `code` next to the message.

**Which codes matter most?**
`MODE_MISMATCH` (422), `PRODUCT_SCOPE_VIOLATION` (403), `MONETIZATION_MODE_MISMATCH` (409), `LIVE_MODE_NOT_ALLOWED` (403), `LIVE_HOST_NOT_ALLOWED`, `CUSTOMER_EMAIL_REQUIRED` and `CUSTOMER_BANNED` (422), the `IDEMPOTENCY_KEY_*` family, `CATALOG_INVALID` (422), `PLAN_NOT_FOUND` (404), `INSUFFICIENT_CREDITS` (409), `RATE_LIMITED` (429), `CHECKOUT_NOT_CONFIGURED` and `SERVICE_UNAVAILABLE` (503), and the `LICENSE_*` family.

**Which errors may I retry?**
Only `429`, `502`, `503`, `409 IDEMPOTENCY_KEY_IN_PROGRESS` and network timeouts — with the **same** `Idempotency-Key` / `reference` / `idempotencyKey`, a cap (e.g. 3) and backoff, honouring `Retry-After`. Never retry other `4xx`.

**What is the `Idempotency-Key` header?**
Every write accepts it. Send one value per logical operation and the same value on retries: a replay returns the stored response (`Idempotent-Replayed: true`) and nothing runs twice. Keys last 24 h.

**What are the rate limits?**

| Route | Limit |
|---|---|
| `POST /api/v1/checkout-sessions` | 60 / minute / account (all keys together) |
| Public licence routes (`verify`, `activate`, `deactivate`) | 60 / minute / IP and 60 / minute / key |
| Public credit routes | 60 / minute / IP and 60 / minute / wallet token |
| Licence batch creation | 10 / minute / account, plus a per-account key quota |

Windows are fixed one-minute windows **per server process**, so the effective ceiling can be higher with several replicas — don't plan around exceeding it.

**What does `503 CHECKOUT_NOT_CONFIGURED` mean?**
This deployment has no payment engine configured. Nothing was written. Stop and tell the merchant; retrying will not help.

**What does `502` mean on checkout creation?**
The payment provider failed (no detail is exposed). Retry with the same `reference`.

**Which date format is used?**
ISO 8601.

---

## Going live and security

**What blocks me from creating a live product or key?**
The account must be approved, identity-verified (KYC/KYB reviewed by Itaja) and onboarded. Otherwise `403 LIVE_MODE_NOT_ALLOWED` with `details.missing`. Live return URLs must also be on verified domains. These steps happen in the dashboard and with the Itaja team; the timing is not documented here. Sandbox is never blocked. See `itaja-go-live`.

**Do existing live keys and products stop working if the gate closes?**
The gate applies to **creation**. Existing live products keep selling and existing live keys keep working.

**Who makes the first real payment?**
The merchant, deliberately and for a small amount. An agent never triggers live payments or uses real customer data for tests.

**Where do secrets live?**
In the production secret manager or the host's environment. Never in the repository, the image, the client bundle, CI logs or chat. `.env` files are git-ignored; commit an `.env.example` with names only.

**May the front end call the Itaja API directly?**
Not with a key. Anything with a key runs on the server. The only key-less browser-safe routes are the public ones that use a different credential (a licence key, a 15-minute wallet token).

**Can I log requests and responses?**
Log ids (`checkoutId`, `customerId`, event id), status codes and `code`s. Do not log the API key, the webhook secret, full signature headers, clear licence keys, wallet tokens or full customer records.

---

## For agents

**What should I never do, even if asked to "just make it work"?**
Put a key in client code or a repo; use a live key to test; fulfil an order from a return page; skip signature verification; build a return URL from the request or move it to an unverified host; bypass `LIVE_MODE_NOT_ALLOWED` or a ban; invent an API route; push a live catalogue without a reviewed dry run.

**What if a skill and the API disagree?**
The API (OpenAPI document, then observed behaviour) is right. Say so, continue with the API's behaviour, and open an issue or a pull request on this repository.

**What if a route I need does not exist?**
Say so. Offer the dashboard path if one exists, or flag it to the Itaja team. Do not call a dashboard route with an API key, and do not simulate the feature.

**How should I report my work to the merchant?**
State what was built, what was verified in sandbox, what is left for a human (live key, webhook secret, domain verification, account approval, first live payment), and which limits apply (manual renewals, unenforced plan features, topics never emitted).
