---
name: itaja-subscriptions
description: Use when a merchant wants recurring billing through Itaja. States what exists today (plans, first-period checkout, manual renewal and dunning) and what does not (card auto-renewal, subscription API routes, subscription webhooks), so no agent builds on features that are not there.
---

# Itaja subscriptions — what is real today

Read `itaja-integration` first. This skill is mostly a map of **limits**, because the most expensive mistake here is building an integration on features that do not exist yet. State of play when this was written; verify against the API reference before relying on a gap having closed.

## The model

- A **plan** is the offer: one or more **items** (each with a price), a currency and a cadence. The plan's price is the **sum of its items** — never look for a single price field.
- A **contract** links one payer to one plan. It carries a status and a current period.
- An **invoice** documents each period. Itaja's invoice is always the legal one.
- `interval` is `WEEK`, `MONTH`, `QUARTER` or `YEAR`, with an `intervalCount`.

## What the developer API offers: nothing to create or run subscriptions

| Needed | Where it happens |
|---|---|
| Create / archive a plan | **Dashboard** |
| List / pause / resume / cancel contracts | **Dashboard** |
| Read a customer's subscription summary | `GET /api/v1/customers/:customerId` (`customerSubscription`) |
| Read a customer's invoices | `GET /api/v1/customers/:customerId/invoices` |

There is **no** `dev-api` route to create a plan, start a subscription, or cancel one. Do not invent one, and do not call the dashboard's session-authenticated routes with an API key — they will not accept it.

## How a subscription starts

The product's **share link** sells the first period:

1. The merchant creates a `SUBSCRIPTION` product and its plans in the dashboard.
2. The buyer opens the share link, picks a plan (only **active** plans with at least one item are listed) and pays the first period at the hosted checkout.
3. On the signed payment confirmation — and only then — the contract is born: `ACTIVE`, period starting at the payment, first invoice already `PAID`.

An integration therefore sends the buyer to the share link. It does not call a "create subscription" API. (A dynamic checkout, `itaja-checkout-session`, sells a one-off sale, not a plan.) Not offered today: free trial from the link, plan change, proration.

## Two collection regimes

| | Card (`AUTOMATIC`) | Mobile money / crypto (`MANUAL`) |
|---|---|---|
| Who runs the schedule | the card network's billing, through Itaja's payment engine | **Itaja** |
| Renewal | charged and retried automatically | the buyer **comes back and pays** an invoice |
| Dunning | none from Itaja | e-mail reminders, then grace, then suspension |
| Cancel / pause / resume from the dashboard | **refused**: `409 CONTRACT_HELD_BY_PROVIDER` | allowed |

**Today every contract is `MANUAL`.** The card rail is decided but not yet sellable end to end. Do not promise a merchant automatic card renewals.

## The manual renewal cycle

- An invoice is issued **10 days before** the period ends and is payable via the share link (signed). The buyer gets a payment-due notice.
- Past the due date: reminders (default attempts at day 1, 3 and 5), then a 7-day grace, then **suspension**. A payment at any point recovers the contract.
- E-mail is the only reminder channel today (not SMS, WhatsApp or push), and reminders only go out when notifications are enabled on the deployment.
- Renewals run only for **live** products. **Sandbox renews nothing** — do not try to test a renewal in sandbox.

## Statuses and gestures (dashboard)

`TRIALING`, `ACTIVE`, `PAUSED`, `CANCELED`, `ENDED`. A contract is **never** `SUSPENDED`: suspension belongs to the dunning machine, and it is what cuts access on the merchant's side.

| Gesture | Effect |
|---|---|
| Pause | `ACTIVE → PAUSED` (refused during a trial: `409 CANNOT_PAUSE_TRIAL`) |
| Resume | back to `ACTIVE`; the paused time is added to the period |
| Cancel at period end (default) | runs to the end of the paid period; later unpaid invoices are voided |
| Cancel now | stops now, **no refund**, every unpaid invoice voided |

Paid invoices are never touched. A plan nobody ever subscribed to can be deleted; otherwise it can only be **archived** (`409 PLAN_HAS_CONTRACTS`), which stops new sales and leaves running contracts renewing.

## Gating access in the merchant's app

The merchant's app needs to know "may this customer use the product right now?". Options that exist today:

- Poll `GET /api/v1/customers/:customerId` and read `customerSubscription` (`isActive`, `isOnFreeTrial`, `shouldRenewAt`, `canceledAt`).
- Treat a payment confirmation (`checkout.paid`, `itaja-webhooks`) as the signal that a period was paid. **Verify against a sandbox delivery before depending on it for renewals** — whether renewal payments surface as `checkout.paid` for your account is not something to assume.

**Do not build on `subscription.*` webhooks.** `subscription.created`, `.updated`, `.cancelled`, `.renewed` and `.payment_failed` appear in the dashboard's event picker, but the platform **does not emit them yet** — a subscription to one is accepted and simply never fires. Same for `payment.*` and `client.*`. The topics actually delivered are `checkout.paid`, `license.*` and `credits.*`.

## Credit subscriptions

A `CREDITS` product with a subscription or hybrid form follows the same shape with its own engine: manual contract, period invoice 10 days ahead, coins granted at each paid period, contract ended for non-payment. See `itaja-credits`. Buyers can cancel a credit subscription from the portal (`itaja-customers`).

## What to tell the merchant

"Plans and subscriber management are in your dashboard; your app sends the buyer to the product's share link and checks the customer's subscription status. Card auto-renewal and subscription webhooks are not available yet; renewals are paid by the buyer through reminders."

## Done when

- [ ] No code calls a plan/subscription route that does not exist on the developer API.
- [ ] Nothing depends on `subscription.*` webhooks.
- [ ] The merchant was told renewals are manual and sandbox does not renew.
- [ ] Access gating reads the customer's status from the API or a verified payment event.
