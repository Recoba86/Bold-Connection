# Bold Connection Installer

Interactive one-click deployment script for Ubuntu/Debian Linux servers.

---

## Overview

`install.sh` deploys Bold Connection from the private GitHub repository `Recoba86/Bold-Connection` to a production-ready Apache + PHP + MySQL stack with Let's Encrypt SSL.

**Default install path:** `/var/www/html/bold-connection`

---

## Prerequisites

- Root access (`sudo` or direct root)
- Ubuntu 20.04+ / 22.04 / 24.04 or Debian 11/12
- Domain with DNS A record pointing to server
- Telegram bot token ([@BotFather](https://t.me/BotFather))
- Admin Telegram chat ID
- GitHub Personal Access Token with **`repo`** scope

---

## Download & Run

Because the repository is **private**, you must authenticate:

### Option A: Environment variable

```bash
export GITHUB_PAT="ghp_your_personal_access_token"

curl -fsSL \
  -H "Authorization: token ${GITHUB_PAT}" \
  -H "Accept: application/vnd.github.raw" \
  "https://raw.githubusercontent.com/Recoba86/Bold-Connection/main/install.sh" \
  -o /tmp/bold-install.sh

sudo bash /tmp/bold-install.sh
```

### Option B: Interactive PAT prompt

If you copy `install.sh` to the server manually (e.g. via `scp`), run:

```bash
sudo bash install.sh
```

The script prompts for a GitHub PAT when it needs to clone or update the repository.

### Option C: GitHub CLI

If `gh auth login` is already configured on the server, the installer detects it and skips the PAT prompt.

---

## Menu Options

| Option | Action |
|--------|--------|
| **1 — Install** | Full fresh deployment |
| **2 — Update** | Pull latest code, run migrations, preserve `config.php` |
| **3 — Repair** | Re-register webhook, re-run `table.php`, fix cron & Apache |
| **4 — Remove** | Remove cron, vhost, optionally files and database |
| **5 — Status** | Show install state, SSL expiry, webhook info |
| **6 — Exit** | Quit |

---

## Install Flow (Interactive Prompts)

During install, you will be asked for:

| Input | Validation | Default |
|-------|------------|---------|
| Domain name | DNS hostname regex | — |
| Bot token | Telegram token format | — |
| Admin chat ID | Numeric (optional `-` prefix) | — |
| Bot username | Non-empty, no `@` | — |
| Install directory | Absolute path | `/var/www/html/bold-connection` |
| MySQL root password | Required if auth fails | — |
| DB name | Alphanumeric | `bold_connection` |
| DB user | Alphanumeric | Random 8 chars |
| DB password | Min 8 chars | Random 16 chars |
| Telegram webhook secret | Hex string | Auto-generated 32 bytes |
| Payment webhook key | Hex string | Auto-generated 32 bytes |
| Self-signed certs | yes/no | **no** |
| GitHub PAT | Non-empty for private repo | `$GITHUB_PAT` env if set |

---

## What Install Does

1. Detects OS (Ubuntu/Debian)
2. Installs packages: Apache, PHP, MySQL, Certbot, Git, curl, unzip
3. Clones repository to install directory
4. Creates MySQL database and user
5. Writes `config.php` from template
6. Configures Apache VirtualHost (HTTP + HTTPS)
7. Obtains Let's Encrypt certificate via Certbot
8. Registers Telegram webhook with `secret_token`
9. Runs `https://domain/table.php` (schema bootstrap)
10. Registers cron jobs for `cronbot/` scripts
11. Runs health checks (`getWebhookInfo`, HTTP probe)

---

## Idempotency & Recovery

The installer is safe to re-run:

- Existing packages are skipped (`apt` idempotent)
- Existing database is detected — prompts before overwrite
- Existing `config.php` is preserved on **Update** and **Repair**
- Partial install recovery: run **Repair** or re-run **Install** (confirms before overwriting)

State file: `/root/.bold-connection/install.state`

---

## Environment Variables

| Variable | Description |
|----------|-------------|
| `GITHUB_PAT` | GitHub token for private repo clone/update |
| `BOLD_INSTALL_DIR` | Override default install path |
| `BOLD_DOMAIN` | Pre-fill domain (skips prompt) |
| `BOLD_NONINTERACTIVE` | `1` = use env vars only (CI/advanced) |

---

## Creating a GitHub PAT

1. GitHub → Settings → Developer settings → Personal access tokens
2. Generate token (classic) with **`repo`** scope
3. Copy token — shown once
4. Use as `GITHUB_PAT` or paste when prompted

Never commit PATs to git or share in chat logs.

---

## Post-Install

1. Send `/start` to your bot in Telegram
2. Configure VPN panel in admin menu
3. Set panel webhook: `https://your-domain/webhooks.php`
4. Set header `X-Webhook-Secret`: `echo -n 'your_key' | base64`
5. Add products and enable payment gateways

---

## Troubleshooting

### `Failed to clone repository`

- Verify PAT has `repo` scope
- Verify access to `Recoba86/Bold-Connection`
- Try: `git clone https://x-access-token:TOKEN@github.com/Recoba86/Bold-Connection.git`

### Certbot / SSL failure

- Confirm DNS A record propagates: `dig +short your-domain.com`
- Ensure ports 80/443 open: `ufw status`
- Stop conflicting web servers on port 80

### Bot does not respond

```bash
curl -s "https://api.telegram.org/bot<TOKEN>/getWebhookInfo" | python3 -m json.tool
```

Check `last_error_message`. Common causes:

- Secret token mismatch (`$telegram_webhook_secret` vs `setWebhook`)
- Apache not serving `index.php`
- Database connection failure in `config.php`

### `table.php` fails

- Check MySQL credentials in `config.php`
- Check Apache error log: `/var/log/apache2/<domain>-error.log`
- Run manually: `curl -v https://your-domain/table.php`

### Cron not running

```bash
crontab -l | grep cronbot
```

Re-run installer **Repair** or add cron lines from [deployment.md](deployment.md).

### Permission errors

```bash
sudo chown -R www-data:www-data /var/www/html/bold-connection
sudo chmod -R 755 /var/www/html/bold-connection
```

---

## Uninstall

Run installer → **Remove**:

- Removes cron entries
- Disables Apache vhost
- Optionally deletes install directory and database (confirmed interactively)

---

## Related

- [deployment.md](deployment.md)
- [configuration.md](configuration.md)
- [environment.md](environment.md)
