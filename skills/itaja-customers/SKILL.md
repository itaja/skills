---
name: itaja-customers
description: Use when a merchant's code must create, look up or update Itaja customers, or send buyers to the customer portal. Covers e-mail identity, merchant bans, invoices and what the buyer sees.
---

# Itaja customers and the buyer portal

Read `itaja-integration` first.

## A customer is an e-mail address

Itaja's customer identity is **global and keyed by e-mail**: one e-mail = one customer across Itaja. Each merchant sees only their own link to that customer. Consequences:

- An address-less customer does not exist. Every checkout needs the real buyer's `customer.email`.
- Another merchant's data about the same person is never visible to yours.
- Never invent, share or guess addresses (`noreply@…`, `buyer+order42@…`): the address receives receipts, licence keys, invoices and dunning e-mails.

## Routes (developer API, key required)

| Method | Path | Notes |
|---|---|---|
| `POST` | `/api/v1/customers/` | `name` and `email` are required (`400` otherwise). `phoneNumber`, `address`, `metadata` optional. |
| `GET` | `/api/v1/customers/` | Search with `?email=` or `?phone=`; `?limit=` (default 50) |
| `GET` | `/api/v1/customers/:customerId` | Includes the customer's subscription summary |
| `PATCH` | `/api/v1/customers/:customerId` | Partial update |
| `GET` | `/api/v1/customers/:customerId/invoices` | Statuses `AVAILABLE`, `UNPAID`, `OVERDUE`, `PAID` |

An address already taken answers a dedicated conflict, not a duplicate row. Look the customer up by `email` first; create only when absent.

> The written API reference once described `email` **or** `phoneNumber` for creation. The route requires `name` **and** `email`, and a phone number never makes a customer exist. Follow the route; the OpenAPI document is authoritative.

Using a product-scoped key, customers are reachable only through that product's account scope.

## Do I need to create customers at all?

Often no. A **dynamic checkout** carries `customer.email`, and the customer is resolved from it. Create customers explicitly when you need an id up front — notably `customerId` for `itaja-credits` grants, consumes and pack sales, and for licence issuance.

## Bans

A merchant can ban a customer from their dashboard. The ban belongs to the `(merchant, customer)` pair — the same buyer keeps buying elsewhere. A banned buyer's checkout is refused `422 CUSTOMER_BANNED`, **and the message carries the merchant's reason, written for the buyer to read**. Show it as written. It is not a bug and not a `CUSTOMER_RESOLUTION_FAILED`.

## Privacy rules for the integration

- `metadata` is stored as given and passed around: no passwords, tokens, card data, ID documents.
- Do not log full customer records. Log the Itaja `customerId`.
- Sending a customer's data to a third party is the merchant's decision, not the agent's.

## The buyer portal

Buyers manage what they bought without an Itaja account and without any API key:

- They sign in with a **link sent by e-mail** to an address that has bought from an Itaja merchant. There is no password. The portal never reveals whether an address exists.
- They see purchases (grouped by merchant), invoices, licence keys and devices, credit balances and credit subscriptions.
- The portal lives on its own origin, with its own session. It is not embeddable inside the merchant's app, and it never takes the merchant's API key.

What to do in an integration: tell buyers they can find their licences, receipts and balances there; link to it from the merchant's order-confirmation e-mail; do **not** build a parallel "forgot my licence" flow that stores clear keys. Use `resend` or `rotate` from `itaja-licenses` instead.

## Done when

- [ ] The buyer's real e-mail is passed on every sale.
- [ ] Customers are looked up by e-mail before being created.
- [ ] `CUSTOMER_BANNED` messages are shown verbatim.
- [ ] No clear licence key or secret is copied into a customer's `metadata`.
