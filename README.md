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
[HTTP API](https://docs.monokulo.com/api/) (`/api/v1` on the Monokulo
address). The shop's server makes each order there with the store's secret
key, following the API's `create-order` form, and sends the customer to the
order's checkout page (`/checkout/<order id>`).

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

The shop asks Monokulo for the order total in the customer's currency;
Monokulo converts it to XMR at its own exchange rate. The currency must be
one your Monokulo prices (or XMR itself): otherwise the customer is asked
to choose another currency or payment method, and the shop's log says why
(Monokulo's own explanation of the problem).

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
| `order.paid` | Paid in full | **Paid**: Processing | The customer is told; note with a link to the order in Monokulo |
| `order.overpaid` | Paid with more than was due | **Paid**: Processing | As paid, and a note that the excess isn't refunded automatically |
| `order.expired` | The payment window closed without full payment | **Expired**: Expired | Note: any funds received stay at the address |
| `order.double_spend_detected` | A payment was double-spent and no longer counts | unchanged | Fraud alert note (the status change, if any, comes as its own event) |
| `order.double_spend_reversed` | That alert was wrong | unchanged | Note |

Notes are for the merchant; only "Order placed" and "Paid" are shown to the
customer. If an order's total changed after a first payment request and the
older request is paid, the order isn't marked paid: a note asks you to
check the amount. A paid order stays paid: a later event (expired, part
paid) only adds a note. Paid means the **Paid** status, or one of
OpenCart's complete statuses (Complete, Shipped by default).

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
(`/api/v1/logs`), when the store there has client logs turned on.

## Upgrading from 0.1

Version 0.1 saved Monokulo's address with each connection. Later versions
use Monokulo's API at `<that address>/api/v1`, and move the saved
connections onto it the first time they read them: connected stores keep
working without connecting again.

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
- **smoke**: a fresh Monokulo (`docker/monokulo-smoke.toml`): sign up, add
  a watch-only wallet, connect, then a guest checkout lands on the Monokulo
  checkout page, and the order, read back through the API, is pending.
  Nothing is paid. It also checks the stand-in Monokulo the other suites
  use against the real one's OpenAPI document.

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
