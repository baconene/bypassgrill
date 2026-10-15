# Android POS Printing

The Android POS prints receipts straight to a Bluetooth thermal printer connected to the phone. When the phone has no printer, it sends the receipt to the online print channel (Pusher) instead. Receipts printed from the web also reach the phone over that channel and print on its printer.

Code lives in `android-app/src/printing/` (app) and `app/Http/Controllers/Api/V1/MobilePosController.php` (server).

## Connecting a printer

Open **Printer Settings** on the phone. The app scans for nearby Bluetooth printers when the screen opens; select **Scan for printers** to scan again.

1. Allow **Nearby devices** when Android asks. If Bluetooth is off, the app asks to turn it on. Android 11 and older also need Location turned on to scan.
2. Turn the printer on. Paired devices show **PAIRED**; others show **NEW**.
3. Tap your printer. A new printer is paired first: Android shows its PIN prompt, and most printers use `0000` or `1234`. The app then connects and saves the printer as this phone's printer.
4. Select **Test print**. The test receipt includes a QR code, which confirms the printer can print images.

**Reconnect saved printer** reconnects without scanning. **Use Laravel default printer** forgets this phone's choice and falls back to the printer address saved on the web.

Scanning uses a small native module (`android-app/plugins/android/BluetoothScannerModule.kt`), installed into the APK by the `withThermalPrinter` config plugin. The thermal printer library itself only lists already-paired devices. A new native module means a new APK is needed; a JavaScript update alone does not deliver it.

## Printing and auto print switches

Two switches on the Printer screen apply to this phone only. Each shows the web setting (**Settings → Print Service**) underneath. Until a switch is changed on the phone, it follows the web setting.

| Switch | Effect |
| --- | --- |
| Printing on this device | Off: the phone neither prints POS receipts nor prints receipts sent from the web. |
| Auto print after payment | On: the receipt prints by itself after a paid checkout. Orders saved as "pay later" do not auto print. |

## Where a receipt goes

**Print receipt** (POS), **Reprint receipt** (Orders) and auto print follow the same rule:

1. **Printer connected to this phone**: the receipt prints over Bluetooth and is **not** sent to Pusher, so no internet is needed. If the connection is closed but a printer is saved, the app tries once to reconnect first.
2. **No printer connected**: the order is sent to the Pusher print channel through `POST /api/v1/mobile-pos/orders/{id}/print`, for another printing device to pick up. An order that has not synced yet is synced first.
3. **Direct print fails part-way** while online: the receipt falls back to the Pusher channel. If the printer stopped mid-receipt, this can print a receipt twice.
4. **No printer and no internet**: the app shows an error asking you to connect the printer.

The message after printing says which path was used.

## Receiving prints from the web

The web **Print** and **Reprint** buttons send the receipt to Pusher (channel `orders` by default, event `print`). While the app is open, the phone listens on that channel. A receipt is printed only if **Printing on this device** is on and the phone's printer is connected; otherwise it is skipped.

The Printer screen shows **Web print requests** (for example "Listening on orders") and **Last web print** (for example "Printed #1042" or "Skipped – No printer connected to this device"). **Reconnect online printing** restarts the listener.

- The app gets the Pusher key, cluster and channel from the server during sync (`realtime` in the mobile bootstrap). The Pusher secret is never sent to the phone.
- The listener only runs while the app is open. Android may drop the connection after the app has been in the background for a while; it reconnects when the app returns to the foreground.
- Every phone with **Printing on this device** on and a printer connected prints every web receipt. Turn it on for only one phone if each receipt should print once.

## Receipt layout

Every print path uses the same layout (`android-app/src/printing/ReceiptFormatter.ts`), recreated from the receipt the previous printing app printed:

```
          BYPASS GRILL            (large)
Likod ng Plaza , Beside Indogrosir

================================
          ORDER #2878             (large)
            TAKEOUT

Date    : Oct 16, 2026 12:32 AM
Cashier : John Adrian Bacon
--------------------------------
Customer: Test off
--------------------------------
ITEM                QTY    TOTAL
--------------------------------
M - Grilled Hungar   1x  P120.00
================================
Subtotal :               P120.00
TOTAL    :               P120.00
================================
Payment  : Cash
Paid     :               P120.00
================================

       Scan to view order
             [QR]
 Thank you for dining with us!
```

- 58 mm paper uses 32 columns and 80 mm uses 48, following the paper width set on the web.
- `Table   :` appears for dine-in orders with a table. `Ref     :` appears when a synced order also has an offline number (see below). `Contact :` and `Address :` join the Customer section when the order has them.
- `Tel:` and `Change :` lines are left out when there is no phone number or no change.
- Amounts use `P` because most thermal printers cannot print `₱`. Item names longer than the item column are cut off.
- The QR code follows the web **Receipt QR** setting: the public order page (default), the Facebook page (label "Follow us on Facebook"), or none. It prints as an image, so printers without built-in QR support can still print it.
- An order created on the phone gets its QR once it has synced. The server returns the order's public link code in the sync response, so the QR works even when printing later without internet. A receipt for an order that has not synced yet prints without a QR.
- The cashier is the user signed in on the phone. Dates use the same format as web receipts.

## Offline order numbers

An order printed before it syncs has no server order number yet. Instead of an ID hash, it gets a short number that restarts every day.

- While online, the phone gets a block of 50 of today's numbers from `POST /api/v1/mobile-pos/number-pool`. The server keeps one counter per day (Philippine time) that starts at 1, and blocks never overlap, so two phones never print the same number. The phone tops up its block during sync and when POS opens, once fewer than 15 numbers are left.
- Every new order takes the next number when it is created: **O-001**, **O-002**, and so on. Unused numbers in a replaced block are skipped, so there can be gaps.
- If the phone has no block for today (offline since midnight) or has used it up, it counts on its own: **L-001**, **L-002**, and so on. The different letter keeps these apart from server-issued numbers. Two phones both counting on their own at the same time can print the same L number.
- The receipt prints **ORDER #O-012** until the order has synced. After that it prints **ORDER #2901** with `Ref     : O-012`. Receipts printed from the web show the same Ref line.
- The Orders list shows **ORDER #O-012** for orders that have not synced yet.
- Synced orders keep the number in `orders.offline_number`, so staff can match a customer's receipt to the order.

## Deploying

The Android features need the Laravel changes deployed to `bypassgrill.baconologies.com` and the migrations run:

```
php artisan migrate
```

The migration `2026_10_16_000001_add_offline_order_number_pool` adds the `mobile_pos_daily_counters` table and the `orders.offline_number` column.

Without the deploy, printing to a printer connected to the phone still works. These do not:

- receiving prints from the web
- falling back to Pusher when no printer is connected
- QR codes for orders created on the phone
- server-issued offline numbers: the phone keeps using L-numbers

After deploying, install the new APK and select **Sync Now** on the phone.

The APK is built by the **Android POS APK** GitHub workflow on every push to `feature/android-pos-app` that changes `android-app/`. Download it from the run's **Artifacts** section (`bypass-grill-pos-release-apk`).

## Server endpoints

All are under `/api/v1/mobile-pos` and need the phone's login token.

| Endpoint | Purpose |
| --- | --- |
| `GET /bootstrap` | Adds `realtime` (Pusher key, cluster, channel, event) to the existing sync data. |
| `POST /sync` | Accepts `offline_number` per order, saves it on the order, and returns each order's `public_token`. |
| `POST /orders/{id}/print` | Sends the order's receipt to the Pusher print channel. Returns 422 when online printing is not configured. |
| `POST /number-pool` | Returns a block of today's offline numbers: `{date, start, end}`. Optional `size` (10–200, default 50). |

Tests: `tests/Feature/MobilePosOfflineNumberTest.php`.
