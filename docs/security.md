# Security Guide

Security model, recent hardening changes, and production checklist for Bold Connection 0.1.5.

---

## Threat Model

Bold Connection handles:

- **Money** — wallet balances, payment callbacks
- **Credentials** — panel API passwords, gateway keys
- **User data** — Telegram IDs, phone numbers, subscription links

Primary risks:

| Risk | Mitigation |
|------|------------|
| Forged Telegram webhooks | IP validation + optional secret token |
| Forged payment callbacks | Atomic invoice claiming, webhook secrets |
| Double payment credit | `confirmPaymentAtomically()` with `FOR UPDATE` |
| IDOR on invoices/services | `loadOwnedInvoice*` ownership checks |
| SQL injection | PDO prepared statements (mysqli removed) |
| TLS MITM on panel calls | `applyCurlTlsOptions()` — verify peer by default |
| Secret leakage | `config.php` gitignored, example template only |

---

## Security Features (0.1.5)

### 1. PDO-only database access

[`config.php`](../config.php) uses PDO exclusively. Legacy mysqli bootstrap removed.

### 2. Atomic payment confirmation

```php
confirmPaymentAtomically($invoiceId, callable $deliver)
```

- Starts transaction
- `SELECT ... FOR UPDATE` on invoice row
- Sets `payment_Status = 'processing'`
- Runs delivery callback (`DirectPayment`)
- Sets `payment_Status = 'paid'`
- Prevents concurrent gateway callbacks from double-crediting

Used in: `zarinpal.php`, `aqayepardakht.php`, `tronado.php`, `iranpay1.php`, `nowpayment.php`, Stars/IranPay2 in `index.php`.

### 3. Telegram webhook secret

When `$telegram_webhook_secret` is set in config:

```php
// index.php
hash_equals($telegram_webhook_secret, $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'])
```

Mismatch → HTTP 403.

### 4. Payment/panel webhook secret

`verifyPaymentWebhookSecret()` in [`function.php`](../function.php):

- Prefers `$payment_webhook_key` from config
- Legacy fallback: admin password from DB (disable by setting config key)

Used in: `webhooks.php`, `payment/card.php`.

### 5. TLS for outbound HTTP

`applyCurlTlsOptions()` respects `$allow_self_signed_certs`:

- **Production:** `false` (default) — full certificate verification
- **Dev only:** `true` — allows self-signed panel certs

### 6. Wallet refund guard

`shouldRefundBalanceOnProvisioningFailure()` — refunds only when payment method is wallet, preventing free-service exploits on panel failure.

### 7. Graceful webhook failure

DB connection failure during HTTP → HTTP 200 JSON ack (prevents retry storms from Telegram/panels).

### 8. Shell command safety

`cronbot/backupbot.php` uses `escapeshellarg()` for mysqldump commands.

---

## Production Checklist

### Configuration

- [ ] Copy `config.example.php` → `config.php` — never commit `config.php`
- [ ] Set `$telegram_webhook_secret` (32+ random bytes)
- [ ] Set `$payment_webhook_key` (32+ random bytes)
- [ ] Keep `$allow_self_signed_certs = false`
- [ ] Use strong MySQL password, localhost-only binding

### Telegram

- [ ] Register webhook with matching `secret_token`
- [ ] Revoke bot token if ever leaked (BotFather → `/revoke`)
- [ ] Restrict admin `$adminnumber` to trusted accounts

### Server

- [ ] Valid HTTPS (Let's Encrypt)
- [ ] Firewall: only 80/443 public; MySQL local
- [ ] Keep OS and PHP updated
- [ ] Restrict `/panel/` and `/api/` if not needed publicly
- [ ] Monitor `error_log` and Apache logs

### Database

- [ ] Dedicated DB user with minimal privileges
- [ ] Regular backups (`cronbot/backupbot.php`)
- [ ] phpMyAdmin not exposed publicly (installer may install it — restrict access)

### Panels & Gateways

- [ ] Panel API credentials rotated periodically
- [ ] Webhook URL uses HTTPS
- [ ] `X-Webhook-Secret` header configured on panel

---

## Incident Response

### Bot token leaked

1. `/revoke` in BotFather → new token
2. Update `$APIKEY` in `config.php`
3. Re-run `setWebhook` with new token

### Webhook secret compromised

1. Generate new secrets (`openssl rand -hex 32`)
2. Update `config.php`
3. Re-register Telegram webhook
4. Update panel webhook header

### Suspected double payment

1. Check `Payment_report` for duplicate `id_invoice` with `paid` status
2. Review gateway callback logs
3. Invoices stuck in `processing` may need manual DB fix if `DirectPayment` failed mid-flight

---

## Known Limitations

- Telegram IP check alone is weaker than secret token — always enable secret in production
- Some legacy panel drivers may have inconsistent TLS settings — audit if using exotic panels
- No built-in API rate limiting — use reverse proxy rules
- Admin web panel (`admin.php`) relies on Telegram session — protect server access

---

## Related

- [configuration.md](configuration.md)
- [architecture.md](architecture.md)
- [deployment.md](deployment.md)
