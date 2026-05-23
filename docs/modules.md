# Module Reference

Overview of major Bold Connection modules and their responsibilities.

---

## Core Modules (root)

| File | Module | Description |
|------|--------|-------------|
| `index.php` | Telegram handler | Main webhook — routes messages, callbacks, purchases |
| `admin.php` | Admin UI | Web-based admin panel (accessed via bot) |
| `function.php` | Core library | DB helpers, payments, cron, TLS, security utilities |
| `botapi.php` | Telegram client | `telegram()` wrapper for Bot API calls |
| `panels.php` | Panel orchestrator | `ManagePanel` class — multi-panel provisioning |
| `keyboard.php` | Keyboard builder | Reply/inline keyboard generation |
| `table.php` | Schema manager | CREATE/ALTER migrations, initial webhook setup |
| `webhooks.php` | Panel webhook | Receives panel events (Marzban/Marzneshin) |
| `request.php` | HTTP client | Outbound requests with TLS options |

---

## Panel Drivers

Each VPN panel type has a dedicated driver file loaded by `panels.php`:

| File | Panel type |
|------|------------|
| `Marzban.php` | Marzban |
| `marzneshin.php` | Marzneshin |
| `alireza_single.php` | Alireza single-node |
| `hiddify.php` | Hiddify |
| `s_ui.php` | S-UI |
| `x-ui_single.php` | 3x-ui single |
| `ibsng.php` | IBSng |

Adding a panel in admin stores credentials in the `marzban_panel` (legacy name) table with a `type` field routing to the correct driver.

---

## Payment Gateways (`payment/`)

| File | Gateway | Callback URL |
|------|---------|--------------|
| `zarinpal.php` | Zarinpal | `/payment/zarinpal.php` |
| `aqayepardakht.php` | Aqayepardakht | `/payment/aqayepardakht.php` |
| `iranpay1.php` | IranPay | `/payment/iranpay1.php` |
| `tronado.php` | Tronado (crypto) | `/payment/tronado.php` |
| `nowpayment.php` | NOWPayments | `/payment/nowpayment.php` |
| `card.php` | Card-to-card auto | `/payment/card.php` |

**Payment flow:**

1. User initiates payment in bot → invoice created in DB
2. User redirected to gateway or shown card number
3. Gateway POSTs callback → gateway script
4. `confirmPaymentAtomically()` claims invoice, runs `DirectPayment()`, marks paid
5. `DirectPayment()` provisions VPN or credits wallet

Gateway credentials stored in `PaySetting` table (admin UI).

---

## Cron Jobs (`cronbot/`)

Background tasks invoked by system crontab:

| Script | Schedule | Purpose |
|--------|----------|---------|
| `statusday.php` | */15 min | Daily status reports |
| `croncard.php` | */1 min | Card payment auto-verify |
| `NoticationsService.php` | */1 min | Service expiry notifications |
| `payment_expire.php` | */5 min | Expire unpaid invoices |
| `sendmessage.php` | */1 min | Broadcast queue |
| `plisio.php` | */3 min | Plisio crypto polling |
| `activeconfig.php` | */1 min | Activate scheduled configs |
| `disableconfig.php` | */1 min | Disable expired configs |
| `iranpay1.php` | */1 min | IranPay status polling |
| `backupbot.php` | */5 hours | Database backup |
| `gift.php` | */2 min | Gift/lottery processing |
| `expireagent.php` | */30 min | Agent expiry |
| `on_hold.php` | */15 min | On-hold service handling |
| `configtest.php` | */2 min | Trial config cleanup |
| `uptime_node.php` | */15 min | Node health monitoring |
| `uptime_panel.php` | */15 min | Panel health monitoring |

Registration: `activecron()` in `function.php` (triggered from `admin.php` or installer).

---

## HTTP API (`api/`)

REST-style JSON API for external admin tools — see [api.md](api.md).

Key files:

- `users.php` — user management
- `invoice.php` — invoices & services
- `product.php` / `category.php` — catalog
- `miniapp.php` — Telegram Mini App backend
- `panels.php` — panel CRUD
- `payment.php` — payment records

---

## Multi-Bot Support (`vpnbot/`)

Legacy structure for hosting multiple bot instances under `vpnbot/Default/` and `vpnbot/update/`. Single-bot deployments use root `index.php` directly.

---

## Web Panel (`panel/`)

Separate PHP web panel (`panel/inc/config.php`) for browser-based admin — optional; most admins use Telegram bot admin.

---

## Vendor Dependencies (`vendor/`)

PHP libraries committed to repo (no root `composer.json`):

- **PhpSpreadsheet** — Excel export
- **endroid/qr-code** — QR generation for configs
- **PSR HTTP** interfaces

---

## Localization

- `text.json` — bot message strings (multi-language via `languagechange()`)
- Timezone: `Asia/Tehran` (configurable in entry scripts)

---

## Version

Current version in [`version`](../version): **0.1.5**

---

## Related

- [architecture.md](architecture.md)
- [api.md](api.md)
- [deployment.md](deployment.md)
