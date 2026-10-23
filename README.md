# Monokulo for OpenCart

Accept Monero in an OpenCart 4 shop through your own
[Monokulo](https://docs.monokulo.com/), the self-hosted, non-custodial Monero
payment processor. Monokulo only ever holds a view key: it watches the
chain for payments to your wallet and tells the shop when an order is paid.
No spend key touches the shop or Monokulo.

- OpenCart **4.1.0.4**, PHP **8.1** or newer (tested on 8.1, 8.4 and 8.5;
  OpenCart doesn't officially support 8.5 yet)
- A store on [www.monokulo.com](https://www.monokulo.com/), or on your own Monokulo
  (its operator must have set its public address)
- GPL-3.0-or-later

## Install

1. Download `monokulo.ocmod.zip` from the
   [latest release](https://github.com/OceanEilonwy/monokulo-opencart/releases/latest).
   Keep the file name: OpenCart names the extension after the file.
2. In the OpenCart admin: **Extensions > Installer**, **Upload**, then
   **Install** next to *Monokulo (Monero payments)*.
3. **Extensions > Extensions**, choose **Payments**, then **Install** (the
   green +) next to *Monokulo (Monero)*, then **Edit** (the pencil).

## Connect

On the extension's settings page:

1. Check the **Monokulo address**: `https://www.monokulo.com` (the default),
   or your own Monokulo's address as your browser opens it. **Save**.
2. Under **Stores**, press **Connect** next to your store.
3. Sign in to Monokulo (or sign up) in the page that opens. Monokulo finds
   your store by the shop's web address:
   - one of your Monokulo stores has it: confirm;
   - none does: set one up (its name; the address is filled in), choose
     the wallet its money goes to, then **Back to OpenCart**.
4. You're back on the settings page: *Your store is connected. Monero is
   now offered at checkout.*

Connecting turns the extension on. Monokulo only sends the connect flow back
to the shop over **https** (plain http only for `localhost` or a `.onion`
address), so the shop's address must be https.

Connecting gives the shop the store's keys and the address of Monokulo's
[HTTP API](https://docs.monokulo.com/api/) entry (`/api/v1` on the Monokulo
address, under its path if it is served under one:
`https://example.com/monokulo`), and the same entry on Monokulo's onion
address when it has one. The shop's server makes each order with the
store's secret key, following the entry's `create-order` form, and sends
the customer to the order's checkout page (its `mk:checkout` link). It
builds no Monokulo address itself: every one is a link or a form from the
entry. The entry is kept as long as Monokulo's `Cache-Control` says (an
hour), then checked again with its `ETag`, so an order is usually one
request to Monokulo.

Each OpenCart store connects on its own: a Monokulo store is one web
address. Stores that share an address share one connection. **Connect
again** replaces a store's keys (Monokulo replaces its webhook too).

## What the customer sees

At checkout's last step, *Monero (via Monokulo)* with a **Pay with Monero**
button. Pressing it places the order and sends the customer to the
Monokulo checkout page for it: the amount in XMR, the address, a QR code,
and the payment's progress. The payment link is also in the order
confirmation email and on the order in the customer's account, so they can
come back to it.

### On Tor

A customer browsing the shop on its own `.onion` address stays on Tor: they
are sent to the order's checkout on Monokulo's onion address (the order's
`mk:onion-checkout` link), never to its web address, and that is the link
in their confirmation. When your Monokulo has no onion address (its API entry,
asked for again first if the kept one lacks it, has no `mk:onion-api`)
there is nowhere on Tor to send them: before any
Monokulo order is made, they are told Monero can't be paid over Tor right
now, the order is left as it was, and the shop's log says why. Give
your Monokulo an onion address (Tor inside it, or your own tor) and orders
carry one at once; the settings page shows a store's onion address once it
connects (again) after that.

The shop asks Monokulo for the order total in the customer's currency, in
that currency's own decimal places (as set in OpenCart) without trailing
zeros; Monokulo converts it to XMR at its own exchange rate. The currency
must be one your Monokulo prices (or XMR itself): otherwise the customer is
asked to choose another currency or payment method, and the shop's log
says why (Monokulo's own explanation of the problem).

## Order statuses

Monokulo tells the shop about each change with a signed webhook, and the
extension moves the order through OpenCart's order history, so OpenCart does
what it always does on a status change (stock, coupons, emails, the
customer's account page). Each status is configurable under **Order
statuses**; the defaults:

| Monokulo event | What happened | OpenCart status (default) | Order history |
| --- | --- | --- | --- |
| (Pay with Monero pressed) | The customer was sent to pay | **Order placed**: Pending | The payment link; the customer is told |
| `order.unconfirmed`, `order.confirming` | A payment was seen; waiting for confirmations | **Payment seen**: Pending | Note |
| `order.partial` | Less than the amount due arrived (underpaid) | **Part paid**: Pending | Note |
| `order.paid` | Paid in full | **Paid**: Processing | The customer is told; note with a link to the order in Monokulo (its `mk:dashboard-order` page, which every webhook carries) |
| `order.overpaid` | Paid with more than was due | **Paid**: Processing | As paid, and a note that the excess isn't refunded automatically |
| `order.expired` | The payment window closed without full payment | **Expired**: Expired | Note: any funds received stay at the address |
| `order.cancelled` | Cancelled in Monokulo before it was paid (only Monokulo's POS cancels, its own orders, so a shop order shouldn't get this) | **Expired**: Expired | As expired |
| `order.double_spend_detected` | A payment was double-spent and no longer counts | unchanged | Fraud alert note (the status change, if any, comes as its own event) |
| `order.double_spend_reversed` | That alert was wrong | unchanged | Note |

Notes are for the merchant; only "Order placed" and "Paid" are shown to the
customer. A paid order never goes back: a later event (expired, cancelled, part paid)
only adds a note, and so does more money arriving (an overpayment, or a
second payment request paid too), for you to refund. Paid means the
**Paid** status, or one of OpenCart's complete statuses (Complete, Shipped
by default).

An order can have more than one payment request: when its total changed
after the customer was first sent to pay, the shop asks Monokulo for a new
one. The extension remembers them all. A payment on any of them marks the
order paid (with a note to check the amount, if it was an earlier one);
anything else about an earlier one (expired, cancelled, part paid) is only a note,
since the newest one decides the status.

### Refunds

Monokulo is non-custodial: it never holds the money, so there are no
automatic refunds. Refund an overpayment or the funds of an expired order
from your wallet yourself. The customer may have left a refund address on
the Monokulo checkout; it's on the order in your Monokulo dashboard.

## Webhooks

Connecting registers the store's webhook address with Monokulo:
`https://<your shop>/index.php?route=extension/monokulo/payment/monokulo.callback`.
Each delivery is signed (`X-Monokulo-Signature`); the extension refuses a
missing or wrong signature and one more than five minutes old. Monokulo
retries a delivery the shop didn't take; a delivery seen before changes
nothing. Deliveries and their answers are listed in Monokulo, in the store's
settings under *Webhooks*.

## Logs

The extension writes to OpenCart's `storage/logs/monokulo.log`, each line
with the request's trace id, which matches Monokulo's own logs. **Send
errors to Monokulo** also sends its warnings and errors to your Monokulo
(with the API entry's `send-logs` form), when the store there has client
logs turned on.

## Upgrading

Connected stores keep working without connecting again: the extension adds
what it now keeps to its tables (on install, or the first time it reads a
connection from before). Until a store connects again it has no onion
entry kept, which changes nothing for customers (each order carries its own
onion checkout). The "In Monokulo" link in an order's notes comes with each
webhook, so orders made before the upgrade have it too.

## Uninstall

Uninstalling (Extensions > Payments, then Extensions > Installer) removes
the files and settings and keeps the extension's tables (`monokulo_*`):
orders refer to them, and installing again picks your connections back up.

## Development

Everything runs in Docker on the host's network (Linux): OpenCart 4.1.0.4
at `http://localhost:8090/` with MariaDB.

```sh
scripts/test up          # build and start OpenCart (PHP_VERSION=8.1|8.4|8.5, default 8.4)
scripts/test all         # build the zip, install it through the Extension Installer, run every suite
scripts/test catalog     # one suite: unit, catalog, admin or http (extra args go to PHPUnit)
COVERAGE=1 scripts/test all   # and coverage/summary.txt, coverage/html/
scripts/smoke            # against a real Monokulo (MONOKULO_BIN=path or MONOKULO_IMAGE=image)
scripts/test down
```

Admin: `http://localhost:8090/admin/`, user `admin`, password
`admin-password-1`.

- **unit**: the extension's library (`src/system/library/monokulo`),
  which knows nothing of OpenCart, against a stand-in Monokulo.
- **catalog**, **admin**: the installed OpenCart booted in the test
  process, the way its `index.php` does, each test in a rolled-back
  database transaction.
- **http**: real HTTP through Apache: the Extension Installer, the connect
  flow's redirects, webhooks.
- **smoke**: a fresh Monokulo with an onion address
  (`docker/monokulo-smoke.toml`): sign up, add a watch-only wallet,
  connect, then a guest checkout lands on the Monokulo checkout page, the
  order, read back through the API, is pending, and its dashboard page
  opens; a guest on the shop's onion address is sent to the onion checkout.
  Nothing is paid. It also checks the stand-in Monokulo the other suites
  use against the real one's answers and its OpenAPI document.

Webhook tests use Monokulo's published test vectors, vendored in
`tests/vectors` and pinned to a monokulo commit (`tests/vectors/PIN`).
`scripts/vectors check` compares them with the pinned commit;
`scripts/vectors pin <commit>` moves the pin.

## Release

Bump `VERSION`, `src/install.json` and `Plugin::VERSION` together (a test
checks), then push a tag `v<version>`. The release workflow installs the
zip through OpenCart's Extension Installer, runs the tests and publishes
`monokulo.ocmod.zip` and its `.sha256`. `scripts/build-zip` builds the
same zip locally (byte for byte).
