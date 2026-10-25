# Coverage and known limits

What the skills cover, where each part of Itaja lives, and what is **not** available. Checked against the production OpenAPI document (`https://api.getitaja.com/api/references/json`, 47 routes) on 2026-10-25. Re-check it before relying on a gap having closed.

## Where each capability lives

| Capability | Developer API (`x-itj-key`) | Dashboard | Skill |
|---|---|---|---|
| Catalogue in code (plans, packs, licences, features) | `PUT /catalog` (+ `?dryRun=true`) | shows them, "Managed by code" | `itaja-catalog` |
| Products | create, read, update, archive (`SUBSCRIPTION`, `CREDITS`, `LICENSE`) | same, plus `PAYLINK` | `itaja-products` |
| Payment link (pay page of a product) | — | create, edit, deactivate | `itaja-products` |
| One-off sale priced by code | `POST /checkout-sessions` | — | `itaja-checkout-session` |
| Fee quote | `POST /quotes` | — | `itaja-checkout-session` |
| Customers | create (upsert by `merchantCustomerId`), search, read, update, invoices — product-scoped key | list, detail, ban | `itaja-customers` |
| Subscriptions | `subscribe`, list, read, cancel, pause, resume | plans, subscribers, same gestures | `itaja-subscriptions` |
| Subscription plans | through `PUT /catalog` | create, archive, delete | `itaja-catalog` |
| Credit wallets | grant, consume, expire, read, balance, wallet token | wallet view | `itaja-credits` |
| Credit packs | list, sell; declared through `PUT /catalog` | create, edit | `itaja-credits` |
| Licence keys | issue, list, read, revoke, suspend, resume, rotate, resend, adjust, batches, settings, retrieval | same gestures | `itaja-licenses` |
| Licence verification | public routes (the key is the credential) | — | `itaja-licenses` |
| Webhook endpoints | — | create, update, delete, test | `itaja-webhooks` |
| API keys, verified domains, KYC | — | yes | `itaja-go-live` |
| Payouts, analytics, revenue sharing | — | yes | not covered |

## Official SDKs

| Package | Registry | Version checked |
|---|---|---|
| `@itaja/node` | npm | 0.3.0 |
| `itaja` | PyPI | 0.3.0 |
| `itaja/itaja-laravel` | Packagist | 0.3.0 |

In 0.3.0, `subscribe()`, `check()` and `report()` throw `NOT_YET_AVAILABLE`; call `POST /subscriptions/subscribe` through the SDK's `request()` (`itaja-sdk`).

## Webhook topics

| Delivered | Advertised in the picker, **never emitted** |
|---|---|
| `checkout.paid` | `payment.success`, `.failed`, `.refunded`, `.pending` |
| `invoice.paid`, `invoice.payment_failed` | `subscription.created`, `.updated`, `.renewed`, `.payment_failed` |
| `subscription.past_due`, `subscription.canceled` | `client.created`, `.updated`, `.deleted` |
| `license.issued`, `.activated`, `.deactivated`, `.revoked`, `.expired`, `.rotated`, `.suspended`, `.resumed`, `.updated` | |
| `credits.granted`, `.consumed`, `.expired`, `.low`, `.clawed_back` | |

A subscription to an advertised topic is accepted and never fires. `license.*` and `credits.*` envelopes carry no `mode` field.

## Known limits

| Area | Limit | What the skills do about it |
|---|---|---|
| Subscriptions | Every subscription renews **manually**: the buyer pays each invoice after an e-mail. Card auto-renewal is decided, not sellable | Never promise automatic card renewals |
| Subscriptions | Card-billed subscriptions refuse pause, resume, cancel, plan change (`409 CONTRACT_HELD_BY_PROVIDER`) | Reported, not worked around |
| Subscriptions | Sandbox renews nothing | Test renewals with the merchant in live |
| Subscriptions | No upgrade or downgrade across currencies or intervals (`409 PLAN_CHANGE_NOT_SUPPORTED`) | — |
| Entitlements | Plan features (`includes`) are stored, not checked or metered; no `check` / `report` endpoint | The app enforces its own limits |
| Webhooks | No generic refund, renewal-specific or customer events | Use `invoice.paid`, `license.revoked`, `credits.clawed_back`, the API |
| Webhooks | Retry schedule and source IPs undocumented | At-least-once design; rely on the signature |
| Reminders | E-mail is the only channel | — |
| Live | Needs an approved, verified, onboarded account and verified return hosts | `itaja-go-live` reports and stops |
| Checkout | No iframe; 15-minute session; payer accepts the Terms of sale on the pay page | New session per attempt |
| Credits | No refund route | Corrections are grants |
| Licences | No published client SDK for apps | Call the public routes |
| Rate limits | Fixed one-minute windows per server process | Stay under them |
| Pop-up widget | Exists; not covered here | Ask the Itaja team |

## Reporting a gap

If a skill disagrees with the API, or a route you need is missing from this page, open an issue on this repository with the request, the response and the OpenAPI entry you compared against.
