#!/usr/bin/env bash
#
# Bold Connection — interactive one-click installer
# Repository: https://github.com/Recoba86/Bold-Connection (private)
#
set -euo pipefail
IFS=$'\n\t'

# ---------------------------------------------------------------------------
# Constants
# ---------------------------------------------------------------------------
readonly SCRIPT_VERSION="1.0.0"
readonly REPO_OWNER="Recoba86"
readonly REPO_NAME="Bold-Connection"
readonly REPO_SLUG="${REPO_OWNER}/${REPO_NAME}"
readonly REPO_BRANCH="${BOLD_BRANCH:-main}"
readonly DEFAULT_INSTALL_DIR="${BOLD_INSTALL_DIR:-/var/www/html/bold-connection}"
readonly STATE_DIR="/root/.bold-connection"
readonly STATE_FILE="${STATE_DIR}/install.state"

# Populated during install
DOMAIN=""
BOT_TOKEN=""
ADMIN_CHAT_ID=""
BOT_USERNAME=""
INSTALL_DIR=""
DB_NAME=""
DB_USER=""
DB_PASS=""
MYSQL_ROOT_PASS=""
TELEGRAM_WEBHOOK_SECRET=""
PAYMENT_WEBHOOK_KEY=""
ALLOW_SELF_SIGNED="false"
GITHUB_PAT="${GITHUB_PAT:-}"

# Colors
readonly RED='\033[0;31m'
readonly GREEN='\033[0;32m'
readonly YELLOW='\033[1;33m'
readonly CYAN='\033[0;36m'
readonly BOLD='\033[1m'
readonly NC='\033[0m'

# ---------------------------------------------------------------------------
# Logging
# ---------------------------------------------------------------------------
log_info()  { echo -e "${GREEN}[INFO]${NC} $*" >&2; }
log_warn()  { echo -e "${YELLOW}[WARN]${NC} $*" >&2; }
log_error() { echo -e "${RED}[ERROR]${NC} $*" >&2; }

die() { log_error "$*"; exit 1; }

# ---------------------------------------------------------------------------
# Guards
# ---------------------------------------------------------------------------
require_root() {
    if [[ "${EUID:-$(id -u)}" -ne 0 ]]; then
        die "Please run as root: sudo bash install.sh"
    fi
}

detect_distro() {
    if [[ -f /etc/os-release ]]; then
        # shellcheck source=/dev/null
        . /etc/os-release
        DISTRO_ID="${ID:-unknown}"
        DISTRO_VERSION="${VERSION_ID:-unknown}"
    else
        die "Cannot detect Linux distribution."
    fi

    case "${DISTRO_ID}" in
        ubuntu)
            case "${DISTRO_VERSION}" in
                20.04|22.04|24.04) ;;
                *) log_warn "Untested Ubuntu version ${DISTRO_VERSION}; continuing anyway." ;;
            esac
            ;;
        debian)
            case "${DISTRO_VERSION}" in
                11|12) ;;
                *) log_warn "Untested Debian version ${DISTRO_VERSION}; continuing anyway." ;;
            esac
            ;;
        *)
            die "Unsupported distribution: ${DISTRO_ID}. Use Ubuntu 20.04+ or Debian 11/12."
            ;;
    esac
    log_info "Detected ${DISTRO_ID} ${DISTRO_VERSION}"
}

rand_alnum() {
    local length="${1:-16}"
    openssl rand -base64 48 | tr -dc 'a-zA-Z0-9' | head -c "${length}"
}

rand_hex() {
    local bytes="${1:-32}"
    openssl rand -hex "${bytes}"
}

prompt_with_validation() {
    local prompt="$1"
    local var_name="$2"
    local regex="$3"
    local default="${4:-}"
    local secret="${5:-false}"
    local value=""

    while true; do
        if [[ -n "${default}" ]]; then
            if [[ "${secret}" == "true" ]]; then
                read -rsp "${prompt} [default: ****]: " value
                echo ""
            else
                read -rp "${prompt} [default: ${default}]: " value
            fi
        else
            if [[ "${secret}" == "true" ]]; then
                read -rsp "${prompt}: " value
                echo ""
            else
                read -rp "${prompt}: " value
            fi
        fi

        [[ -z "${value}" && -n "${default}" ]] && value="${default}"

        if [[ -z "${value}" ]]; then
            log_warn "Value cannot be empty."
            continue
        fi

        if [[ -n "${regex}" ]] && ! [[ "${value}" =~ ${regex} ]]; then
            log_warn "Invalid format. Please try again."
            continue
        fi

        printf -v "${var_name}" '%s' "${value}"
        return 0
    done
}

prompt_yes_no() {
    local prompt="$1"
    local default="${2:-n}"
    local answer=""
    local hint="y/N"
    [[ "${default}" == "y" ]] && hint="Y/n"

    read -rp "${prompt} [${hint}]: " answer
    answer="${answer:-${default}}"
    [[ "${answer}" =~ ^[Yy] ]]
}

save_state() {
    mkdir -p "${STATE_DIR}"
    cat > "${STATE_FILE}" <<EOF
DOMAIN=${DOMAIN}
INSTALL_DIR=${INSTALL_DIR}
DB_NAME=${DB_NAME}
DB_USER=${DB_USER}
INSTALLED_AT=$(date -u +%Y-%m-%dT%H:%M:%SZ)
SCRIPT_VERSION=${SCRIPT_VERSION}
EOF
    chmod 600 "${STATE_FILE}"
}

load_state() {
    if [[ -f "${STATE_FILE}" ]]; then
        # shellcheck source=/dev/null
        source "${STATE_FILE}"
    fi
}

# ---------------------------------------------------------------------------
# GitHub clone URL (public repo or PAT / gh CLI for private)
# ---------------------------------------------------------------------------
get_git_clone_url() {
    if [[ -n "${GITHUB_PAT:-}" ]]; then
        echo "https://x-access-token:${GITHUB_PAT}@github.com/${REPO_SLUG}.git"
        return 0
    fi

    if command -v gh >/dev/null 2>&1 && gh auth status >/dev/null 2>&1; then
        local gh_token
        gh_token="$(gh auth token 2>/dev/null || true)"
        if [[ -n "${gh_token}" ]]; then
            echo "https://x-access-token:${gh_token}@github.com/${REPO_SLUG}.git"
            return 0
        fi
    fi

    echo "https://github.com/${REPO_SLUG}.git"
}

resolve_github_auth() {
    if [[ -n "${GITHUB_PAT:-}" ]]; then
        return 0
    fi
    if command -v gh >/dev/null 2>&1 && gh auth status >/dev/null 2>&1; then
        return 0
    fi
    if [[ "${BOLD_REQUIRE_PAT:-}" == "1" ]]; then
        echo ""
        log_info "Private repository: set GITHUB_PAT or run 'gh auth login', or make the repo public."
        prompt_with_validation "GitHub PAT" GITHUB_PAT '^gh[pousr]_[A-Za-z0-9_]+$' "" true
    fi
    return 0
}

git_clone_or_update() {
    local target="$1"
    resolve_github_auth

    local clone_url
    clone_url="$(get_git_clone_url)"

    if [[ "${clone_url}" == *"@github.com/"* ]]; then
        log_info "Cloning with authenticated GitHub URL."
    else
        log_info "No GitHub token — cloning public repository (${REPO_SLUG})."
    fi

    if [[ -d "${target}/.git" ]]; then
        log_info "Updating repository in ${target}..."
        git -C "${target}" fetch origin "${REPO_BRANCH}" --quiet
        git -C "${target}" reset --hard "origin/${REPO_BRANCH}" --quiet
    elif [[ -d "${target}" && -n "$(ls -A "${target}" 2>/dev/null)" ]]; then
        if prompt_yes_no "Directory ${target} exists but is not a git repo. Remove and clone?" "n"; then
            rm -rf "${target}"
            git clone --branch "${REPO_BRANCH}" --depth 1 "${clone_url}" "${target}"
        else
            die "Cannot install into non-empty directory without git."
        fi
    else
        mkdir -p "$(dirname "${target}")"
        log_info "Cloning ${REPO_SLUG}..."
        git clone --branch "${REPO_BRANCH}" --depth 1 "${clone_url}" "${target}"
    fi

    chown -R www-data:www-data "${target}"
    chmod -R 755 "${target}"
}

# ---------------------------------------------------------------------------
# Package installation
# ---------------------------------------------------------------------------
idempotent_apt_install() {
    export DEBIAN_FRONTEND=noninteractive
    apt-get update -qq
    apt-get install -y -qq "$@"
}

install_system_packages() {
    log_info "Installing system packages..."
    idempotent_apt_install \
        apache2 \
        mysql-server \
        php \
        php-mysql \
        php-curl \
        php-mbstring \
        php-xml \
        php-zip \
        php-cli \
        libapache2-mod-php \
        certbot \
        python3-certbot-apache \
        curl \
        git \
        unzip \
        openssl \
        ufw

    a2enmod rewrite ssl headers >/dev/null 2>&1 || true
    systemctl enable apache2 mysql >/dev/null 2>&1 || true
    systemctl start apache2 mysql >/dev/null 2>&1 || true

    ufw allow 80/tcp >/dev/null 2>&1 || true
    ufw allow 443/tcp >/dev/null 2>&1 || true
}

# ---------------------------------------------------------------------------
# MySQL
# ---------------------------------------------------------------------------
mysql_exec() {
    local sql="$1"
    if mysql -u root ${MYSQL_ROOT_PASS:+-p"${MYSQL_ROOT_PASS}"} -e "SELECT 1" >/dev/null 2>&1; then
        mysql -u root ${MYSQL_ROOT_PASS:+-p"${MYSQL_ROOT_PASS}"} -e "${sql}"
    elif mysql -u root -e "SELECT 1" >/dev/null 2>&1; then
        mysql -u root -e "${sql}"
    else
        die "Cannot connect to MySQL. Provide root password when prompted."
    fi
}

setup_mysql() {
    log_info "Setting up MySQL database..."

    if ! mysql -u root -e "SELECT 1" >/dev/null 2>&1; then
        if [[ -z "${MYSQL_ROOT_PASS:-}" ]]; then
            prompt_with_validation "MySQL root password" MYSQL_ROOT_PASS '.' '' true
        fi
    fi

    mysql_exec "CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
    mysql_exec "CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';"
    mysql_exec "GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';"
    mysql_exec "FLUSH PRIVILEGES;"
    log_info "Database ${DB_NAME} ready."
}

# ---------------------------------------------------------------------------
# Configuration file
# ---------------------------------------------------------------------------
write_config_php() {
    local config_file="${INSTALL_DIR}/config.php"
    local example_file="${INSTALL_DIR}/config.example.php"

    [[ -f "${example_file}" ]] || die "config.example.php not found in ${INSTALL_DIR}"

    if [[ -f "${config_file}" ]] && prompt_yes_no "config.php already exists. Overwrite?" "n"; then
        cp "${config_file}" "${config_file}.bak.$(date +%s)"
    elif [[ -f "${config_file}" ]]; then
        log_info "Keeping existing config.php"
        return 0
    fi

    local self_signed="false"
    [[ "${ALLOW_SELF_SIGNED}" == "true" ]] && self_signed="true"

    cat > "${config_file}" <<EOF
<?php
\$request_exec_timeout = null;

\$dbhost      = 'localhost';
\$dbname      = '${DB_NAME}';
\$usernamedb  = '${DB_USER}';
\$passworddb  = '${DB_PASS}';

\$APIKEY      = '${BOT_TOKEN}';
\$adminnumber = '${ADMIN_CHAT_ID}';
\$domainhosts = '${DOMAIN}';
\$usernamebot = '${BOT_USERNAME}';

\$allow_self_signed_certs = ${self_signed};
\$telegram_webhook_secret = '${TELEGRAM_WEBHOOK_SECRET}';
\$payment_webhook_key     = '${PAYMENT_WEBHOOK_KEY}';

\$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

\$dsn = "mysql:host={\$dbhost};dbname={\$dbname};charset=utf8mb4";
\$pdo = null;

try {
    \$pdo = new PDO(\$dsn, \$usernamedb, \$passworddb, \$options);
} catch (\\PDOException \$e) {
    error_log('Database connection failed: ' . \$e->getMessage());
}

if (\$pdo === null) {
    if (PHP_SAPI !== 'cli') {
        http_response_code(200);
        echo json_encode(['ok' => false, 'description' => 'Service temporarily unavailable']);
        exit;
    }
}
EOF

    chown www-data:www-data "${config_file}"
    chmod 640 "${config_file}"
    log_info "Wrote ${config_file}"
}

# ---------------------------------------------------------------------------
# Apache + SSL
# ---------------------------------------------------------------------------
setup_apache_vhost() {
    log_info "Configuring Apache for ${DOMAIN}..."

    local vhost_http="/etc/apache2/sites-available/bold-connection.conf"
    local vhost_ssl="/etc/apache2/sites-available/bold-connection-ssl.conf"

    cat > "${vhost_http}" <<EOF
<VirtualHost *:80>
    ServerName ${DOMAIN}
    DocumentRoot ${INSTALL_DIR}

    <Directory ${INSTALL_DIR}>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog \${APACHE_LOG_DIR}/bold-connection-error.log
    CustomLog \${APACHE_LOG_DIR}/bold-connection-access.log combined
</VirtualHost>
EOF

    a2dissite 000-default.conf >/dev/null 2>&1 || true
    a2ensite bold-connection.conf >/dev/null 2>&1 || true
    systemctl reload apache2

    if [[ ! -f "/etc/letsencrypt/live/${DOMAIN}/fullchain.pem" ]]; then
        log_info "Obtaining SSL certificate via Certbot..."
        certbot --apache --non-interactive --agree-tos --redirect \
            -m "admin@${DOMAIN}" -d "${DOMAIN}" || {
            log_warn "Certbot failed. Ensure DNS points to this server and port 80 is open."
            log_warn "Continuing with HTTP-only — Telegram webhooks require HTTPS!"
        }
    else
        log_info "SSL certificate already exists for ${DOMAIN}"
    fi

    if [[ -f "/etc/letsencrypt/live/${DOMAIN}/fullchain.pem" ]]; then
        cat > "${vhost_ssl}" <<EOF
<VirtualHost *:443>
    ServerName ${DOMAIN}
    DocumentRoot ${INSTALL_DIR}

    SSLEngine on
    SSLCertificateFile /etc/letsencrypt/live/${DOMAIN}/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/${DOMAIN}/privkey.pem

    <Directory ${INSTALL_DIR}>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog \${APACHE_LOG_DIR}/bold-connection-error.log
    CustomLog \${APACHE_LOG_DIR}/bold-connection-access.log combined
</VirtualHost>
EOF
        a2ensite bold-connection-ssl.conf >/dev/null 2>&1 || true
    fi

    systemctl reload apache2
}

# ---------------------------------------------------------------------------
# Telegram webhook
# ---------------------------------------------------------------------------
register_telegram_webhook() {
    log_info "Registering Telegram webhook..."
    local response
    response=$(curl -sS "https://api.telegram.org/bot${BOT_TOKEN}/setWebhook" \
        -d "url=https://${DOMAIN}/index.php" \
        -d "secret_token=${TELEGRAM_WEBHOOK_SECRET}")

    if echo "${response}" | grep -q '"ok":true'; then
        log_info "Webhook registered successfully."
    else
        log_warn "Webhook registration response: ${response}"
    fi
}

# ---------------------------------------------------------------------------
# Database schema
# ---------------------------------------------------------------------------
run_table_php() {
    log_info "Running database migrations (table.php)..."
    if [[ -f "${INSTALL_DIR}/table.php" ]]; then
        (cd "${INSTALL_DIR}" && php table.php) && return 0
    fi
    curl -sS -k --max-time 120 "https://${DOMAIN}/table.php" >/dev/null 2>&1 \
        || curl -sS --max-time 120 "http://${DOMAIN}/table.php" >/dev/null 2>&1 \
        || log_warn "table.php failed — run: cd ${INSTALL_DIR} && php table.php"
}

# ---------------------------------------------------------------------------
# Cron jobs
# ---------------------------------------------------------------------------
setup_cron() {
    log_info "Registering cron jobs..."
    local tmp
    tmp="$(mktemp)"
    crontab -l 2>/dev/null | grep -v "${DOMAIN}/cronbot/" > "${tmp}" || true

    local jobs=(
        "*/15 * * * * curl -s https://${DOMAIN}/cronbot/statusday.php >/dev/null"
        "*/1 * * * * curl -s https://${DOMAIN}/cronbot/croncard.php >/dev/null"
        "*/1 * * * * curl -s https://${DOMAIN}/cronbot/NoticationsService.php >/dev/null"
        "*/5 * * * * curl -s https://${DOMAIN}/cronbot/payment_expire.php >/dev/null"
        "*/1 * * * * curl -s https://${DOMAIN}/cronbot/sendmessage.php >/dev/null"
        "*/3 * * * * curl -s https://${DOMAIN}/cronbot/plisio.php >/dev/null"
        "*/1 * * * * curl -s https://${DOMAIN}/cronbot/activeconfig.php >/dev/null"
        "*/1 * * * * curl -s https://${DOMAIN}/cronbot/disableconfig.php >/dev/null"
        "*/1 * * * * curl -s https://${DOMAIN}/cronbot/iranpay1.php >/dev/null"
        "0 */5 * * * curl -s https://${DOMAIN}/cronbot/backupbot.php >/dev/null"
        "*/2 * * * * curl -s https://${DOMAIN}/cronbot/gift.php >/dev/null"
        "*/30 * * * * curl -s https://${DOMAIN}/cronbot/expireagent.php >/dev/null"
        "*/15 * * * * curl -s https://${DOMAIN}/cronbot/on_hold.php >/dev/null"
        "*/2 * * * * curl -s https://${DOMAIN}/cronbot/configtest.php >/dev/null"
        "*/15 * * * * curl -s https://${DOMAIN}/cronbot/uptime_node.php >/dev/null"
        "*/15 * * * * curl -s https://${DOMAIN}/cronbot/uptime_panel.php >/dev/null"
    )

    for job in "${jobs[@]}"; do
        echo "${job}" >> "${tmp}"
    done

    crontab "${tmp}"
    rm -f "${tmp}"
    log_info "Cron jobs registered."
}

# ---------------------------------------------------------------------------
# Health checks
# ---------------------------------------------------------------------------
run_health_checks() {
    log_info "Running health checks..."

    local webhook_info
    webhook_info=$(curl -sS "https://api.telegram.org/bot${BOT_TOKEN}/getWebhookInfo" 2>/dev/null || echo '{}')
    echo "${webhook_info}" | grep -q '"url"' && log_info "Webhook URL configured." || log_warn "Webhook may not be configured."

    local http_code
    http_code=$(curl -sS -o /dev/null -w '%{http_code}' "https://${DOMAIN}/index.php" 2>/dev/null || echo "000")
    if [[ "${http_code}" == "200" || "${http_code}" == "403" ]]; then
        log_info "index.php reachable (HTTP ${http_code})."
    else
        log_warn "index.php returned HTTP ${http_code} — check Apache logs."
    fi
}

# ---------------------------------------------------------------------------
# Interactive prompts for install
# ---------------------------------------------------------------------------
collect_install_inputs() {
    echo ""
    echo -e "${BOLD}${CYAN}=== Bold Connection Install ===${NC}"
    echo ""

    if [[ -n "${BOLD_DOMAIN:-}" ]]; then
        DOMAIN="${BOLD_DOMAIN}"
        log_info "Using domain from BOLD_DOMAIN: ${DOMAIN}"
    else
        prompt_with_validation "Domain name (e.g. bot.example.com)" DOMAIN '^[a-zA-Z0-9]([a-zA-Z0-9.-]*[a-zA-Z0-9])?$'
    fi

    prompt_with_validation "Telegram Bot Token" BOT_TOKEN '^[0-9]{8,10}:[a-zA-Z0-9_-]{35}$' "" true
    prompt_with_validation "Admin Chat ID" ADMIN_CHAT_ID '^-?[0-9]+$'
    prompt_with_validation "Bot username (without @)" BOT_USERNAME '^[a-zA-Z0-9_]{3,}$'

    prompt_with_validation "Install directory" INSTALL_DIR '^/' "${DEFAULT_INSTALL_DIR}"

    DB_NAME="bold_connection"
    prompt_with_validation "Database name" DB_NAME '^[a-zA-Z0-9_]+$' "${DB_NAME}"

    DB_USER="$(rand_alnum 8)"
    prompt_with_validation "Database username" DB_USER '^[a-zA-Z0-9_]+$' "${DB_USER}"

    DB_PASS="$(rand_alnum 16)"
    prompt_with_validation "Database password (min 8 chars)" DB_PASS '^.{8,}$' "${DB_PASS}" true

    TELEGRAM_WEBHOOK_SECRET="$(rand_hex 32)"
    log_info "Generated Telegram webhook secret: ${TELEGRAM_WEBHOOK_SECRET:0:8}..."
    if ! prompt_yes_no "Use this auto-generated Telegram webhook secret?" "y"; then
        prompt_with_validation "Telegram webhook secret" TELEGRAM_WEBHOOK_SECRET '^[a-fA-F0-9]{16,}$'
    fi

    PAYMENT_WEBHOOK_KEY="$(rand_hex 32)"
    log_info "Generated payment webhook key: ${PAYMENT_WEBHOOK_KEY:0:8}..."
    if ! prompt_yes_no "Use this auto-generated payment webhook key?" "y"; then
        prompt_with_validation "Payment webhook key" PAYMENT_WEBHOOK_KEY '^[a-fA-F0-9]{16,}$'
    fi

    if prompt_yes_no "Allow self-signed TLS certificates for panel API calls?" "n"; then
        ALLOW_SELF_SIGNED="true"
    fi
}

# ---------------------------------------------------------------------------
# Main operations
# ---------------------------------------------------------------------------
do_install() {
    collect_install_inputs
    install_system_packages
    git_clone_or_update "${INSTALL_DIR}"
    setup_mysql
    write_config_php
    setup_apache_vhost
    register_telegram_webhook
    run_table_php
    setup_cron
    save_state
    run_health_checks

    echo ""
    echo -e "${GREEN}${BOLD}Installation complete!${NC}"
    echo ""
    echo -e "  Bot URL:    ${CYAN}https://${DOMAIN}${NC}"
    echo -e "  Admin ID:   ${ADMIN_CHAT_ID}"
    echo -e "  DB name:    ${DB_NAME}"
    echo -e "  DB user:    ${DB_USER}"
    echo -e "  DB pass:    ${DB_PASS}"
    echo ""
    echo -e "  Send ${BOLD}/start${NC} to @${BOT_USERNAME} in Telegram."
    echo -e "  Panel webhook: ${CYAN}https://${DOMAIN}/webhooks.php${NC}"
    echo -e "  X-Webhook-Secret (base64): $(echo -n "${PAYMENT_WEBHOOK_KEY}" | base64)"
    echo ""
}

do_update() {
    load_state
    [[ -n "${INSTALL_DIR:-}" && -d "${INSTALL_DIR}" ]] || die "No installation found. Run Install first."

    if [[ -f "${INSTALL_DIR}/config.php" ]]; then
        DOMAIN=$(grep '^\$domainhosts' "${INSTALL_DIR}/config.php" | cut -d"'" -f2)
        BOT_TOKEN=$(grep '^\$APIKEY' "${INSTALL_DIR}/config.php" | cut -d"'" -f2)
        TELEGRAM_WEBHOOK_SECRET=$(grep '^\$telegram_webhook_secret' "${INSTALL_DIR}/config.php" | cut -d"'" -f2)
    fi

    git_clone_or_update "${INSTALL_DIR}"
    run_table_php
    register_telegram_webhook
    setup_cron
    run_health_checks
    log_info "Update complete."
}

do_repair() {
    load_state
    [[ -f "${INSTALL_DIR}/config.php" ]] || die "config.php not found at ${INSTALL_DIR:-unknown}"

    DOMAIN=$(grep '^\$domainhosts' "${INSTALL_DIR}/config.php" | cut -d"'" -f2)
    BOT_TOKEN=$(grep '^\$APIKEY' "${INSTALL_DIR}/config.php" | cut -d"'" -f2)
    TELEGRAM_WEBHOOK_SECRET=$(grep '^\$telegram_webhook_secret' "${INSTALL_DIR}/config.php" | cut -d"'" -f2)

    setup_apache_vhost
    register_telegram_webhook
    run_table_php
    setup_cron
    run_health_checks
    log_info "Repair complete."
}

do_remove() {
    load_state

    if prompt_yes_no "Remove cron jobs for Bold Connection?" "y"; then
        local tmp
        tmp="$(mktemp)"
        crontab -l 2>/dev/null | grep -v 'cronbot/' > "${tmp}" || true
        crontab "${tmp}"
        rm -f "${tmp}"
        log_info "Cron jobs removed."
    fi

    a2dissite bold-connection.conf bold-connection-ssl.conf >/dev/null 2>&1 || true
    systemctl reload apache2 >/dev/null 2>&1 || true

    if [[ -n "${INSTALL_DIR:-}" ]] && prompt_yes_no "Delete install directory ${INSTALL_DIR}?" "n"; then
        rm -rf "${INSTALL_DIR}"
        log_info "Install directory removed."
    fi

    if [[ -n "${DB_NAME:-}" ]] && prompt_yes_no "Drop database ${DB_NAME}?" "n"; then
        mysql_exec "DROP DATABASE IF EXISTS \`${DB_NAME}\`;" 2>/dev/null || true
        log_info "Database dropped."
    fi

    rm -f "${STATE_FILE}"
    log_info "Removal complete."
}

do_status() {
    load_state
    echo ""
    echo -e "${BOLD}Bold Connection Status${NC} (installer v${SCRIPT_VERSION})"
    echo ""

    if [[ -f "${STATE_FILE}" ]]; then
        echo -e "  State file: ${GREEN}found${NC}"
        cat "${STATE_FILE}" | sed 's/^/  /'
    else
        echo -e "  State file: ${YELLOW}not found${NC}"
    fi

    if [[ -n "${DOMAIN:-}" && -f "/etc/letsencrypt/live/${DOMAIN}/fullchain.pem" ]]; then
        local expiry
        expiry=$(openssl x509 -enddate -noout -in "/etc/letsencrypt/live/${DOMAIN}/fullchain.pem" | cut -d= -f2)
        echo -e "  SSL expiry: ${expiry}"
    fi

    if [[ -f "${INSTALL_DIR:-/dev/null}/config.php" ]]; then
        echo -e "  config.php: ${GREEN}present${NC}"
    fi

    echo ""
}

show_banner() {
    clear
    echo -e "${CYAN}${BOLD}"
    cat <<'BANNER'
 ____  _     _     ____   _   _  _____ ____ ___ ____ _____ ____
| __ )| |   | |   / ___| | \ | |/ ____/ ___|_ _|  _ \_   _/ ___|
|  _ \| |   | |   \___ \ |  \| | |   \___ \| || | | || | \___ \
| |_) | |___| |___ ___) || |\  | |___ ___) | || |_| || |  ___) |
|____/|_____|_____|____/ |_| \_|\____|____/___|____/|___| |____/
BANNER
    echo -e "${NC}"
    echo -e "  Bold Connection Installer v${SCRIPT_VERSION}"
    echo -e "  Repository: ${REPO_SLUG} (${REPO_BRANCH})"
    echo ""
}

show_menu() {
    show_banner
    echo "  1) Install Bold Connection"
    echo "  2) Update (pull latest + migrate)"
    echo "  3) Repair (webhook, cron, Apache, table.php)"
    echo "  4) Remove"
    echo "  5) Status"
    echo "  6) Exit"
    echo ""
    read -rp "Select option [1-6]: " choice
    echo ""

    case "${choice}" in
        1) do_install ;;
        2) do_update ;;
        3) do_repair ;;
        4) do_remove ;;
        5) do_status ;;
        6) exit 0 ;;
        *) log_warn "Invalid option."; show_menu ;;
    esac
}

# ---------------------------------------------------------------------------
# Entry
# ---------------------------------------------------------------------------
main() {
    require_root
    detect_distro
    mkdir -p "${STATE_DIR}"
    show_menu
}

main "$@"
