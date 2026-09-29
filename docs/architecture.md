# Architecture

How the payment flow, order state handling and plugin lifecycle fit together across files. Details of individual steps are in the code comments of the files named below.

## Payment flow

| Step | Where | What happens |
|---|---|---|
| 1 | `Service/Method/Elepay.php` `apply()` | Sets the order to PENDING, runs `purchaseFlow->prepare`, then forwards to `elepay_checkout` within the same request. |
| 2 | `Controller/ElepayController.php` `checkout()` (`/elepay_checkout`) | Accepts only a PENDING order of the current cart. Zero-amount orders skip elepay and are settled directly. Otherwise creates the elepay code (`ElepayHelper::createCodeObject`) and redirects to its `codeUrl`. |
| 3 | elepay | The customer pays and is sent back to `/elepay_checkout_validate`. |
| 4 | `ElepayController::checkoutValidate()` (`/elepay_checkout_validate`) | Retrieves the charge from the elepay API using the returned `chargeId` / `codeId` (the query string is never trusted), validates it and either completes or rolls back the order. |
| 5 | `ElepayController::elepayWebhook()` (`/elepay_paid_webhook`, POST) | Server-to-server notification that settles the order independently of the customer's browser. Returns 200 on success or when the order is already settled, 400 otherwise. |

`checkout()` of the payment method class always returns a failed `PaymentResult`: `apply()` leaves the purchase flow by forwarding, so it is never reached in normal operation.

The Webhook URL to register on the elepay side is shown on the plugin's admin configuration page.

## Order status transitions

```
PENDING --(charge captured)--> PAID (elepay settlement)
PENDING --(cancelled/failed, owner browser only)--> PROCESSING (purchaseFlow->rollback)
PROCESSING --(charge captured later)--> PAID (stock/points reserved again)
```

- Settlement (`ElepayController::settleOrder`) commits the purchase flow, marks the order paid and sends the order mail. A failure in the settlement marks the request transaction rollback-only, so that only the status change is never committed on its own. A mail failure is only logged, unless the transaction is already rollback-only.
- A payment that is captured after the customer cancelled is still accepted. If stock cannot be reserved again, an error is logged for the shop and the payment is kept.
- Any status other than PENDING / PROCESSING counts as settled, including statuses the shop set later on the admin screen.

## Concurrency

The Webhook, the customer's return and a cancel can arrive at the same time. `ElepayHelper::transitionOrderStatus()` performs the status change as a conditional `UPDATE ... WHERE id = ? AND status IN (...)` and reports whether exactly one row was changed, so only one request runs the follow-up processing. A later request waits on the row lock until the earlier transaction ends and then matches nothing. `fetchLatestOrderStatusId()` reads the status with a pessimistic write lock, because a plain SELECT under MySQL REPEATABLE READ returns the snapshot taken at the start of the transaction.

A consequence: a losing request waits for the winner's commit, which includes the winner's mail sending time.

## Order ownership

`/elepay_checkout_validate` can be opened by anyone who knows an order number. `checkout()` stores the order id in the session under `elepay42.checkout.order_id`; the browser holding it is the owner.

| Caller | Order not settled | Order settled |
|---|---|---|
| Owner, charge captured | Settle, then complete page | Complete page |
| Owner, cancelled / failed | Roll back to PROCESSING, back to the shopping page (`shopping`) or error page | Complete page |
| Not owner, charge captured | Settle, then `/elepay_paid` | `/elepay_paid` if the charge is verified as captured for this order, otherwise home |
| Not owner, cancelled / failed | Error page, no rollback | Home |

`/elepay_paid` (`Resource/template/default/Shopping/paid.twig`) tells a browser that did not start the payment, for example after returning from a payment app into another browser, that the payment is done. It also deletes the cart of the owner browser by `pre_order_id` so the same cart cannot be bought again. The Webhook likewise deletes the cart by `pre_order_id` when it settles an order, since it has no customer session.

For the owner, `orderComplete()` clears the cart, removes the ownership key and stores the order **id** (not the order number) in `OrderHelper::SESSION_ORDER_ID`, which is what the core complete page looks up.

## Plugin lifecycle (`PluginManager.php`)

- `enable()` registers the default configuration, the payment method and the `/elepay_paid` page. It runs on every enable, so it must not overwrite fields the shop edits on the admin screen (`method`, `charge`, `sort_no`); only `visible` is managed by enable/disable.
- `enable()` binds the payment method to all existing delivery methods (`dtb_payment_option`).
- `update()` registers pages added by newer versions (skipped when they exist). It is otherwise empty.
- `uninstall()` hides the `Payment` row (kept, because past orders refer to it), removes its `PaymentOption` rows and removes the plugin's pages. Dropping the delivery bindings is what allows the remaining "QRコード決済" record to be deleted on the admin screen.

EC-CUBE's payment deletion does not cascade to `dtb_payment_option`; it relies on the foreign key on `payment_id` to reject the deletion. On an installation lacking that foreign key, deleting a payment method leaves rows pointing at a missing payment and the purchase screen fails with an `EntityNotFoundException`. The plugin deliberately does not clean these rows on enable: once the `dtb_payment` row is gone, rows of this plugin cannot be told apart from others, so any automatic cleanup could delete other payment methods' bindings. Handle it in support by finding the rows, deleting them and restoring the foreign key:

```sql
-- find
SELECT po.delivery_id, po.payment_id FROM dtb_payment_option po
LEFT JOIN dtb_payment p ON p.id = po.payment_id WHERE p.id IS NULL;

-- delete (PostgreSQL)
DELETE FROM dtb_payment_option po
WHERE NOT EXISTS (SELECT 1 FROM dtb_payment p WHERE p.id = po.payment_id);
```

## URLs

Generate every URL with the router (`generateUrl` / Twig `path()` / `url()`), never by concatenating `Request::getBasePath()` or a root-relative path, so that installations in a sub-directory (as on the EC-CUBE store verification environments) work. `ElepayHelper::addQuery()` does not encode values and is only for appending `mode` / `locale` to elepay's `codeUrl`.

Behind a reverse proxy, absolute URLs fall back to `http://` unless EC-CUBE's `TRUSTED_PROXIES` / `force_ssl` are configured; that is a host-side setting.

## Bundled SDK

`Resource/vendor/` (gitignored, rebuilt with `./build-vendor.sh`) holds only `elestyle/elepay-php-sdk` and its dependencies. It is resolved against PHP 7.4, its autoloader is appended after the host's so the host's classes win on a name clash, and the SDK's implicit nullable parameters are patched (the script fails if the patch does not take effect). Delete the patch once the upstream SDK ships the fix. `SourceInfo` and `TerminalToken*` in SDK 1.2.3 are broken and unused by the plugin, and are left untouched.
