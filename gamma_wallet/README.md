# gamma_wallet — developer notes

OpenCart 4 extension for Gamma Wallet. The shop owners' guide is the README one level up; this file is for whoever changes the code. Packaged as `gamma_wallet.ocmod.zip` (contents of this folder at the zip root, this README left out) by `build_zip.py` in the working copy.

## Layout

| Path | What it does |
|---|---|
| `system/library/gamma_wallet.php` | Calls to the Gamma Integration API with the shop's `GWINT_` token (amounts JSON-encoded with `serialize_precision = -1`), the settings, the cached connection check (refreshed hourly), the Reward-service and currency checks, the order reference (`OC-<install tag>-<order id>`), the key that signs the customer's status links, the per-order row (with the one-step claims for a code slot and a settlement), and the reward logic shared by the shop and the admin (bill, reward email — built and escaped here) |
| `admin/controller/payment/gamma_wallet.php` | Settings page, install (table `PREFIX_gamma_wallet_order`, order status *Awaiting Gamma store credits*, 8 events, the hourly cron job, install date, defaults), uninstall (keeps the installation secret, removes permissions), the box on the admin order page, "send again" (directly, through the library) |
| `catalog/model/payment/gamma_wallet.php` | `getMethods` for the payment method, the reward wrappers, and the store-credit logic (start, check, settle, reconcile) |
| `catalog/controller/payment/gamma_wallet.php` | Payment step (`index`, `confirm`), the routes the customer's page polls (`status`, cached 4 s; `newcode`), `cron`, and the event handlers |
| `catalog/view/template/payment/*.twig` | Payment step, the box (reward / store credits / note) and the block added to the order email; every value is escaped (`|e`), because OpenCart turns Twig's autoescape off |
| `catalog/view/javascript/gamma_wallet.js`, `stylesheet/gamma_wallet.css` | Polling, countdown, new code; the same design as the other Gamma plugins |

## Events

| Trigger | Handler | Why |
|---|---|---|
| `catalog/model/checkout/order.addHistory/before` (sort 0) | `eventAddHistory` | An order entering a paid status gets its bill, before OpenCart builds the order email (`mail_order`, sort 1). Admin status changes go through the same method. When the order email went out earlier (paid later, or a provider that confirms later), the reward email goes here. Skipped when an enabled anti-fraud extension may still change the status |
| `catalog/model/checkout/order.addHistory/after` | `eventAddHistoryAfter` | The reward for orders that went through the anti-fraud check, from the status they really got, by email |
| `catalog/controller/common/footer/before` | `eventFooter` | Every 2 minutes at most, store-credit orders still waiting are checked with Gamma (paid in the app after the customer left the page) |
| `catalog/controller/checkout/success/before` | `eventSuccessBefore` | Remembers the order id before OpenCart clears it from the session |
| `catalog/view/common/success/after` | `eventSuccessAfter` | The box on the success page |
| `catalog/view/account/order_info/after` | `eventOrderInfoAfter` | The box on the customer's order page |
| `catalog/view/mail/order_add/after` | `eventOrderMailAfter` | The reward block in the order confirmation email (orders paid at checkout) |
| `admin/view/sale/order_info/after` | `eventAdminOrderInfo` | The box on the admin order page |

View events receive the template data as their second argument (route, data, output), model events the call's arguments. The handlers' parameters have defaults, so a handler opened as a route by URL does nothing.

The cron job `gamma_wallet_reconcile` (hourly, *System → Maintenance → Cron Jobs*) runs `cron`: the same check as `eventFooter`, plus the connection check.

## Rules it follows

- "Paid" is the extension's own setting (*Order statuses that mean the money is in*, default Processing, Shipped, Processed, Complete). OpenCart's own *processing* statuses include *Pending*, so they can't be used.
- Pay-later payment extensions (`cod`, `bank_transfer`, `cheque`) are unticked by default; when ticked they earn the reward when the shop moves the order to a paid status, with an email of their own, sent once.
- Store credits settle the whole order; the order waits in its own status until `Credit/Check` says Paid, then moves to the "settled" status with the request id in its history.
- One live store-credit code per order: the slot is claimed in one `UPDATE` before Gamma is asked; settlement is claimed the same way, and Gamma's answer must match the order's reference, total and currency. An order no longer in the waiting status keeps its status and gets a history note.
- Only orders placed since the install (`gamma_wallet_data_installed_on`, reset by every install) earn rewards. The installation secret survives an uninstall, so references stay the same and Gamma never rewards an order twice.
- "Send again" runs in the admin through the library (no request to the shop's own website).
- References carry an installation tag because OpenCart order numbers restart at 1 in every installation; a business with two shops would otherwise get Gamma's bill-reference conflict (HTTP 409).

## Tested

OpenCart 4.1.0.4 (PHP 8.2, MariaDB 11, default theme) in Docker, against the live Integration API, with a 0.80 EUR total:

- install through *Extensions → Installer* (upload + install) and *Extensions → Payments* in a fresh shop;
- store credits: QR and countdown on the success page; settled through the app's flow; order moved to Processing with the request id in its history; new code refused while valid, fresh after expiry; wrong key refused;
- rewards for a card order (test card): QR on the success page and in the customer's order confirmation email (not in the shop's alert email);
- cash on delivery: nothing when not ticked; when ticked, a note at checkout, then the reward and one email when the order is set to Processing in the admin; "send again";
- the settings page, saving it, and the extension switching itself off without a Reward service.

1.1.0 (security review fixes), also on 4.1.0.4 against the live Integration API: reinstall through the admin (secret kept, install date reset, 8 events, cron job); card order → bill `OC-<tag>-<id>`; a card order confirmed later → reward email; old orders, a repeated checkout post and a second code while one is live refused; wrong total refused, settlement recorded once; paid after cancel → status kept, history note; status route cached; event routes by URL do nothing; admin settings, order box and "send again" directly from the admin; the customer's order-page box rendered with escaped values.

Not tested yet: an anti-fraud extension end to end, OpenCart 4.0.x, other themes, multi-store.
