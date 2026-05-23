# Remove Mirza Pro Installer + Install Bold Connection Only

Use this when you previously ran the **old Mirza Pro** installer (`mahdiMGF2/mirza_pro`) and want a clean **Bold Connection** install from `Recoba86/Bold-Connection` only.

---

## Part 1 — Full cleanup on the server (run as root)

### 1. Stop services (optional)

```bash
sudo systemctl stop apache2 2>/dev/null || true
```

### 2. Remove Mirza Pro install directory

```bash
sudo rm -rf /var/www/html/mirzaprobotconfig
```

### 3. Remove old installer scripts and symlinks

```bash
sudo rm -f /root/install.sh
sudo rm -f /usr/local/bin/mirza
sudo rm -f /tmp/bold-install.sh ./install.sh
```

### 4. Remove Mirza config folder

```bash
sudo rm -rf /root/confmirza
```

### 5. Remove Bold Connection (if a partial install exists)

```bash
sudo rm -rf /var/www/html/bold-connection
sudo rm -rf /root/.bold-connection
```

### 6. Remove Apache vhosts (Mirza / Bold)

Replace `YOUR_DOMAIN` with your real domain (e.g. `bot.example.com`):

```bash
DOMAIN="YOUR_DOMAIN"

sudo a2dissite "${DOMAIN}.conf" "${DOMAIN}-ssl.conf" 2>/dev/null || true
sudo a2dissite bold-connection.conf bold-connection-ssl.conf 2>/dev/null || true
sudo rm -f "/etc/apache2/sites-available/${DOMAIN}.conf"
sudo rm -f "/etc/apache2/sites-available/${DOMAIN}-ssl.conf"
sudo rm -f /etc/apache2/sites-available/bold-connection.conf
sudo rm -f /etc/apache2/sites-available/bold-connection-ssl.conf
sudo systemctl reload apache2
```

### 7. Remove cron jobs pointing at old paths

```bash
sudo crontab -l 2>/dev/null | grep -v 'cronbot/' | grep -v 'mirzaprobot' | sudo crontab -
```

### 8. Drop Mirza database (optional — destroys bot data)

Default Mirza Pro DB name was `mirzaprobot`. **Only run if you do not need old data.**

```bash
sudo mysql -e "DROP DATABASE IF EXISTS mirzaprobot;"
```

Also drop Bold Connection DB if you created one:

```bash
sudo mysql -e "DROP DATABASE IF EXISTS bold_connection;"
```

### 9. Fix apt/dpkg (required if install was interrupted)

```bash
sudo dpkg --configure -a
sudo apt update
sudo apt -f install -y
```

### 10. Verify cleanup

```bash
ls /var/www/html/mirzaprobotconfig 2>&1
ls /var/www/html/bold-connection 2>&1
ls /root/install.sh 2>&1
which mirza 2>&1
```

All should report “No such file” or empty.

---

## Part 2 — Install Bold Connection (your repo only)

The **only** supported installer is `install.sh` in **Recoba86/Bold-Connection**. It does **not** download from Mirza Pro.

### Prerequisites

- Ubuntu 20.04+ / 22.04 / 24.04 or Debian 11/12
- Root access
- Domain DNS → server IP
- Telegram bot token (@BotFather)
- GitHub PAT only if the repository is **private**

### Install commands (public repository)

```bash
curl -fsSL https://raw.githubusercontent.com/Recoba86/Bold-Connection/main/install.sh -o /tmp/bold-install.sh
head -5 /tmp/bold-install.sh   # must say "Bold Connection"
sudo bash /tmp/bold-install.sh
```

### Install commands (private repository)

```bash
export GITHUB_PAT="ghp_YOUR_TOKEN_HERE"
curl -fsSL \
  -H "Authorization: token ${GITHUB_PAT}" \
  -H "Accept: application/vnd.github.raw" \
  "https://raw.githubusercontent.com/Recoba86/Bold-Connection/main/install.sh" \
  -o /tmp/bold-install.sh
sudo bash /tmp/bold-install.sh
```

Choose **1) Install Bold Connection** and complete prompts (domain, token, chat ID, DB, secrets).

### Success checks

```bash
ls -la /var/www/html/bold-connection/config.php
curl -s "https://api.telegram.org/bot<TOKEN>/getWebhookInfo"
```

Send `/start` to your bot in Telegram.

---

## Do not use these (Mirza Pro — deprecated for this project)

```bash
# WRONG — downloads Mirza Pro
curl -o install.sh -L https://raw.githubusercontent.com/mahdiMGF2/mirzabot/main/install.sh
curl -o install.sh -L https://raw.githubusercontent.com/mahdiMGF2/mirza_pro/...
sudo mirza
```

---

## Repository policy

| Item | Source |
|------|--------|
| Application code | `Recoba86/Bold-Connection` only |
| Installer | `install.sh` in same repo only |
| Mirza Pro (`mahdiMGF2/mirza_pro`) | Not used |

See also: [installer.md](installer.md), [deployment.md](deployment.md).
