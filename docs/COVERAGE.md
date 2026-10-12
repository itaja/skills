# Coverage and known limits

What the skills cover, where each part of Itaja lives, and what is **not** available. Written against the API as it stood on `develop`; re-check the OpenAPI document (`/api/references/json`) before relying on a gap having closed.

## Where each capability lives

| Capability | Developer API (`x-itj-key`) | Dashboard (session) | Skill |
|---|---|---|---|
| Products | create, read, update, archive | create, edit | `itaja-products` |
| Share link (payment link) | — | create, edit, deactivate | `itaja-products` |
| Dynamic checkout | `POST /api/v1/checkout-sessions` | — | `itaja-checkout-session` |
| Customers | create, search, read, update, invoices | list, detail, ban | `itaja-customers` |
| Credit wallets | grant, consume, read, balance, wallet token | wallet view, coin and pack setup | `itaja-credits` |
| Credit pack sale | list packs, start checkout | pack setup | `itaja-credits` |
| Licence keys | issue, list, read, revoke, suspend, resume, rotate, resend, adjust, batches, settings | same gestures | `itaja-licenses` |
| Licence verification | public routes (the key is the credential) | — | `itaja-licenses` |
| Subscription plans | — | create, archive, delete | `itaja-subscriptions` |
| Subscription contracts | — (customer summary only) | list, pause, resume, cancel | `itaja-subscriptions` |
| Webhook endpoints | — | create, update, delete, test | `itaja-webhooks` |
| API keys | — | create, regenerate | `itaja-integration` |
| Payouts, analytics, settings | — | yes | not covered |
| Customer portal | — (buyer-facing) | — | `itaja-customers` |

## Webhook topics

| Delivered today | Advertised, **not emitted yet** |
|---|---|
| `checkout.paid` | `payment.success`, `.failed`, `.refunded`, `.pending` |
| `license.issued`, `.activated`, `.deactivated`, `.revoked`, `.expired`, `.rotated`, `.suspended`, `.resumed`, `.updated` | `subscription.created`, `.updated`, `.cancelled`, `.renewed`, `.payment_failed` |
| `credits.granted`, `.consumed`, `.expired`, `.low`, `.clawed_back` | `client.created`, `.updated`, `.deleted` |

A subscription to an advertised topic is accepted and never fires.

## Known limits

| Area | Limit | What the skills do about it |
|---|---|---|
| Subscriptions | Every contract is `MANUAL`: the buyer pays each period's invoice after a reminder. Card auto-renewal is decided but not sellable end to end | `itaja-subscriptions` says so; never promise automatic card renewals |
| Subscriptions | No developer-API route to create plans or contracts | Dashboard only |
| Subscriptions | Card contracts cannot be paused, resumed or cancelled from the dashboard yet (`409 CONTRACT_HELD_BY_PROVIDER`) | Reported, not worked around |
| Subscriptions | Sandbox renews nothing | Test renewals only on live, with the merchant |
| Webhooks | No `subscription.*`, `payment.*`, `client.*` events | Read status from the API instead |
| Webhooks | Retry schedule and source IPs not documented | Design for at-least-once; rely on the signature |
| Reminders | E-mail is the only dunning channel | — |
| Checkout | No iframe; top-level navigation only | — |
| Checkout | Session lives 15 minutes | New session per attempt |
| Credits | No refund route; coins are not refundable | Corrections are grants |
| Licences | `@itaja/licenses` is private and unpublished | Use the HTTP routes |
| Licences | `POST /v1/licenses/redeem` (bind a printed code to a checkout) is not implemented | Not offered |
| Rate limits | Fixed one-minute windows, per server process | Treat as a ceiling to stay under |
| Going live | Live products and `PROD` keys need an approved, verified, onboarded account | `itaja-go-live` reports `details.missing` and stops |
| Pop-up widget | Exists, but its integration guide is not covered here | Ask the Itaja team |

## Not covered yet

Payouts, analytics, notification settings and the dashboard's own configuration. Customer-portal internals are described only from the integrator's side.

## Reporting a gap

If a skill disagrees with the API, or a route you need is missing from this page, open an issue on this repository with the request, the response you got and the OpenAPI entry you compared against.
