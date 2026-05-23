# Configuration Guide

All runtime configuration lives in `config.php` at the project root. **Never commit this file** — use [`config.example.php`](../config.example.php) as a template.

---

## Quick Setup

```bash
cp config.example.php config.php
nano config.php
```

---

## Configuration Variables

### Database

| Variable | Example | Description |
|----------|---------|-------------|
| `$dbhost` | `localhost` | MySQL host |
| `$dbname` | `bold_connection` | Database name |
| `$usernamedb` | `bolduser` | MySQL username |
| `$passworddb` | `***` | MySQL password |

The installer creates these automatically. For remote MySQL, use the host IP/hostname.

### Telegram

| Variable | Example | Description |
|----------|---------|-------------|
| `$APIKEY` | `7123456789:AAH...` | Bot token from @BotFather |
| `$adminnumber` | `123456789` | Primary admin Telegram chat ID |
| `$usernamebot` | `MyShop_bot` | Bot username without `@` |
| `$domainhosts` | `bot.example.com` | Public domain **without** `https://` |

### Performance

| Variable | Default | Description |
|----------|---------|-------------|
| `$request_exec_timeout` | `null` | cURL timeout (seconds) for slow panel APIs. `null` = PHP default |

### Security (required in production)

| Variable | Default | Description |
|----------|---------|-------------|
| `$allow_self_signed_certs` | `false` | Allow self-signed TLS for outbound cURL. **Keep false in production.** |
| `$telegram_webhook_secret` | `''` | Secret for Telegram webhook validation. Must match `setWebhook secret_token`. |
| `$payment_webhook_key` | `''` | Secret for panel/card webhooks via `X-Webhook-Secret` header. |

Generate strong secrets:

```bash
openssl rand -hex 32
```

---

## Telegram Webhook Secret

When `$telegram_webhook_secret` is non-empty, [`index.php`](../index.php) validates:

```
HTTP Header: X-Telegram-Bot-Api-Secret-Token
```

Register webhook:

```bash
curl -s "https://api.telegram.org/bot<TOKEN>/setWebhook" \
  -d "url=https://<domain>/index.php" \
  -d "secret_token=<telegram_webhook_secret>"
```

If secret is empty, only Telegram IP range check (`checktelegramip()`) applies.

---

## Payment / Panel Webhook Secret

When `$payment_webhook_key` is set, [`webhooks.php`](../webhooks.php) and [`payment/card.php`](../payment/card.php) validate:

```
HTTP Header: X-Webhook-Secret
Value: base64(raw_secret) OR raw secret (via verifyPaymentWebhookSecret)
```

Generate header value for panel config:

```bash
echo -n 'your_payment_webhook_key' | base64
```

If empty, legacy fallback compares against admin password in database (deprecated).

---

## PDO Options

Defined in config — do not change unless you know why:

```php
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];
```

---

## Database Connection Failure Behavior

If PDO connection fails during an HTTP request:

- HTTP **200** returned with `{"ok":false,"description":"Service temporarily unavailable"}`
- Prevents infinite webhook retries from Telegram/panels
- Error logged server-side

CLI scripts will continue without `$pdo` — check logs.

---

## Application Settings (Database)

Most business settings are **not** in `config.php` — they live in the `setting` table and are managed via the bot admin UI:

- Payment gateway keys (Zarinpal merchant ID, etc.)
- Channel membership requirements
- Text customization (`text.json`)
- Product/catalog configuration
- Report channel IDs

---

## Timezone

Application timezone is set in entry scripts:

```php
date_default_timezone_set('Asia/Tehran');
```

Change in `index.php`, `webhooks.php`, and API files if your market differs.

---

## Example Production config.php

```php
<?php
$request_exec_timeout = null;

$dbhost      = 'localhost';
$dbname      = 'bold_connection';
$usernamedb  = 'bolduser';
$passworddb  = 'generated_secure_password';

$APIKEY      = '7123456789:AAHxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx';
$adminnumber = '123456789';
$domainhosts = 'bot.example.com';
$usernamebot = 'MyShop_bot';

$allow_self_signed_certs = false;
$telegram_webhook_secret = 'a1b2c3d4e5f6...';  // 64 hex chars
$payment_webhook_key     = 'f6e5d4c3b2a1...';  // 64 hex chars

// ... PDO bootstrap (copy from config.example.php)
```

---

## Related

- [security.md](security.md) — hardening checklist
- [deployment.md](deployment.md) — full install steps
- [environment.md](environment.md) — server requirements
