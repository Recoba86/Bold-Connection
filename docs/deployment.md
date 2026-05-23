# Deployment Guide

Complete guide for deploying Bold Connection to a production Linux server.

---

## Deployment Methods

| Method | Best for |
|--------|----------|
| **Installer (`install.sh`)** | Fresh Ubuntu/Debian VPS — recommended |
| **Manual** | Custom stack, existing LAMP, Docker-adjacent setups |

---

## Method 1: One-Click Installer (Recommended)

### Requirements

- Ubuntu 20.04+, 22.04, or 24.04 **or** Debian 11/12
- Root shell access
- Public domain with DNS A record → server IP
- Ports **80** and **443** open
- GitHub PAT with `repo` scope (private repository)

### Steps

1. **Create a Telegram bot** via [@BotFather](https://t.me/BotFather) and save the token.

2. **Get your admin Chat ID** — message the bot, then visit:
   `https://api.telegram.org/bot<TOKEN>/getUpdates`

3. **Create a GitHub PAT** — Settings → Developer settings → Personal access tokens → `repo` scope.

4. **Run the installer:**

   ```bash
   export GITHUB_PAT="ghp_xxxxxxxx"
   curl -fsSL \
     -H "Authorization: token ${GITHUB_PAT}" \
     -H "Accept: application/vnd.github.raw" \
     "https://raw.githubusercontent.com/Recoba86/Bold-Connection/main/install.sh" \
     -o /tmp/bold-install.sh

   sudo bash /tmp/bold-install.sh
   ```

5. Select **Install** from the menu and follow prompts.

6. When complete, send `/start` to your bot in Telegram.

See [installer.md](installer.md) for update, repair, and remove operations.

### What the installer does

1. Installs Apache, PHP, MySQL, Certbot, Git
2. Clones `Recoba86/Bold-Connection` to `/var/www/html/bold-connection`
3. Creates MySQL database and user
4. Writes `config.php` with your inputs
5. Configures Apache VirtualHost + Let's Encrypt SSL
6. Registers Telegram webhook with secret token
7. Runs `table.php` to create database schema
8. Registers cron jobs for background tasks
9. Runs health checks

---

## Method 2: Manual Deployment

### 1. Server preparation

```bash
sudo apt update && sudo apt upgrade -y
sudo apt install -y apache2 mysql-server \
  php php-mysql php-curl php-mbstring php-xml php-zip \
  certbot python3-certbot-apache unzip curl git
```

Enable Apache modules:

```bash
sudo a2enmod rewrite ssl headers
sudo systemctl enable --now apache2
```

### 2. Clone the project

```bash
sudo mkdir -p /var/www/html/bold-connection
sudo git clone https://github.com/Recoba86/Bold-Connection.git /var/www/html/bold-connection
sudo chown -R www-data:www-data /var/www/html/bold-connection
```

For private repo, use PAT in URL:

```bash
git clone https://x-access-token:YOUR_PAT@github.com/Recoba86/Bold-Connection.git
```

### 3. Configure

```bash
cd /var/www/html/bold-connection
sudo cp config.example.php config.php
sudo nano config.php
```

Fill all placeholders — see [configuration.md](configuration.md).

Generate secrets:

```bash
openssl rand -hex 32   # telegram_webhook_secret
openssl rand -hex 32   # payment_webhook_key
```

### 4. Database

```bash
sudo mysql -e "CREATE DATABASE bold_connection CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
sudo mysql -e "CREATE USER 'bolduser'@'localhost' IDENTIFIED BY 'strong_password';"
sudo mysql -e "GRANT ALL ON bold_connection.* TO 'bolduser'@'localhost'; FLUSH PRIVILEGES;"
```

Update `config.php` with DB credentials.

### 5. Apache VirtualHost

Create `/etc/apache2/sites-available/bold-connection.conf`:

```apache
<VirtualHost *:80>
    ServerName bot.example.com
    DocumentRoot /var/www/html/bold-connection

    <Directory /var/www/html/bold-connection>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog ${APACHE_LOG_DIR}/bold-error.log
    CustomLog ${APACHE_LOG_DIR}/bold-access.log combined
</VirtualHost>
```

Enable and obtain SSL:

```bash
sudo a2ensite bold-connection.conf
sudo a2dissite 000-default.conf
sudo certbot --apache -d bot.example.com
sudo systemctl reload apache2
```

### 6. Initialize database

Visit once in browser or curl:

```bash
curl -k "https://bot.example.com/table.php"
```

### 7. Register Telegram webhook

```bash
curl -s "https://api.telegram.org/bot<TOKEN>/setWebhook" \
  -d "url=https://bot.example.com/index.php" \
  -d "secret_token=<your_telegram_webhook_secret>"
```

Verify:

```bash
curl -s "https://api.telegram.org/bot<TOKEN>/getWebhookInfo" | jq .
```

### 8. Cron jobs

Add to root crontab (`crontab -e`):

```cron
*/15 * * * * curl -s https://bot.example.com/cronbot/statusday.php >/dev/null
*/1  * * * * curl -s https://bot.example.com/cronbot/croncard.php >/dev/null
*/1  * * * * curl -s https://bot.example.com/cronbot/NoticationsService.php >/dev/null
*/5  * * * * curl -s https://bot.example.com/cronbot/payment_expire.php >/dev/null
*/1  * * * * curl -s https://bot.example.com/cronbot/sendmessage.php >/dev/null
*/3  * * * * curl -s https://bot.example.com/cronbot/plisio.php >/dev/null
*/1  * * * * curl -s https://bot.example.com/cronbot/activeconfig.php >/dev/null
*/1  * * * * curl -s https://bot.example.com/cronbot/disableconfig.php >/dev/null
*/1  * * * * curl -s https://bot.example.com/cronbot/iranpay1.php >/dev/null
0    */5 * * * curl -s https://bot.example.com/cronbot/backupbot.php >/dev/null
*/2  * * * * curl -s https://bot.example.com/cronbot/gift.php >/dev/null
*/30 * * * * curl -s https://bot.example.com/cronbot/expireagent.php >/dev/null
*/15 * * * * curl -s https://bot.example.com/cronbot/on_hold.php >/dev/null
*/2  * * * * curl -s https://bot.example.com/cronbot/configtest.php >/dev/null
*/15 * * * * curl -s https://bot.example.com/cronbot/uptime_node.php >/dev/null
*/15 * * * * curl -s https://bot.example.com/cronbot/uptime_panel.php >/dev/null
```

Or open the admin panel once — `activecron()` attempts auto-registration.

### 9. Connect VPN panel

In the bot admin menu:

1. Add panel (URL, credentials, type)
2. Set panel webhook URL: `https://bot.example.com/webhooks.php`
3. Set header `X-Webhook-Secret` to base64 of `$payment_webhook_key`:

   ```bash
   echo -n 'your_payment_webhook_key' | base64
   ```

---

## Post-Deployment Checklist

- [ ] `/start` responds in Telegram
- [ ] `getWebhookInfo` shows no errors
- [ ] `table.php` ran successfully
- [ ] Cron jobs active (`crontab -l`)
- [ ] SSL valid (browser padlock)
- [ ] `$telegram_webhook_secret` and `$payment_webhook_key` set
- [ ] Test purchase end-to-end on a cheap product

---

## Updating

**Via installer:** Run `install.sh` → select **Update** (preserves `config.php`).

**Manual:**

```bash
cd /var/www/html/bold-connection
sudo -u www-data git pull
curl -s "https://your-domain/table.php"
```

---

## Troubleshooting

| Symptom | Check |
|---------|-------|
| Bot silent | `getWebhookInfo`, secret token mismatch, Apache error log |
| 403 Forbidden on webhook | `$telegram_webhook_secret` vs `setWebhook secret_token` |
| DB errors | `config.php` credentials, run `table.php` |
| Config not created | Panel API URL, credentials, firewall |
| Payments stuck | Cron running, gateway callback URL, `payment/` logs |

Logs: `/var/log/apache2/bold-error.log` and project `error_log` file.

---

## Related

- [configuration.md](configuration.md)
- [environment.md](environment.md)
- [installer.md](installer.md)
- [راهنمای-نصب-تلگرام-و-پنل.md](راهنمای-نصب-تلگرام-و-پنل.md) — Persian Telegram setup
