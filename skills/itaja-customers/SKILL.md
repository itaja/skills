---
name: itaja-customers
description: Creates, finds and updates Itaja customers through the developer API, maps them to the merchant's own user ids (merchantCustomerId), reads their invoices, and points buyers to the Itaja customer portal. Use when a merchant's code must manage buyers, or link its own users to Itaja customers.
license: MIT
metadata:
  author: itaja
  version: "2.0"
---

# Itaja customers and the buyer portal

Read `itaja-integration` first.

## A customer is an e-mail address

Itaja's customer identity is **global and keyed by e-mail**: one e-mail = one customer across Itaja. Each merchant sees only their own relationship with that customer.

- An address-less customer does not exist. Every sale needs the real buyer's e-mail.
- Another merchant's data about the same person is never visible.
- Never invent, share or guess addresses (`noreply@…`, `buyer+order42@…`): the address receives receipts, licence keys, invoices and reminders.

## Your own customer id — `merchantCustomerId`

The merchant never has to store Itaja's customer id. Give each customer **the merchant's own** user or organisation id (`merchantCustomerId`, 1–255 chars) and use it wherever the API takes a `customerId`: customer routes, credit routes, licence issuance, `subscribe`, and the `customerId` filter of `GET /subscriptions`.

- **Scoped to the account.** Two merchants may both use `user_123`; each reaches only its own customer. Another account's id answers like an unknown one.
- **The e-mail stays the identity.** The merchant's id is a handle onto it, never a second customer.
- **Create is an upsert** when `merchantCustomerId` is sent: same id + same e-mail returns the same customer; an existing customer of the account with that e-mail and no id receives the id.
- Refusals: `409 MERCHANT_CUSTOMER_ID_TAKEN` (the id names another customer), `409 CUSTOMER_ALREADY_MAPPED` (the customer already carries another id — a rename is refused), `409 CUSTOMER_EMAIL_TAKEN` (the e-mail belongs to a customer this merchant never sold to).

Recommended pattern: when a user signs up in the merchant's app, `POST /customers` with `name`, `email` and `merchantCustomerId = <app user id>`; afterwards call every route with the app's own id.

## Routes (developer API, product-scoped key)

The customer routes require a key **scoped to a product**; an account-scoped key answers `403 PRODUCT_SCOPE_VIOLATION`.

| Method | Path | Notes |
|---|---|---|
| `POST` | `/customers` | `name` and `email` required; `merchantCustomerId`, `phoneNumber`, `address`, `metadata` optional |
| `GET` | `/customers` | Search with `?email=` or `?phone=`; `?limit=` (default 50) |
| `GET` | `/customers/:customerId` | Itaja id or the merchant's id; includes `merchantCustomerId` |
| `PATCH` | `/customers/:customerId` | Partial update; can set `merchantCustomerId` on a customer that has none |
| `GET` | `/customers/:customerId/invoices` | Statuses `AVAILABLE`, `UNPAID`, `OVERDUE`, `PAID` |

A phone number never makes a customer exist. Look a customer up before creating one when no `merchantCustomerId` is used.

## Do I need to create customers at all?

- **Dynamic checkout**: no — `customer.email` in the body is enough.
- **Credits, licence issuance, `subscribe`**: yes — they take a `customerId` (Itaja's or the merchant's own).

## Bans

A merchant bans a customer from the dashboard. The ban belongs to the `(merchant, customer)` pair; the buyer keeps buying elsewhere. A banned buyer is refused `422 CUSTOMER_BANNED`, **and the message is the merchant's reason, written for the buyer**. Show it as written; never work around it.

## Privacy rules

- `metadata` is stored as given: no passwords, tokens, card data or identity documents.
- Log the customer id, not full customer records.
- Sending a customer's data to a third party is the merchant's decision, not the agent's.

## The buyer portal

Buyers manage what they bought on Itaja's customer portal, on its own origin, without the merchant's API key: purchases grouped by merchant, invoices, licence keys and devices, credit balances and credit subscriptions. They sign in to it with Itaja's own sign-in.

In an integration: link to the portal from the merchant's confirmation e-mails, and do **not** build a parallel "forgot my licence" flow that stores clear keys — use `resend` or `rotate` (`itaja-licenses`).

## Done when

- [ ] The buyer's real e-mail is passed on every sale.
- [ ] App users are mapped with `merchantCustomerId` (or looked up before being created).
- [ ] The customer routes use a product-scoped key.
- [ ] `CUSTOMER_BANNED` messages are shown verbatim.
- [ ] No clear licence key or secret is copied into a customer's `metadata`.
