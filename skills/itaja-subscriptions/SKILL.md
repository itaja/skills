---
name: itaja-subscriptions
description: Sells recurring plans through Itaja — putting a customer on a plan with POST /api/v1/subscriptions/subscribe (new, upgrade, downgrade, cancel to free), listing, pausing, resuming and cancelling subscriptions, free trials, manual renewal and dunning, billing webhooks, and the limits (no card auto-renewal yet, no enforced entitlements). Use when a merchant bills customers every week, month, quarter or year.
license: MIT
metadata:
  author: itaja
  version: "1.0.0" # x-release-please-version
---

# Itaja subscriptions

Read `itaja-integration` first. Package or adapter: `itaja-sdk` (in SDK 0.3.0, call `subscribe` through `request()`). Plans are declared in code (`itaja-catalog`) or in the dashboard. The developer API moves customers between plans and manages their subscriptions.

## The model

- A **plan** is the offer: a price, a currency and a cadence (`week`, `month`, `quarter`, `year`, × `intervalCount`), optional free trial, optional default (free) plan per product.
- A **subscription** (contract) links one customer to one plan, with a status and a current period. One live subscription per customer and product.
- An **invoice** documents each period; Itaja's invoice is the legal one.
- Statuses: `TRIALING`, `ACTIVE`, `PAUSED`, `CANCELED`, `ENDED`.

## Putting a customer on a plan — `subscribe`

`POST /subscriptions/subscribe` is the only write a merchant needs. It never creates a paid subscription without a payment.

```json
{ "customerId": "user_123", "plan": "Pro",
  "successUrl": "https://app.example/billing/merci", "cancelUrl": "https://app.example/billing" }
```

- `customerId`: Itaja's id or the merchant's own (`itaja-customers`).
- **Exactly one** of `plan` (the name declared in code, compared case-insensitively among plans on sale) or `planId` (Itaja's id; required for a plan made in the dashboard). Both or neither: `422 VALIDATION_ERROR`.
- `productId`: only when an account-scoped key names a plan sold by several products (`409 AMBIGUOUS_PLAN` otherwise).
- `successUrl`, `cancelUrl`: absolute `https` URLs of the merchant's site where the pay page sends the payer back; build them from server configuration, never from the request.
- The product needs an active pay page (link) to take the payment; a product without one refuses with `409 INVALID_CONTRACT_TRANSITION`.
- Send an `Idempotency-Key`.

Response: `data.outcome`, `data.subscription`, `data.paymentUrl`, `data.paymentUrlExpiresAt`, `data.scheduledChange`, `data.onDefaultPlan`, `data.plan` (`{ id, name, productId }`).

| The customer… | Target | `outcome` | What to do |
|---|---|---|---|
| has no live subscription | a paid plan | `payment_required` | Redirect to `paymentUrl`. **It expires 24 h after the call** (`paymentUrlExpiresAt`): call `subscribe` again for a fresh one, never cache it. The subscription is born when the first period is paid (or when a trial starts on that page). |
| has no live subscription | the default plan | `on_default_plan` | Nothing to pay. |
| is `ACTIVE` | a higher plan (same currency and interval) | `upgrade_pending` | Redirect to `paymentUrl`: a prorated invoice; the new plan applies once it is paid. |
| is `ACTIVE` | a lower or equal-price plan | `change_scheduled` | Applies at the end of the period (`scheduledChange.effectiveAt`). |
| is `ACTIVE` | the default plan | `cancel_scheduled` | Ends at the end of the paid period. |
| has something pending | its current plan | `pending_cleared` | The pending change or cancel is undone. |
| is `TRIALING` | any plan | `change_scheduled` | Applied at the trial end, no proration. |
| — | the same thing again | `unchanged` (or the same answer) | Nothing written. |

Refusals: `409 INVALID_CONTRACT_TRANSITION` (paused subscription), `409 PLAN_CHANGE_NOT_SUPPORTED` (another currency or interval), `409 CONTRACT_HELD_BY_PROVIDER` (card-billed), `404 PLAN_NOT_FOUND` (unknown name, or a plan renamed in code), `404 CUSTOMER_NOT_FOUND`.

**Fulfil from the webhook, not from `successUrl`.** The return is payer-visible. Grant access on `checkout.paid` / `invoice.paid`, or after reading `GET /subscriptions/:id`.

## Managing subscriptions

| Method | Path | Notes |
|---|---|---|
| `GET` | `/subscriptions` | Filters `productId`, `planId`, `customerId`, `status` |
| `GET` | `/subscriptions/:subscriptionId` | One subscription and its invoices |
| `POST` | `/subscriptions/:subscriptionId/cancel` | `{ "immediately": false }` (default) ends at period end; `true` ends now, **no refund**, unpaid invoices voided |
| `POST` | `/subscriptions/:subscriptionId/pause` | `ACTIVE → PAUSED`; `409 CANNOT_PAUSE_TRIAL` during a trial |
| `POST` | `/subscriptions/:subscriptionId/resume` | `PAUSED → ACTIVE`; paid days are not lost |

Paid invoices are never touched. A subscription out of the key's reach answers `404 SUBSCRIPTION_NOT_FOUND`.

## Free trials

A plan with `trial.days > 0` starts `TRIALING` on the pay page **without any payment**. One trial per customer and product, ever (sandbox included). The buyer is e-mailed 72 h before the trial converts; conversion goes through the normal renewal below.

## Renewal: manual today

| | Mobile money, crypto (`MANUAL`) | Card (`AUTOMATIC`) |
|---|---|---|
| Who renews | the buyer comes back and pays an invoice | the card is charged automatically |
| Status | **every subscription today** | decided, **not sellable yet** |

- The period invoice is issued **10 days before** the period ends, with a payment link, and the buyer is e-mailed.
- Past the due date: reminders (by default at days 1, 3 and 5), a 7-day grace, then suspension. A payment at any point recovers the subscription. E-mail is the only reminder channel.
- **Sandbox renews nothing.** Renewals only run for live products; test renewal logic with the merchant, not in sandbox.

Never promise a merchant automatic card renewals.

## Webhooks

| Topic | When |
|---|---|
| `checkout.paid` | the first period was paid on the pay page (the subscription is born) |
| `invoice.paid` | a later invoice is paid: renewal, upgrade, recovery from dunning |
| `invoice.payment_failed` | a manual invoice passed its due date and entered dunning — once per invoice |
| `subscription.past_due` | the subscription entered dunning |
| `subscription.canceled` | the subscription ended for good (`data.reason`: `merchant`, `period_end`, `provider_canceled`, `retries_exhausted`) |

Fields include `contractId`, `customerId`, `planId`, period dates, and `amount` in major units. `subscription.created`, `.updated`, `.renewed` and `.payment_failed` are **not emitted**: there is no "renewed" event — `invoice.paid` is the renewal signal. See `itaja-webhooks`.

## Gating access in the merchant's app

Store the subscription state from the webhooks above and reconcile with `GET /subscriptions?customerId=…`. Grant access while `ACTIVE` or `TRIALING`; restrict on `subscription.past_due` per the merchant's policy; remove on `subscription.canceled`.

Plan features (`includes`) are recorded but **not enforced or metered by Itaja yet** — there is no entitlement check endpoint. The merchant's app enforces its own limits from the plan the customer is on.

## Done when

- [ ] Customers move between plans only through `subscribe`; no invented "create subscription" call.
- [ ] `paymentUrl` is used immediately, never stored past its expiry.
- [ ] Access follows `invoice.paid` / `subscription.*` webhooks and the API, never the return URL.
- [ ] The merchant was told renewals are manual and sandbox does not renew.
- [ ] Feature limits are enforced by the app, not assumed from Itaja.
