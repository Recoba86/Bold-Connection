# Environment Requirements

Server and runtime requirements for Bold Connection.

---

## Supported Operating Systems

| OS | Versions | Notes |
|----|----------|-------|
| Ubuntu | 20.04, 22.04, 24.04 | Primary target for `install.sh` |
| Debian | 11, 12 | Supported by installer |

Other Linux distros may work with manual deployment but are not tested by the installer.

---

## Hardware (minimum)

| Resource | Minimum | Recommended |
|----------|---------|-------------|
| CPU | 1 vCPU | 2+ vCPU |
| RAM | 1 GB | 2 GB+ |
| Disk | 10 GB | 20 GB+ SSD |
| Network | Public IPv4 | Low-latency to Telegram & panels |

---

## Software Stack

| Component | Version | Purpose |
|-----------|---------|---------|
| **Apache** | 2.4+ | Web server (installer default) |
| **PHP** | 8.0+ (8.1/8.2 recommended) | Application runtime |
| **MySQL / MariaDB** | 8.0+ / 10.6+ | Primary database |
| **Certbot** | Latest | Let's Encrypt SSL |
| **Git** | 2.x | Installer clone/update |
| **curl** | Latest | Cron invocations, health checks |

Nginx can replace Apache for manual deployments — configure PHP-FPM separately.

---

## Required PHP Extensions

| Extension | Used for |
|-----------|----------|
| `pdo_mysql` | Database access |
| `curl` | Telegram API, panel APIs, gateways |
| `mbstring` | Unicode / Persian text |
| `json` | Webhook parsing |
| `xml` | Spreadsheet/QR dependencies |
| `zip` | PhpSpreadsheet |
| `openssl` | TLS |

Verify:

```bash
php -m | grep -E 'pdo_mysql|curl|mbstring|json|xml|zip|openssl'
```

---

## Network & Firewall

| Port | Direction | Purpose |
|------|-----------|---------|
| 80 | Inbound | HTTP (Certbot challenge, redirect) |
| 443 | Inbound | HTTPS (webhooks, admin, API) |
| 3306 | Localhost only | MySQL (do not expose publicly) |

Outbound access required to:

- `api.telegram.org` — Bot API
- Your VPN panel URLs
- Payment gateway endpoints
- `github.com` — installer updates

---

## DNS

- A record: `bot.example.com` → server IP
- Propagation: wait before running Certbot
- Wildcard not required

---

## Telegram Requirements

- Bot token from [@BotFather](https://t.me/BotFather)
- Webhook mode (not long polling) for production
- Valid HTTPS certificate (Let's Encrypt acceptable)

Telegram webhook IP ranges are validated by `checktelegramip()` when secret token is not configured.

---

## File Permissions

Installer sets:

```bash
chown -R www-data:www-data /var/www/html/bold-connection
chmod -R 755 /var/www/html/bold-connection
```

Writable at runtime (by www-data):

- `error_log`
- `cronbot/users.json`, `cronbot/info` (broadcast state)
- `api/cache_keyboard.json` (keyboard cache)

These paths are in `.gitignore`.

---

## PHP Settings

Recommended `php.ini` / Apache overrides:

```ini
memory_limit = 256M
max_execution_time = 120
upload_max_filesize = 16M
post_max_size = 16M
date.timezone = Asia/Tehran
```

`index.php` sets `memory_limit = -1` at runtime for heavy admin operations.

---

## MySQL Configuration

- Charset: **utf8mb4** (required for Persian/emoji)
- Engine: InnoDB
- User should have privileges only on the bot database

---

## Environment Variables (optional)

Bold Connection uses `config.php`, not `.env` files. Optional shell variables for installer only:

| Variable | Purpose |
|----------|---------|
| `GITHUB_PAT` | Pre-fill GitHub token for private repo access |
| `BOLD_INSTALL_DIR` | Override install path (default: `/var/www/html/bold-connection`) |
| `BOLD_DOMAIN` | Pre-fill domain in non-interactive mode |

---

## Related

- [deployment.md](deployment.md)
- [configuration.md](configuration.md)
- [installer.md](installer.md)
